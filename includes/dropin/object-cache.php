<?php
/**
 * Plugin Name: PigCache Object Cache Drop-In
 * Plugin URI: https://pigcache.object-cache/drop-in
 * Description: Redis-backed persistent object cache for WordPress (PhpRedis, Predis, Relay, replication). Same role as Redis Object Cache; shipped as part of PigCache.
 * Version: 2.7.0
 * Author: PigCache (based on Redis Object Cache by Till Krüss)
 * Author URI: https://github.com/rhubarbgroup/redis-cache
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2
 *
 * @package PigCache\ObjectCache
 *
 * Derived from Redis Object Cache (https://github.com/rhubarbgroup/redis-cache), GPLv3.
 */

defined( 'ABSPATH' ) || exit;

// This dropin runs from wp-content/object-cache.php — plugin_dir_path() is unavailable.
// Prefer PIGCACHE_DIR already set by pigcache.php via plugin_dir_path(__FILE__), then
// fall back to scanning common locations when the dropin loads before the plugin does.
if ( ! defined( 'PIGCACHE_PLUGIN_DIR' ) ) {
	if ( defined( 'PIGCACHE_DIR' ) ) {
		define( 'PIGCACHE_PLUGIN_DIR', untrailingslashit( PIGCACHE_DIR ) );
	} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
		foreach ( array(
			WP_CONTENT_DIR . '/plugins/pigcache',
			WP_CONTENT_DIR . '/mu-plugins/pigcache',
		) as $_pigcache_dir ) {
			if ( is_dir( $_pigcache_dir ) ) {
				define( 'PIGCACHE_PLUGIN_DIR', $_pigcache_dir );
				break;
			}
		}
		unset( $_pigcache_dir );
	}
}

if ( defined( 'PIGCACHE_PLUGIN_DIR' ) ) {
	$pigcache_autoload = PIGCACHE_PLUGIN_DIR . '/vendor/autoload.php';
	if ( is_readable( $pigcache_autoload ) ) {
		require_once $pigcache_autoload;
	}
}

// phpcs:disable Generic.WhiteSpace.ScopeIndent.IncorrectExact, Generic.WhiteSpace.ScopeIndent.Incorrect

// PIGCACHE_DEAD_CODE: PIGCACHE_REDIS_DISABLED wrapper (legacy Till Kruss kill-switch).
// PigCache replaces this with its own circuit breaker (pigcache_circuit_*),
// which is smarter (auto-recovers after RETRY_INTERVAL instead of staying off
// until someone re-deploys wp-config.php). No external code or docs reference
// PIGCACHE_REDIS_DISABLED. Safe to remove: delete the `if (...) :` here and
// the matching `endif;` at the bottom of this file (~line 3073).
if ( ! defined( 'PIGCACHE_REDIS_DISABLED' ) || ! PIGCACHE_REDIS_DISABLED ) :

/**
 * Determines whether the object cache implementation supports a particular feature.
 *
 * Possible values include:
 *  - `add_multiple`, `set_multiple`, `get_multiple` and `delete_multiple`
 *  - `flush_runtime` and `flush_group`
 *
 * @param string $feature Name of the feature to check for.
 * @return bool True if the feature is supported, false otherwise.
 */
function wp_cache_supports( $feature ) {
    switch ( $feature ) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
        case 'flush_group':
            return true;

        default:
            return false;
    }
}


/**
 * Adds a value to cache.
 *
 * If the specified key already exists, the value is not stored and the function
 * returns false.
 *
 * @param string $key    The key under which to store the value.
 * @param mixed  $data   The value to store.
 * @param string $group  The group value appended to the $key.
 * @param int    $expire The expiration time, defaults to 0.
 *
 * @return bool          Returns TRUE on success or FALSE on failure.
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->add( $key, $data, $group, $expire );
}

/**
 * Adds multiple values to the cache in one call.
 *
 * @param array  $data   Array of keys and values to be set.
 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
 * @param int    $expire Optional. When to expire the cache contents, in seconds.
 *                       Default 0 (no expiration).
 * @return bool[] Array of return values, grouped by key. Each value is either
 *                true on success, or false if cache key and group already exist.
 */
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->add_multiple( $data, $group, $expire );
}

/**
 * Closes the cache.
 *
 * This function has ceased to do anything since WordPress 2.5. The
 * functionality was removed along with the rest of the persistent cache. This
 * does not mean that plugins can't implement this function when they need to
 * make sure that the cache is cleaned up after WordPress no longer needs it.
 *
 * @return  bool    Always returns True
 */
function wp_cache_close() {
    return true;
}

/**
 * Decrement a numeric item's value.
 *
 * @param string $key    The key under which to store the value.
 * @param int    $offset The amount by which to decrement the item's value.
 * @param string $group  The group value appended to the $key.
 *
 * @return int|bool      Returns item's new value on success or FALSE on failure.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;

    return $wp_object_cache->decrement( $key, $offset, $group );
}

/**
 * Remove the item from the cache.
 *
 * @param string $key    The key under which to store the value.
 * @param string $group  The group value appended to the $key.
 * @param int    $time   The amount of time the server will wait to delete the item in seconds.
 *
 * @return bool          Returns TRUE on success or FALSE on failure.
 */
function wp_cache_delete( $key, $group = '', $time = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->delete( $key, $group, $time );
}

/**
 * Deletes multiple values from the cache in one call.
 *
 * @param array  $keys  Array of keys under which the cache to deleted.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @return bool[] Array of return values, grouped by key. Each value is either
 *                true on success, or false if the contents were not deleted.
 */
function wp_cache_delete_multiple( array $keys, $group = '' ) {
    global $wp_object_cache;

    return $wp_object_cache->delete_multiple( $keys, $group );
}

/**
 * Invalidate all items in the cache. If `PIGCACHE_REDIS_SELECTIVE_FLUSH` is `true`,
 * only keys prefixed with the `PIGCACHE_REDIS_PREFIX` are flushed.
 *
 * @return bool       Returns TRUE on success or FALSE on failure.
 */
function wp_cache_flush() {
    global $wp_object_cache;

    return $wp_object_cache->flush();
}

/**
 * Removes all cache items in a group.
 *
 * @param string $group Name of group to remove from cache.
 * @return true Returns TRUE on success or FALSE on failure.
 */
function wp_cache_flush_group( $group )
{
    global $wp_object_cache;

    return $wp_object_cache->flush_group( $group );
}

/**
 * Removes all cache items from the in-memory runtime cache.
 *
 * @return bool True on success, false on failure.
 */
function wp_cache_flush_runtime() {
    global $wp_object_cache;

    return $wp_object_cache->flush_runtime();
}

/**
 * Retrieve object from cache.
 *
 * Gets an object from cache based on $key and $group.
 *
 * @param string $key        The key under which to store the value.
 * @param string $group      The group value appended to the $key.
 * @param bool   $force      Optional. Whether to force an update of the local cache from the persistent
 *                           cache. Default false.
 * @param bool   $found      Optional. Whether the key was found in the cache. Disambiguates a return of false,
 *                           a storable value. Passed by reference. Default null.
 *
 * @return bool|mixed        Cached object value.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
    global $wp_object_cache;

    return $wp_object_cache->get( $key, $group, $force, $found );
}

/**
 * Retrieves multiple values from the cache in one call.
 *
 * @param array  $keys  Array of keys under which the cache contents are stored.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool   $force Optional. Whether to force an update of the local cache
 *                      from the persistent cache. Default false.
 * @return array Array of values organized into groups.
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
    global $wp_object_cache;

    return $wp_object_cache->get_multiple( $keys, $group, $force );
}

/**
 * Increment a numeric item's value.
 *
 * @param string $key    The key under which to store the value.
 * @param int    $offset The amount by which to increment the item's value.
 * @param string $group  The group value appended to the $key.
 *
 * @return int|bool      Returns item's new value on success or FALSE on failure.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;

    return $wp_object_cache->increment( $key, $offset, $group );
}

/**
 * Sets up Object Cache Global and assigns it.
 *
 * @return  void
 */
function wp_cache_init() {
    global $wp_object_cache;

    // Resolve PIGCACHE_REDIS_PREFIX from env, WP_CACHE_KEY_SALT, or auto-generated DB_NAME hash.
    if ( ! defined( 'PIGCACHE_REDIS_PREFIX' ) ) {
        if ( getenv( 'PIGCACHE_REDIS_PREFIX' ) ) {
            define( 'PIGCACHE_REDIS_PREFIX', getenv( 'PIGCACHE_REDIS_PREFIX' ) );
        } elseif ( defined( 'WP_CACHE_KEY_SALT' ) ) {
            define( 'PIGCACHE_REDIS_PREFIX', WP_CACHE_KEY_SALT );
        } elseif ( isset( $_SERVER['cw_allowed_ip'] ) ) {
            define( 'PIGCACHE_REDIS_PREFIX', (string) getenv( 'HTTP_X_APP_USER' ) );
        } elseif ( defined( 'DB_NAME' ) ) {
            global $table_prefix;
            $auto_seed = DB_NAME . '|' . ( isset( $table_prefix ) ? $table_prefix : '' );
            define( 'PIGCACHE_REDIS_PREFIX', substr( md5( $auto_seed ), 0, 8 ) . ':' );
        }
    }

    // Resolve PIGCACHE_REDIS_SELECTIVE_FLUSH from env or defaults to true when a prefix is active.
    if ( ! defined( 'PIGCACHE_REDIS_SELECTIVE_FLUSH' ) ) {
        if ( getenv( 'PIGCACHE_REDIS_SELECTIVE_FLUSH' ) ) {
            define( 'PIGCACHE_REDIS_SELECTIVE_FLUSH', (bool) getenv( 'PIGCACHE_REDIS_SELECTIVE_FLUSH' ) );
        } elseif ( defined( 'PIGCACHE_REDIS_PREFIX' ) && PIGCACHE_REDIS_PREFIX ) {
            define( 'PIGCACHE_REDIS_SELECTIVE_FLUSH', true );
        }
    }

    if ( ! ( $wp_object_cache instanceof WP_Object_Cache ) ) {
        // Default: graceful fallback (site stays up when Redis is unavailable).
        // Set define('PIGCACHE_REDIS_GRACEFUL', false) to restore the hard-error screen.
        $fail_gracefully = defined( 'PIGCACHE_REDIS_GRACEFUL' ) ? (bool) PIGCACHE_REDIS_GRACEFUL : true;

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $wp_object_cache = new WP_Object_Cache( $fail_gracefully );
    }
}

/**
 * Replaces a value in cache.
 *
 * This method is similar to "add"; however, is does not successfully set a value if
 * the object's key is not already set in cache.
 *
 * @param string $key    The key under which to store the value.
 * @param mixed  $data   The value to store.
 * @param string $group  The group value appended to the $key.
 * @param int    $expire The expiration time, defaults to 0.
 *
 * @return bool          Returns TRUE on success or FALSE on failure.
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->replace( $key, $data, $group, $expire );
}

/**
 * Sets a value in cache.
 *
 * The value is set whether or not this key already exists in Redis.
 *
 * @param string $key    The key under which to store the value.
 * @param mixed  $data   The value to store.
 * @param string $group  The group value appended to the $key.
 * @param int    $expire The expiration time, defaults to 0.
 *
 * @return bool          Returns TRUE on success or FALSE on failure.
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->set( $key, $data, $group, $expire );
}

/**
 * Sets multiple values to the cache in one call.
 *
 * @param array  $data   Array of keys and values to be set.
 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
 * @param int    $expire Optional. When to expire the cache contents, in seconds.
 *                       Default 0 (no expiration).
 * @return bool[] Array of return values, grouped by key. Each value is either
 *                true on success, or false on failure.
 */
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;

    return $wp_object_cache->set_multiple( $data, $group, $expire );
}

/**
 * Switch the internal blog id.
 *
 * This changes the blog id used to create keys in blog specific groups.
 *
 * @param  int $blog_id The blog ID.
 *
 * @return bool
 */
