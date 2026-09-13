<?php
/**
 * Minimal WordPress stubs for the unit suite.
 *
 * Each stub mirrors the behaviour of core that the code under test actually
 * depends on — no more. Where a test needs to drive a stub (filters, options,
 * multisite), it goes through PigCache_Test_WP so the state is explicit and can
 * be reset between tests.
 *
 * @package PigCache
 */

declare( strict_types=1 );

/**
 * Mutable state behind the stubs.
 */
final class PigCache_Test_WP {

	/** @var array<string, callable[]> */
	public static $filters = array();

	/** @var string */
	public static $site_url = 'https://example.com';

	/** @var string */
	public static $home_url = 'https://example.com';

	/** @var bool */
	public static $is_multisite = false;

	/** @var string[] Hosts recognised as sites in the network. */
	public static $network_hosts = array();

	/**
	 * Restore every stub to its default. Call from setUp().
	 */
	public static function reset(): void {
		self::$filters       = array();
		self::$site_url      = 'https://example.com';
		self::$home_url      = 'https://example.com';
		self::$is_multisite  = false;
		self::$network_hosts = array();

		$_SERVER = array_diff_key(
			$_SERVER,
			array_flip( array( 'HTTP_HOST', 'SERVER_NAME', 'REQUEST_URI', 'REQUEST_METHOD', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP' ) )
		);
		$_COOKIE = array();
		$_POST   = array();
	}

	/**
	 * Register a filter callback for the duration of a test.
	 */
	public static function add_filter( string $tag, callable $cb ): void {
		self::$filters[ $tag ][] = $cb;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		foreach ( PigCache_Test_WP::$filters[ $tag ] ?? array() as $cb ) {
			$value = $cb( $value, ...array_slice( $args, 2 ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
		PigCache_Test_WP::add_filter( (string) $tag, $cb );
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Mirrors core closely enough for key derivation: strip tags, drop control
	 * characters, collapse whitespace.
	 */
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		$str = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $str );
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return PigCache_Test_WP::$site_url . $path;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return PigCache_Test_WP::$home_url . $path;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return PigCache_Test_WP::$is_multisite;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $args = array() ) {
		$domain = $args['domain'] ?? '';
		return in_array( $domain, PigCache_Test_WP::$network_hosts, true ) ? array( 1 ) : array();
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
