<?php
/**
 * URL firewall — 404 instantáneo para URLs que WordPress ya declaró inexistentes.
 *
 * MODELO: denylist aprendida, no allowlist.
 *
 * El firewall no decide qué existe. Observa lo que WordPress responde y repite
 * ese veredicto. Cuando una petición termina en un 404 real, se guarda en Redis
 * una llave `pigcache:fw:404:<host>:<md5(path)>` con TTL. En el arranque temprano
 * (serve_early(), antes de bootear WordPress) basta un EXISTS sobre esa llave para
 * devolver el 404 sin tocar MySQL ni cargar plugins.
 *
 * Por qué así y no con una lista blanca de permalinks:
 *   - Una allowlist es fail-closed: cualquier URL válida que falte de la lista se
 *     convierte en un 404 sobre contenido real. Eso obliga a reconstruir la lista
 *     periódicamente y deja el sitio a merced de esa reconstrucción (un rebuild a
 *     medias, o un desalojo del set por maxmemory, tumbaba el sitio entero).
 *   - Una denylist es fail-open: si falta una entrada, el peor caso es que un
 *     scraper reciba un 404 servido por WordPress en vez de por Redis. Contenido
 *     real nunca se bloquea, porque solo bloqueamos rutas que WordPress ya 404eó.
 *
 * Consecuencia práctica: no hay set que sembrar ni cron que reconstruya. El
 * firewall se puede dejar encendido de forma permanente.
 *
 * El primer hit de cada URL inexistente sí bootea WordPress; a partir de ahí se
 * sirve desde Redis durante PIGCACHE_FW_TTL segundos. Los ataques repiten rutas,
 * así que el coste se amortiza de inmediato.
 *
 * Activación por wp-config.php (por sitio):
 *   define('PIGCACHE_URL_FIREWALL', true);             // master on/off
 *   define('PIGCACHE_URL_FIREWALL_MODE', 'enforce');   // 'log' | 'enforce'
 *   define('PIGCACHE_FW_REDIS_DATABASE', 0);           // DB Redis del firewall
 *   define('PIGCACHE_FW_TTL', 604800);                 // vida de un 404 aprendido
 *
 * Comandos:
 *   wp pigcache-fw status    → estado y número de 404 aprendidos
 *   wp pigcache-fw report    → top URLs/IPs/UAs bloqueados (patrones de ataque)
 *   wp pigcache-fw purge     → olvida todo lo aprendido (tras cambios de permalinks)
 *
 * @package PigCache
 */

defined( 'ABSPATH' ) || exit;

class PigCache_Url_Firewall {

	const PREFIX     = 'pigcache:fw:';
	const RECENT_MAX = 5000;
	const INSTR_TTL  = 86400; // 1 día de instrumentación.

	/** TTL por defecto de un 404 aprendido: 7 días. */
	const DEFAULT_TTL = 604800;

	/** @var \Redis|false|null Conexión compartida; false = intento fallido. */
	private static $redis = null;

	/** @var bool Evita registrar dos veces el mismo request. */
	private static $observed = false;

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