function wp_cache_switch_to_blog( $blog_id ) {
    global $wp_object_cache;

    return $wp_object_cache->switch_to_blog( $blog_id );
}

/**
 * Adds a group or set of groups to the list of Redis groups.
 *
 * @param   string|array $groups     A group or an array of groups to add.
 *
 * @return  void
 */
function wp_cache_add_global_groups( $groups ) {
    global $wp_object_cache;

    $wp_object_cache->add_global_groups( $groups );
}

/**
 * Adds a group or set of groups to the list of non-Redis groups.
 *
 * @param   string|array $groups     A group or an array of groups to add.
 *
 * @return  void
 */
function wp_cache_add_non_persistent_groups( $groups ) {
    global $wp_object_cache;

    $wp_object_cache->add_non_persistent_groups( $groups );
}

/**
 * Object cache class definition
 */
#[AllowDynamicProperties]
class WP_Object_Cache {
    /**
     * The Redis client.
     *
     * @var mixed
     */
    private $redis;

    /**
     * The Redis server version.
     *
     * @var null|string
     */
    private $redis_version = null;

    /**
     * Track if Redis is available.
     *
     * @var bool
     */
    private $redis_connected = false;

    /**
     * Check to fail gracefully or throw an exception.
     *
     * @var bool
     */
    private $fail_gracefully = true;

    /**
     * Whether to use igbinary serialization.
     *
     * @var bool
     */
    private $use_igbinary = false;

    /**
     * Holds the non-Redis objects.
     *
     * @var array
     */
    public $cache = [];

    /**
     * Holds the diagnostics values.
     *
     * @var array
     */
    public $diagnostics = null;

    /**
     * Holds the error messages.
     *
     * @var array
     */
    public $errors = [];

    /**
     * List of global groups.
     *
     * @var array<string>
     */
    public $global_groups = [
        'blog-details',
        'blog-id-cache',
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'user_meta',
        'userslugs',
    ];

    /**
     * List of groups that will not be flushed.
     *
     * @var array
     */
    public $unflushable_groups = [];

    /**
     * List of groups not saved to Redis.
     *
     * @var array
     */
    public $ignored_groups = [];

    /**
     * List of groups and their types.
     *
     * @var array
     */
    public $group_type = [];

    /**
     * Prefix used for global groups.
     *
     * @var string
     */
    public $global_prefix = '';

    /**
     * Prefix used for non-global groups.
     *
     * @var int
     */
    public $blog_prefix = 0;

    /**
     * Track how many requests were found in cache.
     *
     * @var int
     */
    public $cache_hits = 0;

    /**
     * Track how may requests were not cached.
     *
     * @var int
     */
    public $cache_misses = 0;

    /**
     * The amount of Redis commands made.
     *
     * @var int
     */
    public $cache_calls = 0;

    /**
     * The amount of microseconds (μs) waited for Redis commands.
     *
     * @var float
     */
    public $cache_time = 0;

    /**
     * Instantiate the Redis class.
     *
     * @param bool $fail_gracefully Handles and logs errors if true throws exceptions otherwise.
     */
    public function __construct( $fail_gracefully = true ) {
        global $blog_id, $table_prefix;

        $this->fail_gracefully = $fail_gracefully;

        if ( defined( 'PIGCACHE_REDIS_GLOBAL_GROUPS' ) && is_array( PIGCACHE_REDIS_GLOBAL_GROUPS ) ) {
            $this->global_groups = array_map( [ $this, 'sanitize_key_part' ], PIGCACHE_REDIS_GLOBAL_GROUPS );
        }

    if ( defined( 'PIGCACHE_REDIS_IGNORED_GROUPS' ) && is_array( PIGCACHE_REDIS_IGNORED_GROUPS ) ) {
            $this->ignored_groups = array_map( [ $this, 'sanitize_key_part' ], PIGCACHE_REDIS_IGNORED_GROUPS );
        }

        if ( defined( 'PIGCACHE_REDIS_UNFLUSHABLE_GROUPS' ) && is_array( PIGCACHE_REDIS_UNFLUSHABLE_GROUPS ) ) {
            $this->unflushable_groups = array_map( [ $this, 'sanitize_key_part' ], PIGCACHE_REDIS_UNFLUSHABLE_GROUPS );
        }

        $this->cache_group_types();

        $this->use_igbinary = defined( 'PIGCACHE_REDIS_IGBINARY' ) && PIGCACHE_REDIS_IGBINARY && extension_loaded( 'igbinary' );

        $client = $this->determine_client();
        $parameters = $this->build_parameters();

        // Circuit breaker: if Redis recently failed, skip the connection attempt
        // entirely rather than paying the read_timeout cost on every request.
        if ( $this->pigcache_circuit_is_open() ) {
            $this->errors[] = 'Redis circuit breaker open — skipping connection attempt.';
            $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $this->global_groups ) );

            if ( function_exists( 'is_multisite' ) ) {
                $this->global_prefix = is_multisite() ? '' : $table_prefix;
                $this->blog_prefix   = is_multisite() ? $blog_id : $table_prefix;
            }

