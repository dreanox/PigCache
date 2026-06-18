<?php
/**
 * URL firewall — 404 instantáneo para URLs inexistentes (anti-scraper).
 *
 * Mantiene en Redis un SET con el md5() de cada permalink válido del sitio
 * (posts, páginas y archivos de términos públicos). En el arranque temprano
 * (serve_early(), antes de bootear WordPress) comprueba si la URL pedida
 * "parece contenido" y NO está en el set; si es así, en modo `enforce` devuelve
 * un 404 sin tocar la base de datos, y en modo `log` solo lo registra.
 *
 * Seguro por construcción:
 *   - Un permalink real SIEMPRE está en el set → nunca se 404ea contenido real.
 *   - Fail-open: si Redis falla o el set no está "ready" (sembrado), no filtra.
 *   - Patrones de bypass para home, archivos, feeds, sitemaps, paginación,
 *     archivos por fecha, /author/, wp-admin, etc.
 *
 * Activación por wp-config.php (por sitio):
 *   define('PIGCACHE_URL_FIREWALL', true);             // master on/off
 *   define('PIGCACHE_URL_FIREWALL_MODE', 'log');       // 'log' | 'enforce'
 *   define('PIGCACHE_FW_REDIS_DATABASE', 0);           // DB Redis del firewall
 *
 * Comandos:
 *   wp pigcache-fw rebuild   → siembra el set desde posts/páginas/términos
 *   wp pigcache-fw report    → top URLs/IPs/UAs bloqueados (patrones de ataque)
 *   wp pigcache-fw status    → estado y tamaño del set
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Url_Firewall {

	const PREFIX     = 'pigcache:fw:';
	const RECENT_MAX = 5000;
	const INSTR_TTL  = 86400; // 1 día de instrumentación.

	// ── Configuración ───────────────────────────────────────────────────────

	public static function enabled(): bool {
		return defined( 'PIGCACHE_URL_FIREWALL' ) && PIGCACHE_URL_FIREWALL;
	}

	public static function mode(): string {
		$m = defined( 'PIGCACHE_URL_FIREWALL_MODE' )
			? strtolower( (string) PIGCACHE_URL_FIREWALL_MODE )
			: 'log';
		return 'enforce' === $m ? 'enforce' : 'log';
	}

	// ── Entrada temprana (llamada desde serve_early, antes de bootear WP) ────

	/**
	 * Comprueba la petición actual y, en modo enforce, corta con 404 + exit.
	 * No usa funciones de WordPress: lee $_SERVER/$_COOKIE y Redis directo.
	 */
	public static function maybe_block_early(): void {
		if ( ! self::enabled() ) {
			return;
		}

		// Solo GET sin cuerpo POST.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method || ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( self::has_session_cookie() ) {
			return;
		}

		$host = self::host_early();
		if ( '' === $host ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = self::canonical_path( $uri );
		if ( self::is_bypassed( $path ) ) {
			return;
		}

		$redis = self::connect();
		if ( ! $redis ) {
			return; // fail-open
		}

		try {
			// Fail-open hasta que el set esté sembrado.
			if ( ! $redis->exists( self::ready_key( $host ) ) ) {
				return;
			}
			$member = $redis->sIsMember( self::set_key( $host ), md5( $path ) );
			if ( $member ) {
				return; // URL válida
			}

			self::record( $redis, $host, $path );
		} catch ( \Throwable $e ) {
			return; // cualquier error → fail-open
		}

		if ( 'enforce' === self::mode() ) {
			self::send_404_and_exit();
		}
		// modo log: dejar pasar a WordPress.
	}

	private static function send_404_and_exit(): void {
		if ( ! headers_sent() ) {
			http_response_code( 404 );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-PigCache-FW: block' );
			header( 'Cache-Control: no-store' );
		}
		echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>404</title></head>"
			. "<body><h1>404</h1><p>Not found.</p></body></html>";
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		exit;
	}

	// ── Instrumentación (patrones de ataque) ─────────────────────────────────

	private static function record( $redis, string $host, string $path ): void {
		$ip  = self::client_ip();
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 300 ) : '';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? substr( (string) $_SERVER['HTTP_REFERER'], 0, 300 ) : '';

		$entry = wp_json_encode_safe( array(
			't'    => time(),
			'path' => $path,
			'ip'   => $ip,
			'ua'   => $ua,
			'ref'  => $ref,
			'mode' => self::mode(),
		) );

		try {
			$rk = self::recent_key( $host );
			$redis->lPush( $rk, $entry );
			$redis->lTrim( $rk, 0, self::RECENT_MAX - 1 );
			$redis->expire( $rk, self::INSTR_TTL );

			self::zbump( $redis, self::by_path_key( $host ), $path );
			self::zbump( $redis, self::by_ip_key( $host ), $ip );
			self::zbump( $redis, self::by_ua_key( $host ), $ua );
		} catch ( \Throwable $e ) {
			// best-effort
		}
	}

	private static function zbump( $redis, string $key, string $member ): void {
		if ( '' === $member ) {
			return;
		}
		$redis->zIncrBy( $key, 1, $member );
		$redis->expire( $key, self::INSTR_TTL );
	}

	// ── Mantenimiento del set (hooks de WordPress) ───────────────────────────

	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 20, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete_post' ), 20, 1 );

		add_action( 'created_term', array( __CLASS__, 'on_term' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 20, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_delete' ), 20, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'pigcache-fw', 'PigCache_Url_Firewall_CLI' );
		}
	}

	public static function on_save_post( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$path = self::permalink_path_for_post( $post_id );
		if ( '' === $path ) {
			return;
		}
		if ( self::is_viewable_post( $post ) ) {
			self::add_path( $path );
		} else {
			self::remove_path( $path );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post ) {
			self::on_save_post( $post->ID );
		}
	}

	public static function on_delete_post( $post_id ): void {
		$path = self::permalink_path_for_post( (int) $post_id );
		if ( '' !== $path ) {
			self::remove_path( $path );
		}
	}

	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( is_string( $link ) ) {
			self::add_path( self::path_from_url( $link ) );
		}
	}

	public static function on_term_delete( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		// El término ya no existe; get_term_link no sirve aquí. El set se limpia
		// del todo en el próximo rebuild. (Las llaves huérfanas son inocuas.)
	}

	private static function add_path( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		$redis = self::connect();
		if ( ! $redis ) {
			return;
		}
		try {
			$redis->sAdd( self::set_key( self::host_late() ), md5( $path ) );
		} catch ( \Throwable $e ) {
		}
	}

	private static function remove_path( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		$redis = self::connect();
		if ( ! $redis ) {
			return;
		}
		try {
			$redis->sRem( self::set_key( self::host_late() ), md5( $path ) );
		} catch ( \Throwable $e ) {
		}
	}

	// ── Siembra / inspección (usadas por WP-CLI) ─────────────────────────────

	/**
	 * Reconstruye el set desde cero: posts, páginas y términos públicos.
	 *
	 * @param callable|null $progress  Recibe mensajes de progreso (CLI).
	 * @return array{added:int,host:string}|null  null si Redis no responde.
	 */
	public static function rebuild( $progress = null ) {
		$redis = self::connect();
		if ( ! $redis ) {
			return null;
		}
		$host = self::host_late();
		$set  = self::set_key( $host );

		// Set temporal para hacer swap atómico al final.
		$tmp = $set . ':build';
		try {
			$redis->del( $tmp );
		} catch ( \Throwable $e ) {
		}

		$added = 0;
		$buf   = array();

		$flush = static function () use ( &$buf, $redis, $tmp, &$added ) {
			if ( empty( $buf ) ) {
				return;
			}
			$args = array_merge( array( $tmp ), $buf );
			try {
				call_user_func_array( array( $redis, 'sAdd' ), $args );
			} catch ( \Throwable $e ) {
			}
			$added += count( $buf );
			$buf    = array();
		};

		// Posts + páginas (y cualquier post_type público).
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		$paged = 1;
		do {
			$ids = get_posts( array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'paged'            => $paged,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			) );
			foreach ( $ids as $id ) {
				$p = self::permalink_path_for_post( (int) $id );
				if ( '' !== $p ) {
					$buf[] = md5( $p );
					if ( count( $buf ) >= 1000 ) {
						$flush();
					}
				}
			}
			if ( $progress && ! empty( $ids ) ) {
				$progress( 'posts pág ' . $paged . ' (+' . count( $ids ) . ')' );
			}
			$paged++;
		} while ( ! empty( $ids ) );

		// Términos públicos (categorías, tags, taxonomías custom públicas).
		$taxes = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxes as $tax ) {
			$offset = 0;
			do {
				$terms = get_terms( array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 1000,
					'offset'     => $offset,
					'fields'     => 'ids',
				) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					break;
				}
				foreach ( $terms as $tid ) {
					$link = get_term_link( (int) $tid, $tax );
					if ( is_string( $link ) ) {
						$p = self::path_from_url( $link );
						if ( '' !== $p ) {
							$buf[] = md5( $p );
							if ( count( $buf ) >= 1000 ) {
								$flush();
							}
						}
					}
				}
				if ( $progress ) {
					$progress( 'términos ' . $tax . ' +' . count( $terms ) );
				}
				$offset += 1000;
			} while ( count( $terms ) === 1000 );
		}

		$flush();

		// Swap atómico: renombra tmp → set, marca ready.
		try {
			if ( $added > 0 ) {
				$redis->rename( $tmp, $set );
			} else {
				// Sin URLs: deja el set como esté y no marques ready (evita 404 masivo).
				$redis->del( $tmp );
				return array( 'added' => 0, 'host' => $host );
			}
			$redis->set( self::ready_key( $host ), (string) time() );
		} catch ( \Throwable $e ) {
			return array( 'added' => $added, 'host' => $host );
		}

		return array( 'added' => $added, 'host' => $host );
	}

	/**
	 * Devuelve un reporte de instrumentación (top paths/IPs/UAs + recientes).
	 *
	 * @param int $top  Cuántos por ranking.
	 * @return array
	 */
	public static function report( int $top = 20 ): array {
		$out = array(
			'host'      => self::host_late(),
			'ready'     => false,
			'set_size'  => 0,
			'top_paths' => array(),
			'top_ips'   => array(),
			'top_uas'   => array(),
			'recent'    => array(),
		);
		$redis = self::connect();
		if ( ! $redis ) {
			return $out;
		}
		$host = $out['host'];
		try {
			$out['ready']    = (bool) $redis->exists( self::ready_key( $host ) );
			$out['set_size'] = (int) $redis->sCard( self::set_key( $host ) );
			$out['top_paths'] = self::ztop( $redis, self::by_path_key( $host ), $top );
			$out['top_ips']   = self::ztop( $redis, self::by_ip_key( $host ), $top );
			$out['top_uas']   = self::ztop( $redis, self::by_ua_key( $host ), $top );
			$recent           = $redis->lRange( self::recent_key( $host ), 0, 49 );
			if ( is_array( $recent ) ) {
				$out['recent'] = array_map( static function ( $j ) {
					$d = json_decode( (string) $j, true );
					return is_array( $d ) ? $d : array( 'raw' => $j );
				}, $recent );
			}
		} catch ( \Throwable $e ) {
		}
		return $out;
	}

	private static function ztop( $redis, string $key, int $n ): array {
		$res = $redis->zRevRange( $key, 0, $n - 1, true );
		$out = array();
		if ( is_array( $res ) ) {
			foreach ( $res as $member => $score ) {
				$out[] = array( 'key' => $member, 'count' => (int) $score );
			}
		}
		return $out;
	}

	// ── Helpers de URL / bypass ──────────────────────────────────────────────

	/**
	 * Normaliza un path: quita query/fragmento, colapsa //, quita /page/N y
	 * asegura barra final cuando no hay extensión.
	 */
	public static function canonical_path( string $uri ): string {
		$p = $uri;
		foreach ( array( '?', '#' ) as $c ) {
			$i = strpos( $p, $c );
			if ( false !== $i ) {
				$p = substr( $p, 0, $i );
			}
		}
		// Decodificar %XX para comparar de forma consistente: REQUEST_URI llega
		// percent-encoded (p. ej. emoji o acentos: %F0%9F…), mientras que
		// get_permalink entrega el path como UTF-8 crudo. Sin esto, los slugs no
		// ASCII generarían un md5 distinto en cada lado → 404 a contenido real.
		// Quitamos primero bytes de control que rawurldecode pudiera introducir
		// (p. ej. %00, %0A) para no envenenar el path.
		$dec = rawurldecode( $p );
		$dec = preg_replace( '/[\x00-\x1F\x7F]/', '', $dec );
		if ( null !== $dec && '' !== $dec ) {
			$p = $dec;
		}
		if ( '' === $p ) {
			$p = '/';
		}
		$p = preg_replace( '#/+#', '/', $p );
		$p = preg_replace( '#/page/[0-9]+/?$#', '/', $p );
		if ( '' === $p || '/' !== $p[0] ) {
			$p = '/' . $p;
		}
		if ( '/' !== substr( $p, -1 ) ) {
			$last = strrchr( $p, '/' );
			if ( false !== $last && false === strpos( $last, '.' ) ) {
				$p .= '/';
			}
		}
		return $p;
	}

	private static function path_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		return self::canonical_path( $path );
	}

	private static function permalink_path_for_post( int $post_id ): string {
		$link = get_permalink( $post_id );
		if ( ! is_string( $link ) || '' === $link ) {
			return '';
		}
		return self::path_from_url( $link );
	}

	/**
	 * Rutas que NUNCA se bloquean (se dejan pasar a WordPress).
	 */
	public static function is_bypassed( string $path ): bool {
		if ( '/' === $path ) {
			return true;
		}

		$prefixes = array(
			'/wp-admin', '/wp-login', '/wp-json', '/wp-content', '/wp-includes',
			'/wp-cron.php', '/xmlrpc.php', '/author/', '/.well-known/',
		);
		foreach ( $prefixes as $pre ) {
			if ( 0 === strpos( $path, $pre ) ) {
				return true;
			}
		}

		// Feeds.
		if ( preg_match( '#/feed/?$#', $path ) || false !== strpos( $path, '/feed/' ) ) {
			return true;
		}
		// Sitemaps.
		if ( false !== strpos( $path, 'sitemap' ) ) {
			return true;
		}
		// Cualquier path con extensión (archivos, .php de scanners → nginx/php los maneja).
		if ( preg_match( '#\.[a-z0-9]{1,6}$#i', $path ) ) {
			return true;
		}
		// Archivos por fecha: /2026/ o /2026/06/ etc.
		if ( preg_match( '#^/[0-9]{4}(/|$)#', $path ) ) {
			return true;
		}

		// Filtro extensible para casos propios.
		$bypass = apply_filters_safe( 'pigcache_fw_bypass', false, $path );
		return (bool) $bypass;
	}

	private static function is_viewable_post( $post ): bool {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		return is_post_type_viewable( $post->post_type );
	}

	private static function has_session_cookie(): bool {
		if ( empty( $_COOKIE ) ) {
			return false;
		}
		$prefixes = array( 'wordpress_logged_in_', 'comment_author_', 'wp-postpass_', 'woocommerce_items_in_cart' );
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			foreach ( $prefixes as $pre ) {
				if ( 0 === strncmp( $name, $pre, strlen( $pre ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return substr( (string) $_SERVER['HTTP_CF_CONNECTING_IP'], 0, 45 );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return (string) $_SERVER['REMOTE_ADDR'];
		}
		return '';
	}

	// ── Host / Redis ─────────────────────────────────────────────────────────

	private static function norm_host( string $h ): string {
		$h = strtolower( trim( $h ) );
		$i = strpos( $h, ':' );
		if ( false !== $i ) {
			$h = substr( $h, 0, $i );
		}
		if ( 0 === strncmp( $h, 'www.', 4 ) ) {
			$h = substr( $h, 4 );
		}
		return $h;
	}

	private static function host_early(): string {
		$h = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( '' === $h && isset( $_SERVER['SERVER_NAME'] ) ) {
			$h = (string) $_SERVER['SERVER_NAME'];
		}
		// Solo caracteres válidos de host (anti key-poisoning).
		if ( ! preg_match( '/^[a-z0-9.\-:]+$/i', $h ) ) {
			return '';
		}
		return self::norm_host( $h );
	}

	private static function host_late(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return self::norm_host( $host );
	}

	private static function set_key( string $h ): string {
		return self::PREFIX . 'urls:' . $h;
	}
	private static function ready_key( string $h ): string {
		return self::PREFIX . 'ready:' . $h;
	}
	private static function recent_key( string $h ): string {
		return self::PREFIX . 'recent:' . $h;
	}
	private static function by_path_key( string $h ): string {
		return self::PREFIX . 'by_path:' . $h;
	}
	private static function by_ip_key( string $h ): string {
		return self::PREFIX . 'by_ip:' . $h;
	}
	private static function by_ua_key( string $h ): string {
		return self::PREFIX . 'by_ua:' . $h;
	}

	/**
	 * Conexión phpredis propia (DB fija, independiente del object cache) para
	 * que el chequeo temprano y el mantenimiento usen el mismo espacio.
	 *
	 * @return \Redis|null
	 */
	private static function connect() {
		if ( ! class_exists( 'Redis' ) ) {
			return null;
		}
		$host = defined( 'PIGCACHE_REDIS_HOST' ) ? PIGCACHE_REDIS_HOST : '127.0.0.1';
		$port = defined( 'PIGCACHE_REDIS_PORT' ) ? (int) PIGCACHE_REDIS_PORT : 6379;
		$db   = defined( 'PIGCACHE_FW_REDIS_DATABASE' ) ? (int) PIGCACHE_FW_REDIS_DATABASE : 0;

		try {
			$r = new \Redis();
			if ( ! $r->connect( $host, $port, 0.2 ) ) {
				return null;
			}
			if ( defined( 'PIGCACHE_REDIS_PASSWORD' ) && PIGCACHE_REDIS_PASSWORD !== '' ) {
				$r->auth( PIGCACHE_REDIS_PASSWORD );
			}
			if ( $db > 0 ) {
				$r->select( $db );
			}
			return $r;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}

/**
 * json_encode seguro sin depender de WP en el early path.
 */
if ( ! function_exists( 'wp_json_encode_safe' ) ) {
	function wp_json_encode_safe( $data ) {
		$j = json_encode( $data );
		return false === $j ? '{}' : $j;
	}
}

/**
 * apply_filters si existe; si no (early path), devuelve el default.
 */
if ( ! function_exists( 'apply_filters_safe' ) ) {
	function apply_filters_safe( $tag, $value, $arg ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( $tag, $value, $arg );
		}
		return $value;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Comandos WP-CLI del URL firewall: `wp pigcache-fw <subcomando>`.
	 */
	class PigCache_Url_Firewall_CLI {

		/**
		 * Reconstruye el set de URLs válidas desde posts/páginas/términos.
		 *
		 * ## EXAMPLES
		 *     wp pigcache-fw rebuild
		 */
		public function rebuild( $args, $assoc ) {
			\WP_CLI::log( 'Reconstruyendo set de URLs…' );
			$res = PigCache_Url_Firewall::rebuild( static function ( $msg ) {
				\WP_CLI::log( '  ' . $msg );
			} );
			if ( null === $res ) {
				\WP_CLI::error( 'Redis no responde. Revisa PIGCACHE_REDIS_* y que redis esté arriba.' );
			}
			if ( 0 === $res['added'] ) {
				\WP_CLI::warning( 'No se agregaron URLs (¿sitio vacío?). El set NO quedó "ready" para evitar 404 masivos.' );
				return;
			}
			\WP_CLI::success( sprintf( 'Set listo para %s con %d URLs.', $res['host'], $res['added'] ) );
		}

		/**
		 * Muestra el estado del firewall.
		 *
		 * ## EXAMPLES
		 *     wp pigcache-fw status
		 */
		public function status( $args, $assoc ) {
			$rep = PigCache_Url_Firewall::report( 0 );
			\WP_CLI::log( 'Host:      ' . $rep['host'] );
			\WP_CLI::log( 'Activado:  ' . ( PigCache_Url_Firewall::enabled() ? 'sí' : 'no' ) );
			\WP_CLI::log( 'Modo:      ' . PigCache_Url_Firewall::mode() );
			\WP_CLI::log( 'Ready:     ' . ( $rep['ready'] ? 'sí' : 'no' ) );
			\WP_CLI::log( 'URLs:      ' . $rep['set_size'] );
		}

		/**
		 * Reporta los patrones bloqueados (top paths/IPs/UAs).
		 *
		 * ## OPTIONS
		 * [--top=<n>]
		 * : Cuántos por ranking. Default 20.
		 *
		 * ## EXAMPLES
		 *     wp pigcache-fw report --top=30
		 */
		public function report( $args, $assoc ) {
			$top = isset( $assoc['top'] ) ? max( 1, (int) $assoc['top'] ) : 20;
			$rep = PigCache_Url_Firewall::report( $top );

			\WP_CLI::log( sprintf( 'Host %s · ready=%s · URLs=%d · modo=%s',
				$rep['host'], $rep['ready'] ? 'sí' : 'no', $rep['set_size'], PigCache_Url_Firewall::mode() ) );

			$print = static function ( $title, $rows ) {
				\WP_CLI::log( "\n== $title ==" );
				if ( empty( $rows ) ) {
					\WP_CLI::log( '  (sin datos)' );
					return;
				}
				foreach ( $rows as $r ) {
					\WP_CLI::log( sprintf( '  %6d  %s', $r['count'], $r['key'] ) );
				}
			};
			$print( 'Top URLs bloqueadas', $rep['top_paths'] );
			$print( 'Top IPs', $rep['top_ips'] );
			$print( 'Top User-Agents', $rep['top_uas'] );
			\WP_CLI::log( sprintf( "\nRecientes en buffer: %d", count( $rep['recent'] ) ) );
		}
	}
}