	/**
	 * Cuánto vive un 404 aprendido. Acotado a [5 min, 30 días] para que una
	 * constante mal puesta no congele un veredicto para siempre.
	 */
	public static function ttl(): int {
		$ttl = defined( 'PIGCACHE_FW_TTL' ) ? (int) PIGCACHE_FW_TTL : self::DEFAULT_TTL;
		return max( 300, min( 2592000, $ttl ) );
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

		$path = self::eligible_request_path();
		if ( null === $path ) {
			return;
		}

		$host = self::host_early();
		if ( '' === $host ) {
			return;
		}

		$redis = self::connect();
		if ( ! $redis ) {
			return; // fail-open
		}

		try {
			if ( ! $redis->exists( self::deny_key( $host, $path ) ) ) {
				return; // desconocida → que decida WordPress
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

	/**
	 * Filtros comunes al camino temprano y al de observación: solo GET anónimos
	 * sobre rutas que tengan pinta de contenido.
	 *
	 * @return string|null Path canónico, o null si la petición no aplica.
	 */
	private static function eligible_request_path() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method || ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		if ( self::has_session_cookie() ) {
			return null;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = self::canonical_path( $uri );

		return self::is_bypassed( $path ) ? null : $path;
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

	// ── Aprendizaje: observar lo que WordPress respondió ─────────────────────

	/**
	 * Corre en `shutdown`, cuando ya sabemos el código de respuesta real.
	 *
	 * Solo se fía del par (is_404(), http_response_code() === 404): así no se
	 * aprende de soft-404 que luego redirigen, ni de plantillas que marcan 404
	 * y después cambian de opinión.
	 */
	public static function observe_response(): void {
		if ( self::$observed || ! self::enabled() ) {
			return;
		}
		self::$observed = true;

		if ( ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		if ( 404 !== (int) http_response_code() ) {
			return;
		}

		$path = self::eligible_request_path();
		if ( null === $path ) {
			return;
		}

		self::remember_404( $path );
	}

	/**
	 * Guarda un 404 confirmado. Idempotente.
	 */
	public static function remember_404( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		$redis = self::connect();
		if ( ! $redis ) {
			return;
		}
		try {
			$redis->setEx( self::deny_key( self::host_late(), $path ), self::ttl(), (string) time() );
		} catch ( \Throwable $e ) {
		}
	}

	/**
	 * Olvida un 404 aprendido: la URL volvió a existir.
	 */
	public static function forget_404( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		$redis = self::connect();
		if ( ! $redis ) {
			return;
		}
		try {
			$redis->del( self::deny_key( self::host_late(), $path ) );
		} catch ( \Throwable $e ) {
		}
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

	// ── Hooks de WordPress ───────────────────────────────────────────────────

	public static function init(): void {
		// Con el firewall apagado no se registra nada: ni aprendizaje ni conexiones
		// a Redis en save_post. Los comandos de CLI sí se registran para poder
		// inspeccionar y purgar sin encenderlo.
		if ( ! self::enabled() ) {
			if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
				\WP_CLI::add_command( 'pigcache-fw', 'PigCache_Url_Firewall_CLI' );
			}
			return;
		}

		// Aprender de las respuestas reales del sitio.
		add_action( 'shutdown', array( __CLASS__, 'observe_response' ), 0 );

		// Una URL que vuelve a existir deja de estar bloqueada de inmediato.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'created_term', array( __CLASS__, 'on_term' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 20, 3 );

		// Cambios estructurales reescriben todas las URLs del sitio: lo aprendido
		// deja de ser válido y hay que olvidarlo entero.
		foreach ( array( 'permalink_structure', 'category_base', 'tag_base', 'home', 'siteurl' ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'on_structure_change' ), 10, 0 );
		}
		add_action( 'permalink_structure_changed', array( __CLASS__, 'on_structure_change' ), 10, 0 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'pigcache-fw', 'PigCache_Url_Firewall_CLI' );
		}
	}

	public static function on_save_post( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$path = self::permalink_path_for_post( (int) $post_id );
		if ( '' !== $path ) {
			self::forget_404( $path );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post ) {
			self::on_save_post( $post->ID );
		}
	}

	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( is_string( $link ) ) {
			self::forget_404( self::path_from_url( $link ) );
		}
	}

	/**
	 * Tras un cambio estructural, todo lo aprendido puede ser un falso positivo.
	 * Se borra entero; el firewall vuelve a aprender en minutos.
	 */
	public static function on_structure_change(): void {
		self::purge();
	}

	// ── Mantenimiento ────────────────────────────────────────────────────────

	/**
	 * Borra todos los 404 aprendidos de este host.
	 *
	 * @return int|null Llaves borradas, o null si Redis no responde.
	 */
	public static function purge() {
		$redis = self::connect();
		if ( ! $redis ) {
			return null;
		}

		$pattern = self::deny_prefix( self::host_late() ) . '*';
		$deleted = 0;
		$cursor  = null;

		try {
			// SCAN en lotes: nunca KEYS, que bloquea el servidor en sitios grandes.
			$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
			while ( $keys = $redis->scan( $cursor, $pattern, 500 ) ) {
				if ( ! empty( $keys ) ) {
					$redis->del( $keys );
					$deleted += count( $keys );
				}
				if ( 0 === (int) $cursor ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			return $deleted;
		}

		return $deleted;
	}

	/**
	 * Cuántos 404 hay aprendidos ahora mismo (cuenta por SCAN, no bloquea).
	 *
	 * @return int
	 */
	public static function learned_count(): int {
		$redis = self::connect();
		if ( ! $redis ) {
			return 0;
		}

		$pattern = self::deny_prefix( self::host_late() ) . '*';
		$count   = 0;
		$cursor  = null;

		try {
			$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
			while ( $keys = $redis->scan( $cursor, $pattern, 500 ) ) {
				$count += count( $keys );
				if ( 0 === (int) $cursor ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
		}

		return $count;
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
			'learned'   => 0,
			'ttl'       => self::ttl(),
			'top_paths' => array(),
			'top_ips'   => array(),
			'top_uas'   => array(),
			'recent'    => array(),
		);
		$redis = self::connect();
		if ( ! $redis ) {
			return $out;
		}
		$host           = $out['host'];
		$out['learned'] = self::learned_count();

		if ( $top < 1 ) {
			return $out;
		}

		try {
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
		// ASCII generarían un md5 distinto en cada lado.
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
	 * Rutas que NUNCA se bloquean ni se aprenden (se dejan pasar a WordPress).
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

	// ── Host / llaves ────────────────────────────────────────────────────────

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

	public static function deny_prefix( string $h ): string {
		return self::PREFIX . '404:' . $h . ':';
	}
	public static function deny_key( string $h, string $path ): string {
		return self::deny_prefix( $h ) . md5( $path );
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
	 * Se cachea por proceso: una importación masiva dispara miles de save_post
	 * y abrir un socket por cada uno era medio segundo de conexiones por post.
	 *
	 * @return \Redis|null
	 */
	private static function connect() {
		if ( null !== self::$redis ) {
			return self::$redis ?: null;
		}
		if ( ! class_exists( 'Redis' ) ) {
			self::$redis = false;
			return null;
		}
		$host = defined( 'PIGCACHE_REDIS_HOST' ) ? PIGCACHE_REDIS_HOST : '127.0.0.1';
		$port = defined( 'PIGCACHE_REDIS_PORT' ) ? (int) PIGCACHE_REDIS_PORT : 6379;
		$db   = defined( 'PIGCACHE_FW_REDIS_DATABASE' ) ? (int) PIGCACHE_FW_REDIS_DATABASE : 0;

		try {
			$r = new \Redis();
			if ( ! $r->connect( $host, $port, 0.2 ) ) {
				self::$redis = false;
				return null;
			}
			if ( defined( 'PIGCACHE_REDIS_PASSWORD' ) && PIGCACHE_REDIS_PASSWORD !== '' ) {
				$r->auth( PIGCACHE_REDIS_PASSWORD );
			}
			if ( $db > 0 ) {
				$r->select( $db );
			}
			self::$redis = $r;
			return $r;
		} catch ( \Throwable $e ) {
			self::$redis = false;
			return null;
		}
	}

	/**
	 * Suelta la conexión cacheada. Solo para tests.
	 */
	public static function reset_connection(): void {
		self::$redis    = null;
		self::$observed = false;
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
			\WP_CLI::log( '404 vivos: ' . $rep['learned'] );
			\WP_CLI::log( 'TTL:       ' . $rep['ttl'] . 's' );
		}

		/**
		 * Olvida todos los 404 aprendidos.
		 *
		 * Úsalo tras cambiar la estructura de permalinks si el firewall no lo
		 * detectó solo, o para forzar un reaprendizaje limpio. Es seguro: el
		 * peor efecto es que los scrapers vuelvan a pagar un 404 de WordPress
		 * por URL hasta que se reaprenda.
		 *
		 * ## EXAMPLES
		 *     wp pigcache-fw purge
		 */
		public function purge( $args, $assoc ) {
			$n = PigCache_Url_Firewall::purge();
			if ( null === $n ) {
				\WP_CLI::error( 'Redis no responde. Revisa PIGCACHE_REDIS_* y que redis esté arriba.' );
			}
			\WP_CLI::success( sprintf( '%d entradas olvidadas.', (int) $n ) );
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

			\WP_CLI::log( sprintf( 'Host %s · 404 vivos=%d · modo=%s · ttl=%ds',
				$rep['host'], $rep['learned'], PigCache_Url_Firewall::mode(), $rep['ttl'] ) );

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