            return;
        }

        try {
            switch ( $client ) {
                case 'phpredis':
                    $this->connect_using_phpredis( $parameters );
                    break;
                case 'relay':
                    $this->connect_using_relay( $parameters );
                    break;
                case 'predis':
                default:
                    $this->connect_using_predis( $parameters );
                    break;
            }

            // PIGCACHE_DEAD_CODE: Redis Cluster ping branch.
            // Cluster mode (PIGCACHE_REDIS_CLUSTER) is never used by PigCache
            // — no external refs, no docs, target deployment is cPanel + standalone
            // Redis. Replace this whole if/else with just `$this->diagnostics['ping'] = $this->redis->ping();`.
            if ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
                $connectionId = is_string( PIGCACHE_REDIS_CLUSTER )
                    ? PIGCACHE_REDIS_CLUSTER
                    : current( $this->build_cluster_connection_array() );

                $this->diagnostics[ 'ping' ] = $client === 'predis'
                    ? $this->redis->getClientBy( 'id', $connectionId )->ping()
                    : $this->redis->ping( $connectionId );
            } else {
                $this->diagnostics[ 'ping' ] = $this->redis->ping();
            }

            $this->fetch_info();

            $this->redis_connected = true;
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );
        }

        // Assign global and blog prefixes for use with keys.
        if ( function_exists( 'is_multisite' ) ) {
            $this->global_prefix = is_multisite() ? '' : $table_prefix;
            $this->blog_prefix = is_multisite() ? $blog_id : $table_prefix;
        }
    }

    /**
     * Set group type array
     *
     * @return void
     */
    protected function cache_group_types() {
        foreach ( $this->global_groups as $group ) {
            $this->group_type[ $group ] = 'global';
        }

        foreach ( $this->unflushable_groups as $group ) {
            $this->group_type[ $group ] = 'unflushable';
        }

        foreach ( $this->ignored_groups as $group ) {
            $this->group_type[ $group ] = 'ignored';
        }
    }

    /**
     * Determine the Redis client.
     *
     * PigCache policy: prefer PhpRedis (PECL) when available; otherwise Predis from Composer.
     * Override with PIGCACHE_REDIS_CLIENT if needed.
     *
     * @return string
     */
    protected function determine_client() {
        $client = 'predis';

        if ( class_exists( 'Redis' ) ) {
            $client = 'phpredis';
        }

        if ( defined( 'PIGCACHE_REDIS_CLIENT' ) ) {
            $client = (string) PIGCACHE_REDIS_CLIENT;
            $client = str_replace( 'pecl', 'phpredis', $client );
        }

        $client = trim( strtolower( $client ) );

        /**
         * Filter the Redis client identifier after defaults and PIGCACHE_REDIS_CLIENT are applied.
         *
         * @param string $client One of phpredis, predis, relay.
         */
        return (string) apply_filters( 'pigcache_redis_client', $client );
    }

    /**
     * Build the connection parameters from config constants.
     *
     * @return array
     */
    protected function build_parameters() {
        $parameters = [
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'timeout' => 1,
            'read_timeout' => 1,
            'retry_interval' => null,
            'persistent' => false,
        ];

        $settings = [
            'scheme',
            'host',
            'port',
            'path',
            'password',
            'database',
            'timeout',
            'read_timeout',
            'retry_interval',
        ];

        foreach ( $settings as $setting ) {
            $constant = sprintf( 'PIGCACHE_REDIS_%s', strtoupper( $setting ) );

            if ( defined( $constant ) ) {
                $parameters[ $setting ] = constant( $constant );
            }
        }

        if ( isset( $parameters[ 'password' ] ) && $parameters[ 'password' ] === '' ) {
            unset( $parameters[ 'password' ] );
        }

        $this->diagnostics[ 'timeout' ] = $parameters[ 'timeout' ];
        $this->diagnostics[ 'read_timeout' ] = $parameters[ 'read_timeout' ];
        $this->diagnostics[ 'retry_interval' ] = $parameters[ 'retry_interval' ];

        return $parameters;
    }

    /**
     * Connect to Redis using the PhpRedis (PECL) extension.
     *
     * @param  array $parameters Connection parameters built by the `build_parameters` method.
     * @return void
     */
    protected function connect_using_phpredis( $parameters ) {
        $version = phpversion( 'redis' );

        $this->diagnostics[ 'client' ] = sprintf( 'PhpRedis (v%s)', $version );

        // PIGCACHE_DEAD_CODE: phpredis Sharding (RedisArray) and Cluster (RedisCluster) branches.
        // Lines ~742-771 (the `if SHARDS` and `elseif CLUSTER` branches). Neither
        // PIGCACHE_REDIS_SHARDS nor PIGCACHE_REDIS_CLUSTER are documented or
        // referenced outside this dropin. The `else` branch (single Redis
        // instance, line 772) is the only path PigCache actually exercises.
        // Safe to delete: collapse the `if/elseif/else` into just the body of
        // the final `else` block.
        if ( defined( 'PIGCACHE_REDIS_SHARDS' ) ) {
            $this->redis = new RedisArray( array_values( PIGCACHE_REDIS_SHARDS ) );

            $this->diagnostics[ 'shards' ] = PIGCACHE_REDIS_SHARDS;
        } elseif ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
            if ( is_string( PIGCACHE_REDIS_CLUSTER ) ) {
                $this->redis = new RedisCluster( PIGCACHE_REDIS_CLUSTER );
            } else {
                $args = [
                    'cluster' => $this->build_cluster_connection_array(),
                    'timeout' => $parameters['timeout'],
                    'read_timeout' => $parameters['read_timeout'],
                    'persistent' => $parameters['persistent'],
                ];

                if ( isset( $parameters['password'] ) && version_compare( $version, '4.3.0', '>=' ) ) {
                    $args['password'] = $parameters['password'];
                }

                if ( version_compare( $version, '5.3.0', '>=' ) && defined( 'PIGCACHE_REDIS_SSL_CONTEXT' ) && ! empty( PIGCACHE_REDIS_SSL_CONTEXT ) ) {
                    if ( ! array_key_exists( 'password', $args ) ) {
                        $args['password'] = null;
                    }

                    $args['ssl'] = PIGCACHE_REDIS_SSL_CONTEXT;
                }

                $this->redis = new RedisCluster( null, ...array_values( $args ) );
                $this->diagnostics += $args;
            }
        } else {
            $this->redis = new Redis();

            $args = [
                'host' => $parameters['host'],
                'port' => $parameters['port'],
                'timeout' => $parameters['timeout'],
                '',
                'retry_interval' => (int) $parameters['retry_interval'],
            ];

            if ( version_compare( $version, '3.1.3', '>=' ) ) {
                $args['read_timeout'] = $parameters['read_timeout'];
            }

            if ( strcasecmp( 'tls', $parameters['scheme'] ) === 0 ) {
                $args['host'] = sprintf(
                    '%s://%s',
                    $parameters['scheme'],
                    str_replace( 'tls://', '', $parameters['host'] )
                );

                if ( version_compare( $version, '5.3.0', '>=' ) && defined( 'PIGCACHE_REDIS_SSL_CONTEXT' ) && ! empty( PIGCACHE_REDIS_SSL_CONTEXT ) ) {
                    $args['others']['stream'] = PIGCACHE_REDIS_SSL_CONTEXT;
                }
            }

            if ( strcasecmp( 'unix', $parameters['scheme'] ) === 0 ) {
                $args['host'] = $parameters['path'];
                $args['port'] = -1;
            }

            call_user_func_array( [ $this->redis, 'connect' ], array_values( $args ) );

            if ( isset( $parameters['password'] ) ) {
                $args['password'] = $parameters['password'];
                $this->redis->auth( $parameters['password'] );
            }

            if ( isset( $parameters['database'] ) ) {
                if ( ctype_digit( (string) $parameters['database'] ) ) {
                    $parameters['database'] = (int) $parameters['database'];
                }

                $args['database'] = $parameters['database'];

                if ( $parameters['database'] ) {
                    $this->redis->select( $parameters['database'] );
                }
            }

            $this->diagnostics += $args;
        }
    }

    /**
     * Connect to Redis using the Relay extension.
     *
     * @param  array $parameters Connection parameters built by the `build_parameters` method.
     * @return void
     */
    // PIGCACHE_DEAD_CODE (Tier 2 — borderline): connect_using_relay() is the
    // whole Relay client path (lines ~824-895). Relay is mentioned in MANUAL.md
    // as "supported" but cPanel shared hosts never have the Relay extension
    // (it requires a license + custom build). If you confirm no client uses
    // Relay, delete the whole method AND the `case 'relay':` branch in the
    // constructor switch around line 595.
    protected function connect_using_relay( $parameters ) {
        $version = phpversion( 'relay' );

        $this->diagnostics[ 'client' ] = sprintf( 'Relay (v%s)', $version );

        // PIGCACHE_DEAD_CODE: SHARDS/CLUSTER guards inside Relay (~3 lines).
        // Even if you keep Relay, these throw branches are dead because the
        // Cluster/Shards constants are themselves dead.
        if ( defined( 'PIGCACHE_REDIS_SHARDS' ) ) {
            throw new Exception('Relay does not support sharding.');
        } elseif ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
            throw new Exception('Relay does not cluster connections.');
        } else {
            $this->redis = new Relay\Relay;

            $args = [
                'host' => $parameters['host'],
                'port' => $parameters['port'],
                'timeout' => $parameters['timeout'],
                '',
                'retry_interval' => (int) $parameters['retry_interval'],
            ];

            $args['read_timeout'] = $parameters['read_timeout'];

            if ( strcasecmp( 'tls', $parameters['scheme'] ) === 0 ) {
                $args['host'] = sprintf(
                    '%s://%s',
                    $parameters['scheme'],
                    str_replace( 'tls://', '', $parameters['host'] )
                );

                if ( defined( 'PIGCACHE_REDIS_SSL_CONTEXT' ) && ! empty( PIGCACHE_REDIS_SSL_CONTEXT ) ) {
                    $args['others']['stream'] = PIGCACHE_REDIS_SSL_CONTEXT;
                }
            }

            if ( strcasecmp( 'unix', $parameters['scheme'] ) === 0 ) {
                $args['host'] = $parameters['path'];
                $args['port'] = -1;
            }

            call_user_func_array( [ $this->redis, 'connect' ], array_values( $args ) );

            if ( isset( $parameters['password'] ) ) {
                $args['password'] = $parameters['password'];
                $this->redis->auth( $parameters['password'] );
            }

            if ( isset( $parameters['database'] ) ) {
                if ( ctype_digit( (string) $parameters['database'] ) ) {
                    $parameters['database'] = (int) $parameters['database'];
                }

                $args['database'] = $parameters['database'];

                if ( $parameters['database'] ) {
                    $this->redis->select( $parameters['database'] );
                }
            }

            $this->diagnostics += $args;
        }
    }

    /**
     * Connect to Redis using the Predis library.
     *
     * @param  array $parameters Connection parameters built by the `build_parameters` method.
     * @throws \Exception If the Predis library was not found or is unreadable.
     * @return void
     */
    protected function connect_using_predis( $parameters ) {
        $client = 'Predis';

        // Load bundled Predis library. The dropin is loaded by WordPress
        // BEFORE the main plugin file runs, so PIGCACHE_PLUGIN_DIR is almost
        // never defined when we get here. Search the standard install
        // locations (mirrors advanced-cache.php's plugin-discovery pattern)
        // so we work on hosts that don't have the phpredis PECL extension.
        if ( ! class_exists( 'Predis\Client' ) ) {
            $autoload_candidates = array();

            if ( defined( 'PIGCACHE_PLUGIN_DIR' ) ) {
                $autoload_candidates[] = PIGCACHE_PLUGIN_DIR . '/vendor/autoload.php';
            }

            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $autoload_candidates[] = WP_CONTENT_DIR . '/plugins/pigcache/vendor/autoload.php';
                $autoload_candidates[] = WP_CONTENT_DIR . '/mu-plugins/pigcache/vendor/autoload.php';

                // Anything under wp-content/plugins/* that ships the same vendor.
                if ( function_exists( 'glob' ) ) {
                    $glob_matches = glob( WP_CONTENT_DIR . '/plugins/*/vendor/predis/predis/src/Client.php' );
                    if ( is_array( $glob_matches ) ) {
                        foreach ( $glob_matches as $client_path ) {
                            $autoload_candidates[] = dirname( $client_path, 4 ) . '/autoload.php';
                        }
                    }
                }
            }

            foreach ( $autoload_candidates as $autoload_path ) {
                if ( $autoload_path && is_readable( $autoload_path ) ) {
                    require_once $autoload_path;
                    if ( class_exists( 'Predis\Client' ) ) {
                        break;
                    }
                }
            }

            if ( ! class_exists( 'Predis\Client' ) ) {
                throw new Exception(
                    'Predis not found. Run `composer install` in wp-content/plugins/pigcache or remove wp-content/object-cache.php.'
                );
            }
        }

        $servers = false;
        $options = [];

        // PIGCACHE_DEAD_CODE: Predis SHARDS / SENTINEL / SERVERS (replication) / CLUSTER branches.
        // All four constants (PIGCACHE_REDIS_SHARDS, _SENTINEL, _SERVERS, _CLUSTER)
        // are dead — zero external refs, zero docs, target environment is single
        // standalone Redis. Safe to delete this whole `if/elseif` chain; the
        // `$servers = false` initialization above already handles the only path
        // PigCache uses (single-server connection via $parameters).
        if ( defined( 'PIGCACHE_REDIS_SHARDS' ) ) {
            $servers = PIGCACHE_REDIS_SHARDS;
            $parameters['shards'] = $servers;
        } elseif ( defined( 'PIGCACHE_REDIS_SENTINEL' ) ) {
            $servers = PIGCACHE_REDIS_SERVERS;
            $parameters['servers'] = $servers;
            $options['replication'] = 'sentinel';
            $options['service'] = PIGCACHE_REDIS_SENTINEL;
        } elseif ( defined( 'PIGCACHE_REDIS_SERVERS' ) ) {
            $servers = PIGCACHE_REDIS_SERVERS;
            $parameters['servers'] = $servers;
            $options['replication'] = 'predis';
        } elseif ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
            $servers = $this->build_cluster_connection_array();
            $parameters['cluster'] = $servers;
            $options['cluster'] = 'redis';
        }

        if ( strcasecmp( 'unix', $parameters['scheme'] ) === 0 ) {
            unset($parameters['host'], $parameters['port']);
        }

        if ( isset( $parameters['read_timeout'] ) && $parameters['read_timeout'] ) {
            $parameters['read_write_timeout'] = $parameters['read_timeout'];
        }

        // PIGCACHE_DEAD_CODE: Predis multi-server option propagation. Only
        // triggers when one of the dead SERVERS/SHARDS/CLUSTER constants is
        // defined. Safe to delete with the if/elseif chain above.
        foreach ( [ 'PIGCACHE_REDIS_SERVERS', 'PIGCACHE_REDIS_SHARDS', 'PIGCACHE_REDIS_CLUSTER' ] as $constant ) {
            if ( defined( $constant ) ) {
                if ( $parameters['database'] ) {
                    $options['parameters']['database'] = $parameters['database'];
                }

                if ( isset( $parameters['password'] ) ) {
                    if ( is_array( $parameters['password'] ) ) {
                        $options['parameters']['username'] = PIGCACHE_REDIS_PASSWORD[0];
                        $options['parameters']['password'] = PIGCACHE_REDIS_PASSWORD[1];
                    } else {
                        $options['parameters']['password'] = PIGCACHE_REDIS_PASSWORD;
                    }
                }
            }
        }

        if ( isset( $parameters['password'] ) ) {
            if ( is_array( $parameters['password'] ) ) {
                $parameters['username'] = array_shift( $parameters['password'] );
                $parameters['password'] = implode( '', $parameters['password'] );
            }

            if ( defined( 'PIGCACHE_REDIS_USERNAME' ) ) {
                $parameters['username'] = PIGCACHE_REDIS_USERNAME;
            }
        }

        if ( defined( 'PIGCACHE_REDIS_SSL_CONTEXT' ) && ! empty( PIGCACHE_REDIS_SSL_CONTEXT ) ) {
            $parameters['ssl'] = PIGCACHE_REDIS_SSL_CONTEXT;
        }

        $this->redis = new Predis\Client( $servers ?: $parameters, $options );
        $this->redis->connect();

        $this->diagnostics = array_merge(
            [ 'client' => sprintf( '%s (v%s)', $client, Predis\Client::VERSION ) ],
            $parameters,
            $options
        );
    }

    /**
     * Fetches Redis `INFO` mostly for server version.
     *
     * @return void
     */
    public function fetch_info() {
        // PIGCACHE_DEAD_CODE: Cluster INFO branch in fetch_info(). Replace the
        // whole if/else with just `$info = $this->redis->info();` and the
        // single-instance handling that follows the `else`.
        if ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
            $connectionId = is_string( PIGCACHE_REDIS_CLUSTER )
                ? 'SERVER'
                : current( $this->build_cluster_connection_array() );

            $info = $this->is_predis()
                ? $this->redis->getClientBy( 'id', $connectionId )->info()
                : $this->redis->info( $connectionId );
        } else {
            if ( $this->is_predis() ) {
                $connection = $this->redis->getConnection();
                if ( $connection instanceof Predis\Connection\Replication\ReplicationInterface ) {
                    $node = $connection->getCurrent();
                    $connection->switchToMaster();
                }
            }

            $info = $this->redis->info();

            if ( isset( $connection, $node ) ) {
                $connection->switchTo($node);
            }
        }

        if ( isset( $info['redis_version'] ) ) {
            $this->redis_version = $info['redis_version'];
        } elseif ( isset( $info['Server']['redis_version'] ) ) {
            $this->redis_version = $info['Server']['redis_version'];
        }
    }

    /**
     * Is Redis available?
     *
     * @return bool
     */
    public function redis_status() {
        return (bool) $this->redis_connected;
    }

    /**
     * Returns the Redis instance.
     *
     * @return mixed
     */
    public function redis_instance() {
        return $this->redis;
    }

    /**
     * Returns the Redis server version.
     *
     * @return null|string
     */
    public function redis_version() {
        return $this->redis_version;
    }

    /**
     * Adds a value to cache.
     *
     * If the specified key already exists, the value is not stored and the function
     * returns false.
     *
     * @param   string $key            The key under which to store the value.
     * @param   mixed  $value          The value to store.
     * @param   string $group          The group value appended to the $key.
     * @param   int    $expiration     The expiration time, defaults to 0.
     * @return  bool                   Returns TRUE on success or FALSE on failure.
     */
    public function add( $key, $value, $group = 'default', $expiration = 0 ) {
        return $this->add_or_replace( true, $key, $value, $group, $expiration );
    }

    /**
     * Adds multiple values to the cache in one call.
     *
     * @param array  $data   Array of keys and values to be added.
     * @param string $group  Optional. Where the cache contents are grouped.
     * @param int    $expire Optional. When to expire the cache contents, in seconds.
     *                       Default 0 (no expiration).
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if cache key and group already exist.
     */
    public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
        if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
            return array_combine( array_keys( $data ), array_fill( 0, count( $data ), false ) );
        }

        if (
            $this->redis_status() &&
            method_exists( $this->redis, 'pipeline' ) &&
            ! $this->is_ignored_group( $group )
        ) {
            return $this->add_multiple_at_once( $data, $group, $expire );
        }

        $values = [];

        foreach ( $data as $key => $value ) {
            $values[ $key ] = $this->add( $key, $value, $group, $expire );
        }

        return $values;
    }

    /**
     * Adds multiple values to the cache in one call.
     *
     * @param array  $data   Array of keys and values to be added.
     * @param string $group  Optional. Where the cache contents are grouped.
     * @param int    $expire Optional. When to expire the cache contents, in seconds.
     *                       Default 0 (no expiration).
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if cache key and group already exist.
     */
    protected function add_multiple_at_once( array $data, $group = 'default', $expire = 0 ) {
        $keys = array_keys( $data );

        $san_group = $this->sanitize_key_part( $group );

        $tx = $this->redis->pipeline();

        $orig_exp = $expire;
        $expire = $this->validate_expiration( $expire );
        $derived_keys = [];

        foreach ( $data as $key => $value ) {
            /**
             * Filters the cache expiration time
             *
             * @param int    $expiration The time in seconds the entry expires. 0 for no expiry.
             * @param string $key        The cache key.
             * @param string $group      The cache group.
             * @param mixed  $orig_exp   The original expiration value before validation.
             */
            $expire = apply_filters( 'pigcache_cache_expiration', $expire, $key, $group, $orig_exp );

            $san_key = $this->sanitize_key_part( $key );
            $derived_key = $derived_keys[ $key ] = $this->fast_build_key( $san_key, $san_group );

            $args = [ $derived_key, $this->maybe_serialize( $value ) ];

            if ( $this->is_predis() ) {
                $args[] = 'nx';

                if ( $expire ) {
                    $args[] = 'ex';
                    $args[] = $expire;
                }
            } else {
                if ( $expire ) {
                    $args[] = [ 'nx', 'ex' => $expire ];
                } else {
                    $args[] = [ 'nx' ];
                }
            }

            $tx->set( ...$args );
        }

        try {
            $start_time = microtime( true );

            $method = $this->is_predis() ? 'execute' : 'exec';

            $results = array_map( function ( $response ) {
                return (bool) $this->parse_redis_response( $response );
            }, $tx->{$method}() ?: [] );

            if ( count( $results ) !== count( $keys ) ) {
                $tx->discard();

                return array_fill_keys( $keys, false );
            }

            $results = array_combine( $keys, $results );

            foreach ( $results as $key => $result ) {
                if ( $result ) {
                    $this->add_to_internal_cache( $derived_keys[ $key ], $data[ $key ] );
                }
            }

            $execute_time = microtime( true ) - $start_time;

            $this->cache_calls++;
            $this->cache_time += $execute_time;
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return array_combine( $keys, array_fill( 0, count( $keys ), false ) );
        }

        return $results;
    }

    /**
     * Replace a value in the cache.
     *
     * If the specified key doesn't exist, the value is not stored and the function
     * returns false.
     *
     * @param   string $key            The key under which to store the value.
     * @param   mixed  $value          The value to store.
     * @param   string $group          The group value appended to the $key.
     * @param   int    $expiration     The expiration time, defaults to 0.
     * @return  bool                   Returns TRUE on success or FALSE on failure.
     */
    public function replace( $key, $value, $group = 'default', $expiration = 0 ) {
        return $this->add_or_replace( false, $key, $value, $group, $expiration );
    }

    /**
     * Add or replace a value in the cache.
     *
     * Add does not set the value if the key exists; replace does not replace if the value doesn't exist.
     *
     * @param   bool   $add            True if should only add if value doesn't exist, false to only add when value already exists.
     * @param   string $key            The key under which to store the value.
     * @param   mixed  $value          The value to store.
     * @param   string $group          The group value appended to the $key.
     * @param   int    $expiration     The expiration time, defaults to 0.
     * @return  bool                   Returns TRUE on success or FALSE on failure.
     */
    protected function add_or_replace( $add, $key, $value, $group = 'default', $expiration = 0 ) {
        $cache_addition_suspended = function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition();

        if ( $add && $cache_addition_suspended ) {
            return false;
        }

        $result = true;

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        $derived_key = $this->fast_build_key( $san_key, $san_group );

        // Save if group not excluded and redis is up.
        if ( ! $this->is_ignored_group( $san_group ) && $this->redis_status() ) {
            try {
                $orig_exp = $expiration;
                $expiration = $this->validate_expiration( $expiration );

                /**
                 * Filters the cache expiration time
                 *
                 * @since 1.4.2
                 * @param int    $expiration The time in seconds the entry expires. 0 for no expiry.
                 * @param string $key        The cache key.
                 * @param string $group      The cache group.
                 * @param mixed  $orig_exp   The original expiration value before validation.
                 */
                $expiration = apply_filters( 'pigcache_cache_expiration', $expiration, $key, $group, $orig_exp );
                $start_time = microtime( true );

                if ( $add ) {
                    $args = [ $derived_key, $this->maybe_serialize( $value ) ];

                    if ( $this->is_predis() ) {
                        $args[] = 'nx';

                        if ( $expiration ) {
                            $args[] = 'ex';
                            $args[] = $expiration;
                        }
                    } else {
                        if ( $expiration ) {
                            $args[] = [
                                'nx',
                                'ex' => $expiration,
                            ];
                        } else {
                            $args[] = [ 'nx' ];
                        }
                    }

                    $result = $this->parse_redis_response(
                        $this->redis->set( ...$args )
                    );

                    if ( ! $result ) {
                        return false;
                    }
                } elseif ( $expiration ) {
                    $result = $this->parse_redis_response( $this->redis->setex( $derived_key, $expiration, $this->maybe_serialize( $value ) ) );
                } else {
                    $result = $this->parse_redis_response( $this->redis->set( $derived_key, $this->maybe_serialize( $value ) ) );
                }

                $execute_time = microtime( true ) - $start_time;

                $this->cache_calls++;
                $this->cache_time += $execute_time;
            } catch ( Exception $exception ) {
                $this->handle_exception( $exception );

                return false;
            }
        }

        $exists = array_key_exists( $derived_key, $this->cache );

        if ( (bool) $add === $exists ) {
            return false;
        }

        if ( $result ) {
            $this->add_to_internal_cache( $derived_key, $value );
        }

        return $result;
    }

    /**
     * Remove the item from the cache.
     *
     * @param   string $key        The key under which to store the value.
     * @param   string $group      The group value appended to the $key.
     * @return  bool               Returns TRUE on success or FALSE on failure.
     */
    public function delete( $key, $group = 'default', $deprecated = false ) {
        $result = false;

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        $derived_key = $this->fast_build_key( $san_key, $san_group );

        if ( array_key_exists( $derived_key, $this->cache ) ) {
            unset( $this->cache[ $derived_key ] );
            $result = true;
        }

        $start_time = microtime( true );

        if ( $this->redis_status() && ! $this->is_ignored_group( $san_group ) ) {
            try {
                $result = $this->parse_redis_response( $this->redis->del( $derived_key ) );
            } catch ( Exception $exception ) {
                $this->handle_exception( $exception );

                return false;
            }
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls++;
        $this->cache_time += $execute_time;

        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires on every cache key deletion
             *
             * @since 1.3.3
             * @param string $key          The cache key.
             * @param string $group        The group value appended to the $key.
             * @param float  $execute_time Execution time for the request in seconds.
             */
            do_action( 'pigcache_object_cache_delete', $key, $group, $execute_time );
        }

        return (bool) $result;
    }

    /**
     * Deletes multiple values from the cache in one call.
     *
     * @param array  $keys  Array of keys to be deleted.
     * @param string $group Optional. Where the cache contents are grouped.
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if the contents were not deleted.
     */
    public function delete_multiple( array $keys, $group = 'default' ) {
        if (
            $this->redis_status() &&
            method_exists( $this->redis, 'pipeline' ) &&
            ! $this->is_ignored_group( $group )
        ) {
            return $this->delete_multiple_at_once( $keys, $group );
        }

        $values = [];

        foreach ( $keys as $key ) {
            $values[ $key ] = $this->delete( $key, $group );
        }

        return $values;
    }

    /**
     * Deletes multiple values from the cache in one call.
     *
     * @param array  $keys  Array of keys to be deleted.
     * @param string $group Optional. Where the cache contents are grouped.
     * @return bool[] Array of return values, grouped by key. Each value is either
     *                true on success, or false if the contents were not deleted.
     */
    protected function delete_multiple_at_once( array $keys, $group = 'default' ) {
        $start_time = microtime( true );

        try {
            $tx = $this->redis->pipeline();

            foreach ( $keys as $key ) {
                $derived_key = $this->build_key( (string) $key, $group );

                $tx->del( $derived_key );

                unset( $this->cache[ $derived_key ] );
            }

            $method = $this->is_predis() ? 'execute' : 'exec';

            $results = array_map( function ( $response ) {
                return (bool) $this->parse_redis_response( $response );
            }, $tx->{$method}() ?: [] );

            if ( count( $results ) !== count( $keys ) ) {
                $tx->discard();

                return array_fill_keys( $keys, false );
            }

            $execute_time = microtime( true ) - $start_time;
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return array_combine( $keys, array_fill( 0, count( $keys ), false ) );
        }

        if ( function_exists( 'do_action' ) ) {
            foreach ( $keys as $key ) {
                /**
                 * Fires on every cache key deletion
                 *
                 * @since 1.3.3
                 * @param string $key          The cache key.
                 * @param string $group        The group value appended to the $key.
                 * @param float  $execute_time Execution time for the request in seconds.
                 */
                do_action( 'pigcache_object_cache_delete', $key, $group, $execute_time );
            }
        }

        return array_combine( $keys, $results );
    }

    /**
     * Removes all cache items from the in-memory runtime cache.
     *
     * @return bool True on success, false on failure.
     */
    public function flush_runtime() {
        $this->cache = [];

        return true;
    }

    /**
     * Executes Lua flush script.
     *
     * @return array|false  Returns array on success, false on failure
     */
    protected function execute_lua_script( $script ) {
        $results = [];

        // PIGCACHE_DEAD_CODE: Cluster Lua dispatch — Cluster mode is unused.
        // Delete this `if` block; the dropin will execute the script on the
        // single Redis instance below as it already does.
        if ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
            return $this->execute_lua_script_on_cluster( $script );
        }

        // PIGCACHE_DEAD_CODE: PIGCACHE_REDIS_FLUSH_TIMEOUT constant is undocumented
        // and unreferenced externally. Replace with a hardcoded 5 (or keep — the
        // savings here are minimal but the constant adds API surface to nothing).
        $flushTimeout = defined( 'PIGCACHE_REDIS_FLUSH_TIMEOUT' ) ? PIGCACHE_REDIS_FLUSH_TIMEOUT : 5;

        if ( $this->is_predis() ) {
            $connection = $this->redis->getConnection();

            if ($connection instanceof Predis\Connection\Replication\ReplicationInterface) {
                $connection = $connection->getMaster();
            }

            $timeout = $connection->getParameters()->read_write_timeout ?? ini_get( 'default_socket_timeout' );
            stream_set_timeout( $connection->getResource(), $flushTimeout );
        } else {
            $timeout = $this->redis->getOption( Redis::OPT_READ_TIMEOUT );
            $this->redis->setOption( Redis::OPT_READ_TIMEOUT, $flushTimeout );
        }

        try {
            $results[] = $this->parse_redis_response( $script() );
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );
            $results = false;
        }

        if ( $this->is_predis() ) {
            stream_set_timeout( $connection->getResource(), $timeout ); // @phpstan-ignore variable.undefined
        } else {
            $this->redis->setOption( Redis::OPT_READ_TIMEOUT, $timeout );
        }

        return $results;
    }

    /**
     * Executes Lua flush script on Redis cluster.
     *
     * @return array|false  Returns array on success, false on failure
     */
    // PIGCACHE_DEAD_CODE: execute_lua_script_on_cluster() is only called from
    // the (also dead) Cluster branch in execute_lua_script(). Delete the whole
    // method (~35 lines, ends at the next `}` before the comment block for
    // `public function flush()`).
    protected function execute_lua_script_on_cluster( $script ) {
        $results = [];
        $redis = $this->redis;
        $flushTimeout = defined( 'PIGCACHE_REDIS_FLUSH_TIMEOUT' ) ? PIGCACHE_REDIS_FLUSH_TIMEOUT : 5;

        if ( $this->is_predis() ) {
            foreach ( $this->redis->getIterator() as $master ) {
                $timeout = $master->getConnection()->getParameters()->read_write_timeout ?? ini_get( 'default_socket_timeout' );
                stream_set_timeout( $master->getConnection()->getResource(), $flushTimeout );

                $this->redis = $master;
                $results[] = $this->parse_redis_response( $script() );

                stream_set_timeout($master->getConnection()->getResource(), $timeout);
            }
        } else {
            try {
                foreach ( $this->redis->_masters() as $master ) {
                    $this->redis = new Redis();
                    $this->redis->connect( $master[0], $master[1], 0, null, 0, $flushTimeout );

                    $results[] = $this->parse_redis_response( $script() );
                }
            } catch ( Exception $exception ) {
                $this->handle_exception( $exception );
                $this->redis = $redis;

                return false;
            }
        }

        $this->redis = $redis;

        return $results;
    }

    /**
     * Invalidate all items in the cache. If `PIGCACHE_REDIS_SELECTIVE_FLUSH` is `true`,
     * only keys prefixed with the `PIGCACHE_REDIS_PREFIX` are flushed.
     *
     * @return bool True on success, false on failure.
     */
    public function flush() {
        $results = [];
        $this->cache = [];

        if ( $this->redis_status() ) {
            $salt = defined( 'PIGCACHE_REDIS_PREFIX' ) ? trim( PIGCACHE_REDIS_PREFIX ) : null;
            $selective = defined( 'PIGCACHE_REDIS_SELECTIVE_FLUSH' ) ? PIGCACHE_REDIS_SELECTIVE_FLUSH : null;

            $start_time = microtime( true );

            if ( $salt && $selective ) {
                $script = $this->get_flush_closure( $salt );
                $results = $this->execute_lua_script( $script );

                if ( empty( $results ) ) {
                    return false;
                }
            } else {
                // PIGCACHE_DEAD_CODE: Cluster flush fan-out (iterates masters).
                // Unused — Cluster mode is dead. Replace this whole if/else with
                // just the body of the final `else` (single-instance flushdb call).
                if ( defined( 'PIGCACHE_REDIS_CLUSTER' ) ) {
                    try {
                        if ( $this->is_predis() ) {
                            foreach ( $this->redis->getIterator() as $master ) {
                                $results[] = $this->parse_redis_response( $master->flushdb() );
                            }
                        } else {
                            foreach ( $this->redis->_masters() as $master ) {
                                $results[] = $this->parse_redis_response( $this->redis->flushdb( $master ) );
                            }
                        }
                    } catch ( Exception $exception ) {
                        $this->handle_exception( $exception );

                        return false;
                    }
                } else {
                    try {
                        $results[] = $this->parse_redis_response( $this->redis->flushdb() );
                    } catch ( Exception $exception ) {
                        $this->handle_exception( $exception );

                        return false;
                    }
                }
            }

            if ( function_exists( 'do_action' ) ) {
                $execute_time = microtime( true ) - $start_time;

                /**
                 * Fires on every cache flush
                 *
                 * @since 1.3.5
                 * @param null|array $results      Array of flush results.
                 * @param int        $deprecated   Unused. Default 0.
                 * @param bool       $seletive     Whether a selective flush took place.
                 * @param string     $salt         The defined key prefix.
                 * @param float      $execute_time Execution time for the request in seconds.
                 */
                do_action( 'pigcache_object_cache_flush', $results, 0, $selective, $salt, $execute_time );
            }
        }

        if ( empty( $results ) ) {
            return false;
        }

        foreach ( $results as $result ) {
            if ( ! $result ) {
                return false;
            }
        }

        return true;
    }

    /**
	 * Removes all cache items in a group.
	 *
	 * @param string $group Name of group to remove from cache.
	 * @return bool Returns TRUE on success or FALSE on failure.
	 */
    public function flush_group( $group ) {
        // PIGCACHE_DEAD_CODE: PIGCACHE_REDIS_DISABLE_GROUP_FLUSH escape hatch.
        // Undocumented, zero external refs. Delete this `if` block to keep the
        // per-group flush always enabled (which is what PigCache_Sql_Cache and
        // PigCache_Html_Cache rely on for surgical invalidation).
        if ( defined( 'PIGCACHE_REDIS_DISABLE_GROUP_FLUSH' ) && PIGCACHE_REDIS_DISABLE_GROUP_FLUSH ) {
            return $this->flush();
        }

        $san_group = $this->sanitize_key_part( $group );

        if ( is_multisite() && ! $this->is_global_group( $san_group ) ) {
            $salt = str_replace( "{$this->blog_prefix}:{$san_group}", "*:{$san_group}", $this->fast_build_key( '*', $san_group ) );
        } else {
            $salt = $this->fast_build_key( '*', $san_group );
        }

        foreach ( $this->cache as $key => $value ) {
            if ( strpos( $key, "{$san_group}:" ) === 0 || strpos( $key, ":{$san_group}:" ) !== false ) {
                unset( $this->cache[ $key ] );
            }
        }

        if ( in_array( $san_group, $this->unflushable_groups ) ) {
            return false;
        }

        if ( ! $this->redis_status() ) {
            return false;
        }

        $start_time = microtime( true );
        $script = $this->lua_flush_closure( $salt, false );
        $results = $this->execute_lua_script( $script );

        if ( empty( $results ) ) {
            return false;
        }

        if ( function_exists( 'do_action' ) ) {
            $execute_time = microtime( true ) - $start_time;

            /**
             * Fires on every group cache flush
             *
             * @param null|array $results Array of flush results.
             * @param string $salt The defined key prefix.
             * @param float $execute_time Execution time for the request in seconds.
             * @since 2.2.3
             */
            do_action( 'pigcache_object_cache_flush_group', $results, $salt, $execute_time );
        }

        foreach ( $results as $result ) {
            if ( ! $result ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a closure to flush selectively.
     *
     * @param   string $salt  The salt to be used to differentiate.
     * @return  callable      Generated callable executing the lua script.
     */
    protected function get_flush_closure( $salt ) {
        if ( $this->unflushable_groups ) {
            return $this->lua_flush_extended_closure( $salt );
        } else {
            return $this->lua_flush_closure( $salt );
        }
    }

    /**
     * Quotes a string for usage in the `glob` function
     *
     * @param string $string The string to quote.
     * @return string
     */
    protected function glob_quote( $string ) {
        $characters = [ '*', '+', '?', '!', '{', '}', '[', ']', '(', ')', '|', '@' ];

        return str_replace(
            $characters,
            array_map(
                function ( $character ) {
                    return "[{$character}]";
                },
                $characters
            ),
            $string
        );
    }

    /**
     * Returns a closure ready to be called to flush selectively ignoring unflushable groups.
     *
     * @param   string $salt  The salt to be used to differentiate.
     * @param   bool $escape ...
     * @return  callable      Generated callable executing the lua script.
     */
    protected function lua_flush_closure( $salt, $escape = true ) {
        $salt = $escape ? $this->glob_quote( $salt ) : $salt;

        return function () use ( $salt ) {
            $script = implode(
                "\n",
                array(
                    'local cur = 0',
                    'local i = 0',
                    'local tmp',
                    'repeat',
                    "    tmp = redis.call('SCAN', cur, 'MATCH', '{$salt}*')",
                    '    cur = tonumber(tmp[1])',
                    '    if tmp[2] then',
                    '        for _, v in pairs(tmp[2]) do',
                    "            redis.call('del', v)",
                    '            i = i + 1',
                    '        end',
                    '    end',
                    'until 0 == cur',
                    'return i',
                )
            );

            if ( isset($this->redis_version) && version_compare( $this->redis_version, '5', '<' ) && version_compare( $this->redis_version, '3.2', '>=' ) ) {
                $script = 'redis.replicate_commands()' . "\n" . $script;
            }

            $args = $this->is_predis() ? [ $script, 0 ] : [ $script ];

            return call_user_func_array( [ $this->redis, 'eval' ], $args );
        };
    }

    /**
     * Returns a closure ready to be called to flush selectively.
     *
     * @param   string $salt  The salt to be used to differentiate.
     * @return  callable      Generated callable executing the lua script.
     */
    protected function lua_flush_extended_closure( $salt ) {
        $salt = $this->glob_quote( $salt );

        return function () use ( $salt ) {
            $salt_length = strlen( $salt );

            $unflushable = array_map(
                function ( $group ) {
                    return ":{$group}:";
                },
                $this->unflushable_groups
            );

            $salt_len = (int) $salt_length;
            $script    = implode(
                "\n",
                array(
                    'local cur = 0',
                    'local i = 0',
                    'local d, tmp',
                    'repeat',
                    "    tmp = redis.call('SCAN', cur, 'MATCH', '{$salt}*')",
                    '    cur = tonumber(tmp[1])',
                    '    if tmp[2] then',
                    '        for _, v in pairs(tmp[2]) do',
                    '            d = true',
                    '            for _, s in pairs(KEYS) do',
                    "                d = d and not v:find(s, {$salt_len})",
                    '                if not d then break end',
                    '            end',
                    '            if d then',
                    "                redis.call('del', v)",
                    '                i = i + 1',
                    '            end',
                    '        end',
                    '    end',
                    'until 0 == cur',
                    'return i',
                )
            );
            if ( isset($this->redis_version) && version_compare( $this->redis_version, '5', '<' ) && version_compare( $this->redis_version, '3.2', '>=' ) ) {
                $script = 'redis.replicate_commands()' . "\n" . $script;
            }

            $args = $this->is_predis()
                ? array_merge( [ $script, count( $unflushable ) ], $unflushable )
                : [ $script, $unflushable, count( $unflushable ) ];

            return call_user_func_array( [ $this->redis, 'eval' ], $args );
        };
    }

    /**
     * Retrieve object from cache.
     *
     * Gets an object from cache based on $key and $group.
     *
     * @param   string $key        The key under which to store the value.
     * @param   string $group      The group value appended to the $key.
     * @param   bool   $force      Optional. Whether to force a refetch rather than relying on the local
     *                             cache. Default false.
     * @param   bool   $found      Optional. Whether the key was found in the cache. Disambiguates a return of
     *                             false, a storable value. Passed by reference. Default null.
     * @return  bool|mixed         Cached object value.
     */
    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );
        $derived_key = $this->fast_build_key( $san_key, $san_group );

        if ( array_key_exists( $derived_key, $this->cache ) && ! $force ) {
            $found = true;
            $this->cache_hits++;
            $value = $this->get_from_internal_cache( $derived_key );

            return $value;
        } elseif ( $this->is_ignored_group( $group ) || ! $this->redis_status() ) {
            $found = false;
            $this->cache_misses++;

            return false;
        }

        $start_time = microtime( true );

        try {
            $result = $this->redis->get( $derived_key );
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return false;
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls++;
        $this->cache_time += $execute_time;

        if ( $result === null || $result === false ) {
            $found = false;
            $this->cache_misses++;

            return false;
        } else {
            $found = true;
            $this->cache_hits++;
            $value = $this->maybe_unserialize( $result );
        }

        $this->add_to_internal_cache( $derived_key, $value );

        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires on every cache get request
             *
             * @since 1.2.2
             * @param mixed  $value        Value of the cache entry.
             * @param string $key          The cache key.
             * @param string $group        The group value appended to the $key.
             * @param bool   $force        Whether a forced refetch has taken place rather than relying on the local cache.
             * @param bool   $found        Whether the key was found in the cache.
             * @param float  $execute_time Execution time for the request in seconds.
             */
            do_action( 'pigcache_object_cache_get', $key, $value, $group, $force, $found, $execute_time );
        }

        if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) ) {
            if ( has_filter( 'pigcache_object_cache_get_value' ) ) {
                /**
                 * Filters the return value
                 *
                 * @since 1.4.2
                 * @param mixed  $value Value of the cache entry.
                 * @param string $key   The cache key.
                 * @param string $group The group value appended to the $key.
                 * @param bool   $force Whether a forced refetch has taken place rather than relying on the local cache.
                 * @param bool   $found Whether the key was found in the cache.
                 */
                return apply_filters( 'pigcache_object_cache_get_value', $value, $key, $group, $force, $found );
            }
        }

        return $value;
    }

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param array  $keys  Array of keys under which the cache contents are stored.
     * @param string $group Optional. Where the cache contents are grouped. Default empty.
     * @param bool   $force Optional. Whether to force an update of the local cache
     *                      from the persistent cache. Default false.
     * @return array|false Array of values organized into groups.
     */
    public function get_multiple( $keys, $group = 'default', $force = false ) {
        if ( ! is_array( $keys ) ) {
            return false;
        }

        $cache = [];
        $derived_keys = [];
        $start_time = microtime( true );

        $san_group = $this->sanitize_key_part( $group );

        foreach ( $keys as $key ) {
            $san_key = $this->sanitize_key_part( $key );
            $derived_keys[ $key ] = $this->fast_build_key( $san_key, $san_group );
        }

        if ( $this->is_ignored_group( $group ) || ! $this->redis_status() ) {
            foreach ( $keys as $key ) {
                $value = $this->get_from_internal_cache( $derived_keys[ $key ] );
                $cache[ $key ] = $value;

                if ($value === false) {
                    $this->cache_misses++;
                } else {
                    $this->cache_hits++;
                }
            }

            return $cache;
        }

        if ( ! $force ) {
            foreach ( $keys as $key ) {
                $value = $this->get_from_internal_cache( $derived_keys[ $key ] );

                if ( $value === false ) {
                    $this->cache_misses++;

                } else {
                    $cache[ $key ] = $value;
                    $this->cache_hits++;
                }
            }
        }

        $remaining_keys = array_filter(
            $keys,
            function ( $key ) use ( $cache ) {
                return ! array_key_exists( $key, $cache );
            }
        );

        if ( empty( $remaining_keys ) ) {
            return $cache;
        }

        $start_time = microtime( true );
        $results = [];

        $remaining_ids = array_map(
            function ( $key ) use ( $derived_keys ) {
                return $derived_keys[ $key ];
            },
            $remaining_keys
        );

        try {
            $results = array_combine(
                $remaining_keys,
                $this->redis->mget( $remaining_ids )
                    ?: array_fill( 0, count( $remaining_ids ), false )
            );
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            $results = array_combine(
                $remaining_keys,
                array_fill( 0, count( $remaining_ids ), false )
            );
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls++;
        $this->cache_time += $execute_time;

        foreach ( $results as $key => $value ) {
            if ( $value === null || $value === false ) {
                $cache[ $key ] = false;
                $this->cache_misses++;
            } else {
                $cache[ $key ] = $this->maybe_unserialize( $value );
                $this->add_to_internal_cache( $derived_keys[ $key ], $cache[ $key ] );
                $this->cache_hits++;
            }
        }

        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires on every cache get multiple request
             *
             * @since 2.0.6
             * @param array  $keys         Array of keys under which the cache contents are stored.
             * @param array  $cache        Cache items.
             * @param string $group        The group value appended to the $key.
             * @param bool   $force        Whether a forced refetch has taken place rather than relying on the local cache.
             * @param float  $execute_time Execution time for the request in seconds.
             */
            do_action( 'pigcache_object_cache_get_multiple', $keys, $cache, $group, $force, $execute_time );
        }

        if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) ) {
            if ( has_filter( 'pigcache_object_cache_get_value' ) ) {
                foreach ( $cache as $key => $value ) {
                    /**
                     * Filters the return value
                     *
                     * @since 1.4.2
                     * @param mixed  $value Value of the cache entry.
                     * @param string $key   The cache key.
                     * @param string $group The group value appended to the $key.
                     * @param bool   $force Whether a forced refetch has taken place rather than relying on the local cache.
                     */
                    $cache[ $key ] = apply_filters( 'pigcache_object_cache_get_value', $value, $key, $group, $force );
                }
            }
        }

        return $cache;
    }

    /**
     * Sets a value in cache.
     *
     * The value is set whether or not this key already exists in Redis.
     *
     * @param   string $key        The key under which to store the value.
     * @param   mixed  $value      The value to store.
     * @param   string $group      The group value appended to the $key.
     * @param   int    $expiration The expiration time, defaults to 0.
     * @return  bool               Returns TRUE on success or FALSE on failure.
     */
    public function set( $key, $value, $group = 'default', $expiration = 0 ) {
        $result = true;
        $start_time = microtime( true );

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        $derived_key = $this->fast_build_key( $san_key, $san_group );

        // Save if group not excluded from redis and redis is up.
        if ( ! $this->is_ignored_group( $group ) && $this->redis_status() ) {
            $orig_exp = $expiration;
            $expiration = $this->validate_expiration( $expiration );

            /**
             * Filters the cache expiration time
             *
             * @since 1.4.2
             * @param int    $expiration The time in seconds the entry expires. 0 for no expiry.
             * @param string $key        The cache key.
             * @param string $group      The cache group.
             * @param mixed  $orig_exp   The original expiration value before validation.
             */
            $expiration = apply_filters( 'pigcache_cache_expiration', $expiration, $key, $group, $orig_exp );

            try {
                if ( $expiration ) {
                    $result = $this->parse_redis_response( $this->redis->setex( $derived_key, $expiration, $this->maybe_serialize( $value ) ) );
                } else {
                    $result = $this->parse_redis_response( $this->redis->set( $derived_key, $this->maybe_serialize( $value ) ) );
                }
            } catch ( Exception $exception ) {
                $this->handle_exception( $exception );

                return false;
            }

            $execute_time = microtime( true ) - $start_time;
            $this->cache_calls++;
            $this->cache_time += $execute_time;
        }

        // If the set was successful, or we didn't go to redis.
        if ( $result ) {
            $this->add_to_internal_cache( $derived_key, $value );
        }

        if ( function_exists( 'do_action' ) ) {
            $execute_time = microtime( true ) - $start_time;

            /**
             * Fires on every cache set
             *
             * @since 1.2.2
             * @param string $key          The cache key.
             * @param mixed  $value        Value of the cache entry.
             * @param string $group        The group value appended to the $key.
             * @param int    $expiration   The time in seconds the entry expires. 0 for no expiry.
             * @param float  $execute_time Execution time for the request in seconds.
             */
            do_action( 'pigcache_object_cache_set', $key, $value, $group, $expiration, $execute_time );
        }

        return $result;
    }

    /**
     * Sets multiple values to the cache in one call.
     *
     * @param array  $data   Array of key and value to be set.
     * @param string $group  Optional. Where the cache contents are grouped.
     * @param int    $expire Optional. When to expire the cache contents, in seconds.
     *                       Default 0 (no expiration).
     * @return bool[] Array of return values, grouped by key. Each value is always true.
     */
    public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
        if (
            $this->redis_status() &&
            method_exists( $this->redis, 'pipeline' ) &&
            ! $this->is_ignored_group( $group )
        ) {
            return $this->set_multiple_at_once( $data, $group, $expire );
        }

        $values = [];

        foreach ( $data as $key => $value ) {
            $values[ $key ] = $this->set( $key, $value, $group, $expire );
        }

        return $values;
    }

    /**
     * Sets multiple values to the cache in one call.
     *
     * @param array  $data       Array of key and value to be set.
     * @param string $group      Optional. Where the cache contents are grouped.
     * @param int    $expiration Optional. When to expire the cache contents, in seconds.
     *                           Default 0 (no expiration).
     * @return bool[] Array of return values, grouped by key. Each value is always true.
     */
    protected function set_multiple_at_once( array $data, $group = 'default', $expiration = 0 ) {
        $start_time = microtime( true );

        $san_group = $this->sanitize_key_part( $group );
        $derived_keys = [];

        $orig_exp = $expiration;
        $expiration = $this->validate_expiration( $expiration );
        $expirations = [];

        $tx = $this->redis->pipeline();
        $keys = array_keys( $data );

        foreach ( $data as $key => $value ) {
            $san_key = $this->sanitize_key_part( $key );
            $derived_key = $derived_keys[ $key ] = $this->fast_build_key( $san_key, $san_group );

            /**
             * Filters the cache expiration time
             *
             * @param int    $expiration The time in seconds the entry expires. 0 for no expiry.
             * @param string $key        The cache key.
             * @param string $group      The cache group.
             * @param mixed  $orig_exp   The original expiration value before validation.
             */
            $expiration = $expirations[ $key ] = apply_filters( 'pigcache_cache_expiration', $expiration, $key, $group, $orig_exp );

            if ( $expiration ) {
                $tx->setex( $derived_key, $expiration, $this->maybe_serialize( $value ) );
            } else {
                $tx->set( $derived_key, $this->maybe_serialize( $value ) );
            }
        }

        try {
            $method = $this->is_predis() ? 'execute' : 'exec';

            $results = array_map( function ( $response ) {
                return (bool) $this->parse_redis_response( $response );
            }, $tx->{$method}() ?: [] );

            if ( count( $results ) !== count( $keys ) ) {
                $tx->discard();

                return array_fill_keys( $keys, false );
            }

            $results = array_combine( $keys, $results );

            foreach ( $results as $key => $result ) {
                if ( $result ) {
                    $this->add_to_internal_cache( $derived_keys[ $key ], $data[ $key ] );
                }
            }
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return array_combine( $keys, array_fill( 0, count( $keys ), false ) );
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls++;
        $this->cache_time += $execute_time;

        if ( function_exists( 'do_action' ) ) {
            foreach ( $data as $key => $value ) {
                /**
                 * Fires on every cache set
                 *
                 * @param string $key          The cache key.
                 * @param mixed  $value        Value of the cache entry.
                 * @param string $group        The group value appended to the $key.
                 * @param int    $expiration   The time in seconds the entry expires. 0 for no expiry.
                 * @param float  $execute_time Execution time for the request in seconds.
                 */
                do_action( 'pigcache_object_cache_set', $key, $value, $group, $expirations[ $key ], $execute_time );
            }
        }

        return $results;
    }

    /**
     * Increment a Redis counter by the amount specified
     *
     * @param  string $key    The key name.
     * @param  int    $offset Optional. The increment. Defaults to 1.
     * @param  string $group  Optional. The key group. Default is 'default'.
     * @return int|bool
     */
    public function increment( $key, $offset = 1, $group = 'default' ) {
        $offset = (int) $offset;
        $start_time = microtime( true );

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        $derived_key = $this->fast_build_key( $san_key, $san_group );

        // If group is a non-Redis group, save to internal cache, not Redis.
        if ( $this->is_ignored_group( $group ) || ! $this->redis_status() ) {
            $value = $this->get_from_internal_cache( $derived_key );
            $value += $offset;
            $this->add_to_internal_cache( $derived_key, $value );

            return $value;
        }

        try {
            if ( $this->use_igbinary ) {
                $value = (int) $this->parse_redis_response( $this->maybe_unserialize( $this->redis->get( $derived_key ) ) );
                $value += $offset;
                $serialized = $this->maybe_serialize( $value );

                if ( ($pttl = $this->redis->pttl( $derived_key )) > 0 ) {
                    if ( $this->is_predis() ) {
                        $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized, 'px', $pttl ) );
                    } else {
                        $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized, [ 'px' => $pttl ] ) );
                    }
                } else {
                    $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized ) );
                }

                if ( $result ) {
                    $this->add_to_internal_cache( $derived_key, $value );
                    $result = $value;
                }
            } else {
                $result = $this->parse_redis_response( $this->redis->incrBy( $derived_key, $offset ) );
                $this->add_to_internal_cache( $derived_key, (int) $this->redis->get( $derived_key ) );
            }
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return false;
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls += 2;
        $this->cache_time += $execute_time;

        return $result;
    }

    /**
     * Alias of `increment()`.
     *
     * @see self::increment()
     * @param  string $key    The key name.
     * @param  int    $offset Optional. The increment. Defaults to 1.
     * @param  string $group  Optional. The key group. Default is 'default'.
     * @return int|bool
     */
    public function incr( $key, $offset = 1, $group = 'default' ) {
        return $this->increment( $key, $offset, $group );
    }

    /**
     * Decrement a Redis counter by the amount specified
     *
     * @param  string $key    The key name.
     * @param  int    $offset Optional. The decrement. Defaults to 1.
     * @param  string $group  Optional. The key group. Default is 'default'.
     * @return int|bool
     */
    public function decrement( $key, $offset = 1, $group = 'default' ) {
        $offset = (int) $offset;
        $start_time = microtime( true );

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        $derived_key = $this->fast_build_key( $san_key, $san_group );

        // If group is a non-Redis group, save to internal cache, not Redis.
        if ( $this->is_ignored_group( $group ) || ! $this->redis_status() ) {
            $value = $this->get_from_internal_cache( $derived_key );
            $value -= $offset;
            $this->add_to_internal_cache( $derived_key, $value );

            return $value;
        }

        try {
            if ( $this->use_igbinary ) {
                $value = (int) $this->parse_redis_response( $this->maybe_unserialize( $this->redis->get( $derived_key ) ) );
                $value -= $offset;
                $serialized = $this->maybe_serialize( $value );

                if ( ($pttl = $this->redis->pttl( $derived_key )) > 0 ) {
                    if ( $this->is_predis() ) {
                        $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized, 'px', $pttl ) );
                    } else {
                        $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized, [ 'px' => $pttl ] ) );
                    }
                } else {
                    $result = $this->parse_redis_response( $this->redis->set( $derived_key, $serialized ) );
                }

                if ( $result ) {
                    $this->add_to_internal_cache( $derived_key, $value );
                    $result = $value;
                }
            } else {
                $result = $this->parse_redis_response( $this->redis->decrBy( $derived_key, $offset ) );
                $this->add_to_internal_cache( $derived_key, (int) $this->redis->get( $derived_key ) );
            }
        } catch ( Exception $exception ) {
            $this->handle_exception( $exception );

            return false;
        }

        $execute_time = microtime( true ) - $start_time;

        $this->cache_calls += 2;
        $this->cache_time += $execute_time;

        return $result;
    }

    /**
     * Alias of `decrement()`.
     *
     * @see self::decrement()
     * @param  string $key    The key name.
     * @param  int    $offset Optional. The decrement. Defaults to 1.
     * @param  string $group  Optional. The key group. Default is 'default'.
     * @return int|bool
     */
    public function decr( $key, $offset = 1, $group = 'default' ) {
        return $this->decrement( $key, $offset, $group );
    }

    /**
     * Render data about current cache requests
     * Used by the Debug bar plugin
     *
     * @return void
     */
    public function stats() {
    ?>
        <p>
            <strong>Redis Status:</strong>
            <?php echo esc_html( $this->redis_status() ? 'Connected' : 'Not connected' ); ?>
            <br />
            <strong>Redis Client:</strong>
            <?php echo esc_html( $this->diagnostics['client'] ?: 'Unknown' ); ?>
            <br />
            <strong>Cache Hits:</strong>
            <?php echo (int) $this->cache_hits; ?>
            <br />
            <strong>Cache Misses:</strong>
            <?php echo (int) $this->cache_misses; ?>
            <br />
            <strong>Cache Size:</strong>
            <?php echo esc_html( number_format_i18n( strlen( serialize( $this->cache ) ) / 1024, 2 ) ); ?> KB
        </p>
    <?php
    }

    /**
     * Returns various information about the object cache.
     *
     * @return object
     */
    public function info() {
        $total = $this->cache_hits + $this->cache_misses;

        $bytes = array_map(
            function ( $keys ) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
                return strlen( serialize( $keys ) );
            },
            $this->cache
        );

        return (object) [
            'hits' => $this->cache_hits,
            'misses' => $this->cache_misses,
            'ratio' => $total > 0 ? round( $this->cache_hits / ( $total / 100 ), 1 ) : 100,
            'bytes' => array_sum( $bytes ),
            'time' => $this->cache_time,
            'calls' => $this->cache_calls,
            'groups' => (object) [
                'global' => $this->global_groups,
                'non_persistent' => $this->ignored_groups,
                'unflushable' => $this->unflushable_groups,
            ],
            'errors' => empty( $this->errors ) ? null : $this->errors,
            'meta' => [
                'Client' => $this->diagnostics['client'] ?? 'Unknown',
                'Redis Version' => $this->redis_version,
            ],
        ];
    }

    /**
     * Builds a key for the cached object using the prefix, group and key.
     *
     * @param   string $key        The key under which to store the value, pre-sanitized.
     * @param   string $group      The group value appended to the $key, pre-sanitized.
     * @return  string
     */
    public function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $san_key = $this->sanitize_key_part( $key );
        $san_group = $this->sanitize_key_part( $group );

        return $this->fast_build_key($san_key, $san_group);
    }

    /**
     * Builds a key for the cached object using the prefix, group and key.
     *
     * @param   string $key        The key under which to store the value, pre-sanitized.
     * @param   string $group      The group value appended to the $key, pre-sanitized.
     * @return  string
     */
    public function fast_build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $salt = defined( 'PIGCACHE_REDIS_PREFIX' ) ? trim( PIGCACHE_REDIS_PREFIX ) : '';

        $prefix = $this->is_global_group( $group ) ? $this->global_prefix : $this->blog_prefix;
        $prefix = trim( (string) $prefix, '_-:$' );

        return "{$salt}{$prefix}:{$group}:{$key}";
    }

    /**
     * Replaces the set group separator by another one
     *
     * @param  string $part  The string to sanitize.
     * @return string        Sanitized string.
     */
    protected function sanitize_key_part( $part ) {
        return is_string( $part ) ? str_replace( ':', '-', $part ) : $part;
    }

    /**
     * Checks if the given group is part the ignored group array
     *
     * @param string $group  Name of the group to check, pre-sanitized.
     * @return bool
     */
    protected function is_ignored_group( $group ) {
        return $this->is_group_of_type( $group, 'ignored' );
    }

    /**
     * Checks if the given group is part the global group array
     *
     * @param string $group  Name of the group to check, pre-sanitized.
     * @return bool
     */
    protected function is_global_group( $group ) {
        return $this->is_group_of_type( $group, 'global' );
    }

    /**
     * Checks if the given group is part the unflushable group array
     *
     * @param string $group  Name of the group to check, pre-sanitized.
     * @return bool
     */
    protected function is_unflushable_group( $group ) {
        return $this->is_group_of_type( $group, 'unflushable' );
    }

    /**
     * Checks the type of the given group
     *
     * @param string $group  Name of the group to check, pre-sanitized.
     * @param string $type   Type of the group to check.
     * @return bool
     */
    private function is_group_of_type( $group, $type ) {
        return isset( $this->group_type[ $group ] )
            && $this->group_type[ $group ] == $type;
    }

    /**
     * Convert Redis responses into something meaningful
     *
     * @param mixed $response Response sent from the redis instance.
     * @return mixed
     */
    protected function parse_redis_response( $response ) {
        if ( is_bool( $response ) ) {
            return $response;
        }

        if ( is_numeric( $response ) ) {
            return $response;
        }

        if ( is_object( $response ) && method_exists( $response, 'getPayload' ) ) {
            return $response->getPayload() === 'OK';
        }

        return false;
    }

    /**
     * Simple wrapper for saving object to the internal cache.
     *
     * @param   string $derived_key    Key to save value under.
     * @param   mixed  $value          Object value.
     */
    public function add_to_internal_cache( $derived_key, $value ) {
        if ( is_object( $value ) ) {
            $value = clone $value;
        }

        $this->cache[ $derived_key ] = $value;
    }

    /**
     * Get a value specifically from the internal, run-time cache, not Redis.
     *
     * @param   int|string $derived_key Key value.
     *
     * @return  bool|mixed              Value on success; false on failure.
     */
    public function get_from_internal_cache( $derived_key ) {
        if ( ! array_key_exists( $derived_key, $this->cache ) ) {
            return false;
        }

        if ( is_object( $this->cache[ $derived_key ] ) ) {
            return clone $this->cache[ $derived_key ];
        }

        return $this->cache[ $derived_key ];
    }

    /**
     * In multisite, switch blog prefix when switching blogs
     *
     * @param int $_blog_id Blog ID.
     * @return bool
     */
    public function switch_to_blog( $_blog_id ) {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
            return false;
        }

        $this->blog_prefix = (int) $_blog_id;

        return true;
    }

    /**
     * Sets the list of global groups.
     *
     * @param array $groups List of groups that are global.
     */
    public function add_global_groups( $groups ) {
        $groups = (array) $groups;

        if ( $this->redis_status() ) {
            $this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
        } else {
            $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $groups ) );
        }

        $this->cache_group_types();
    }

    /**
     * Sets the list of groups not to be cached by Redis.
     *
     * @param array $groups  List of groups that are to be ignored.
     */
    public function add_non_persistent_groups( $groups ) {
        /**
         * Filters list of groups to be added to {@see self::$ignored_groups}
         *
         * @since 2.1.7
         * @param string[] $groups List of groups to be ignored.
         */
        $groups = apply_filters( 'pigcache_cache_add_non_persistent_groups', (array) $groups );

        $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $groups ) );
        $this->cache_group_types();
    }

    /**
     * Sets the list of groups not to flushed cached.
     *
     * @param array $groups List of groups that are unflushable.
     */
    public function add_unflushable_groups( $groups ) {
        $groups = (array) $groups;

        $this->unflushable_groups = array_unique( array_merge( $this->unflushable_groups, $groups ) );
        $this->cache_group_types();
    }

    /**
     * Wrapper to validate the cache keys expiration value
     *
     * @param mixed $expiration  Incoming expiration value (whatever it is).
     */
    protected function validate_expiration( $expiration ) {
        $expiration = is_int( $expiration ) || ctype_digit( (string) $expiration ) ? (int) $expiration : 0;

        // PIGCACHE_DEAD_CODE (Tier 2 — undocumented but harmless if kept):
        // PIGCACHE_REDIS_MAXTTL caps every TTL. Never documented, no external
        // refs. Borderline useful as a safety belt — delete only if you don't
        // want to ever advertise it.
        if ( defined( 'PIGCACHE_REDIS_MAXTTL' ) ) {
            $max = (int) PIGCACHE_REDIS_MAXTTL;

            if ( $expiration === 0 || $expiration > $max ) {
                $expiration = $max;
            }
        }

        return $expiration;
    }

    /**
     * Unserialize value only if it was serialized.
     *
     * @param string $original  Maybe unserialized original, if is needed.
     * @return mixed            Unserialized data can be any type.
     */
    protected function maybe_unserialize( $original ) {
        if ( $this->use_igbinary ) {
            return igbinary_unserialize( $original );
        }

        // Don't attempt to unserialize data that wasn't serialized going in.
        if ( $this->is_serialized( $original ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            $value = @unserialize( $original );

            return is_object( $value ) ? clone $value : $value;
        }

        return $original;
    }

    /**
     * Serialize data, if needed.
     *
     * @param mixed $data  Data that might be serialized.
     * @return mixed       A scalar data
     */
    protected function maybe_serialize( $data ) {
        if ( is_object( $data ) ) {
            $data = clone $data;
        }

        if ( $this->use_igbinary ) {
            return igbinary_serialize( $data );
        }

        if ( is_array( $data ) || is_object( $data ) ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            return serialize( $data );
        }

        if ( $this->is_serialized( $data, false ) ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            return serialize( $data );
        }

        return $data;
    }

    /**
     * Check value to find if it was serialized.
     *
     * If $data is not an string, then returned value will always be false.
     * Serialized data is always a string.
     *
     * @param string $data    Value to check to see if was serialized.
     * @param bool   $strict  Optional. Whether to be strict about the end of the string. Default true.
     * @return bool           False if not serialized and true if it was.
     */
    protected function is_serialized( $data, $strict = true ) {
        // if it isn't a string, it isn't serialized.
        if ( ! is_string( $data ) ) {
            return false;
        }

        $data = trim( $data );

        if ( 'N;' === $data ) {
            return true;
        }

        if ( strlen( $data ) < 4 ) {
            return false;
        }

        if ( ':' !== $data[1] ) {
            return false;
        }

        if ( $strict ) {
            $lastc = substr( $data, -1 );

            if ( ';' !== $lastc && '}' !== $lastc ) {
                return false;
            }
        } else {
            $semicolon = strpos( $data, ';' );
            $brace = strpos( $data, '}' );

            // Either ; or } must exist.
            if ( false === $semicolon && false === $brace ) {
                return false;
            }

            // But neither must be in the first X characters.
            if ( false !== $semicolon && $semicolon < 3 ) {
                return false;
            }

            if ( false !== $brace && $brace < 4 ) {
                return false;
            }
        }
        $token = $data[0];

        switch ( $token ) {
            case 's':
                if ( $strict ) {
                    if ( '"' !== substr( $data, -2, 1 ) ) {
                        return false;
                    }
                } elseif ( false === strpos( $data, '"' ) ) {
                    return false;
                }
                // Or else fall through.
                // No break!
            case 'a':
            case 'O':
                return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';

                return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
        }

        return false;
    }

    /**
     * Handle the redis failure gracefully or throw an exception.
     *
     * @param \Exception $exception  Exception thrown.
     * @throws \Exception If `fail_gracefully` flag is set to a falsy value.
     * @return void
     */

    // ── Circuit breaker ──────────────────────────────────────────────────────
    // After a Redis failure the circuit is "open" for PIGCACHE_REDIS_RETRY_INTERVAL
    // seconds (default 30). During that window every request skips the connection
    // attempt entirely instead of blocking for the full read_timeout duration.
    // After the interval a single probe request attempts to reconnect; if it
    // succeeds the circuit closes automatically.

    /**
     * Returns the path of the circuit-breaker flag file for this Redis endpoint.
     *
     * sys_get_temp_dir() is intentional and the only viable location for this file.
     * The circuit breaker must work when Redis is DOWN and WordPress may not be fully
     * loaded — which means:
     *   • wp_upload_dir() is unavailable (calls get_option(), which hits the object cache
     *     we are currently initialising — circular dependency).
     *   • WP_CONTENT_DIR/cache/ is not guaranteed to exist or be writable at this point.
     *   • The WordPress DB transient API is unavailable for the same reason.
     *   • The plugin folder (PIGCACHE_DIR) is not a writable runtime-data location
     *     and would violate WP.org guidelines.
     * The system temp directory is the standard PHP location for ephemeral flag files;
     * it does not persist across reboots (correct behaviour — a stale flag after a
     * server restart should clear so Redis gets a fresh connection attempt).
     *
     * @return string
     */
    private function pigcache_circuit_path() {
        $host = defined( 'PIGCACHE_REDIS_HOST' ) ? PIGCACHE_REDIS_HOST : '127.0.0.1';
        $port = defined( 'PIGCACHE_REDIS_PORT' ) ? (string) PIGCACHE_REDIS_PORT : '6379';
        return sys_get_temp_dir() . '/pigcache_cb_' . md5( $host . ':' . $port ) . '.flag';
    }

    /**
     * Returns true when the circuit is open (Redis marked as down).
     *
     * @return bool
     */
    private function pigcache_circuit_is_open() {
        $path = $this->pigcache_circuit_path();
        if ( ! file_exists( $path ) ) {
            return false;
        }

        $ts  = (int) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $ttl = defined( 'PIGCACHE_REDIS_RETRY_INTERVAL' ) ? (int) PIGCACHE_REDIS_RETRY_INTERVAL : 30;

        if ( time() - $ts < $ttl ) {
            return true;
        }

        // Interval elapsed — delete flag and let this request probe Redis.
        @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return false;
    }

    /**
     * Opens the circuit breaker (writes the flag file with the current timestamp).
     *
     * @return void
     */
    private function pigcache_open_circuit() {
        @file_put_contents( $this->pigcache_circuit_path(), (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    // ────────────────────────────────────────────────────────────────────────

    protected function handle_exception( $exception ) {
        $this->redis_connected = false;

        // When Redis is unavailable, fall back to the internal cache by forcing all groups to be "no redis" groups.
        $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $this->global_groups ) );

        // Open the circuit breaker so subsequent requests skip the connection
        // attempt (and its timeout cost) for PIGCACHE_REDIS_RETRY_INTERVAL seconds.
        $this->pigcache_open_circuit();

        error_log( $exception ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires when an object cache related error occurs.
             *
             * @since 1.5.0
             * @param \Exception $exception The exception.
             * @param string     $message   The exception message.
             */
            do_action( 'pigcache_object_cache_error', $exception, $exception->getMessage() );
        }

        // PIGCACHE_DEAD_CODE: fail_gracefully=false branch only fires the death
        // screen below. PigCache's wp_cache_init() always constructs with
        // graceful=true (default PIGCACHE_REDIS_GRACEFUL=true). Once you delete
        // show_error_and_die() you can also delete this if-block AND the
        // $fail_gracefully constructor argument + property (~5 lines saved).
        if ( ! $this->fail_gracefully ) {
            $this->show_error_and_die( $exception );
        }

        $this->errors[] = $exception->getMessage();
    }

    /**
     * Show Redis connection error screen, or load custom `/redis-error.php`.
     *
     * @return void
     */
    // PIGCACHE_DEAD_CODE: show_error_and_die() is the full Till Kruss "Error
    // establishing a Redis connection" white-screen-of-death (~60 lines). Only
    // reachable when fail_gracefully=false, which PigCache never sets. The
    // whole method including i18n / wp_load_translations_early / verbose
    // error HTML can be deleted. Method ends at the `}` before
    // `protected function build_cluster_connection_array()`.
    protected function show_error_and_die( Exception $exception ) {
        wp_load_translations_early();

        $domain = 'pigcache';
        $locale = defined( 'WPLANG' ) ? WPLANG : 'en_US';
        // WP_LANG_DIR is the correct WordPress constant for plugin .mo files — no alternative API exists.
        $mofile = WP_LANG_DIR . "/plugins/{$domain}-{$locale}.mo";

        if ( load_textdomain( $domain, $mofile, $locale ) === false ) {
            add_filter( 'pre_determine_locale', function () {
                return defined( 'WPLANG' ) ? WPLANG : 'en_US';
            } );

            add_filter( 'pre_get_language_files_from_path', '__return_empty_array' );
        }

        // Load custom Redis error template, if present.
        if ( file_exists( WP_CONTENT_DIR . '/redis-error.php' ) ) {
            require_once WP_CONTENT_DIR . '/redis-error.php';
            die();
        }

        $verbose = wp_installing()
            || defined( 'WP_ADMIN' )
            || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

            $message = '<h1>' . __( 'Error establishing a Redis connection', 'pigcache' ) . "</h1>\n";

        if ( $verbose ) {
            $message .= "<p><code>" . $exception->getMessage() . "</code></p>\n";

            $message .= '<p>' . sprintf(
                // translators: %s = Formatted wp-config.php file name.
                __( 'WordPress is unable to establish a connection to Redis. This means that the connection information in your %s file are incorrect, or that the Redis server is not reachable.', 'pigcache' ),
                '<code>wp-config.php</code>'
            ) . "</p>\n";

            $message .= "<ul>\n";
            $message .= '<li>' . __( 'Is the correct Redis host and port set?', 'pigcache' ) . "</li>\n";
            $message .= '<li>' . __( 'Is the Redis server running?', 'pigcache' ) . "</li>\n";
            $message .= "</ul>\n";

            $message .= '<p>' . sprintf(
                // translators: %s = Link to installation instructions.
                __( 'If you need help, please read the <a href="%s">installation instructions</a>.', 'pigcache' ),
                'https://github.com/rhubarbgroup/redis-cache/wiki/Installation'
            ) . "</p>\n";
        }

        $message .= '<p>' . sprintf(
            // translators: %1$s = Formatted object-cache.php file name, %2$s = Formatted wp-content directory name.
            __( 'To disable Redis, delete the %1$s file in the %2$s directory.', 'pigcache' ),
            '<code>object-cache.php</code>',
            '<code>/wp-content/</code>'
        ) . "</p>\n";

        // phpcs:disable WordPress.Security.EscapeOutput
        wp_die( $message );
        // phpcs:enable
    }

    /**
     * Builds a clean connection array out of redis clusters array.
     *
     * @return  array
     */
    // PIGCACHE_DEAD_CODE: build_cluster_connection_array() — only called from
    // dead Cluster branches in __construct, connect_using_phpredis,
    // connect_using_predis, fetch_info, execute_lua_script_on_cluster.
    // Delete this whole method (~40 lines) once those branches are removed.
    protected function build_cluster_connection_array() {
        $cluster = array_values( PIGCACHE_REDIS_CLUSTER );

        foreach ( $cluster as $key => $server ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
            $components = parse_url( $server );

            if ( ! empty( $components['scheme'] ) ) {
                $scheme = $components['scheme'];
            } elseif ( defined( 'PIGCACHE_REDIS_SCHEME' ) ) {
                $scheme = PIGCACHE_REDIS_SCHEME;
            } else {
                $scheme = null;
            }

            if ( isset( $scheme ) ) {
                $cluster[ $key ] = sprintf(
                    '%s://%s:%d',
                    $scheme,
                    $components['host'],
                    $components['port']
                );
            } else {
                $cluster[ $key ] = sprintf(
                    '%s:%d',
                    $components['host'],
                    $components['port']
                );
            }
        }

        return $cluster;
    }

    /**
     * Check whether Predis client is in use.
     *
     * @return bool
     */
    protected function is_predis() {
        return $this->redis instanceof Predis\Client;
    }

    /**
     * Allows access to private properties for backwards compatibility.
     *
     * @param string $name Name of the property.
     * @return mixed
     */
    public function __get( $name ) {
        return isset( $this->{$name} ) ? $this->{$name} : null;
    }
}

// PIGCACHE_DEAD_CODE: closing endif; for PIGCACHE_REDIS_DISABLED wrapper above.
// Remove together with the opening `if (...) :` near the top of this file.
endif;
// phpcs:enable Generic.WhiteSpace.ScopeIndent.IncorrectExact, Generic.WhiteSpace.ScopeIndent.Incorrect
