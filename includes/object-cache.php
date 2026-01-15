<?php
/**
 * Hazelcast Object Cache Drop-in
 *
 * A WordPress object cache drop-in that uses the PHP Memcached extension to connect
 * to a Hazelcast cluster via the Memcache protocol. This file should be placed in
 * wp-content/object-cache.php to replace WordPress's default object cache.
 *
 * Features:
 * - Persistent connections to Hazelcast cluster
 * - Circuit breaker pattern for fault tolerance
 * - Automatic fallback to in-memory cache on connection failure
 * - TLS/SSL support for secure connections
 * - SASL authentication support
 * - Group-based cache invalidation
 * - Multisite compatibility
 *
 * @package    Hazelcast_Object_Cache
 * @author     Starter Kit
 * @license    Apache-2.0
 * @link       https://hazelcast.com
 *
 * @requires   PHP 8.1+
 * @requires   Memcached extension
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
    return;
}

if ( ! class_exists( 'Memcached' ) ) {
    return;
}

/**
 * Hazelcast WordPress Object Cache
 *
 * Implements the WordPress object cache API using Hazelcast as the backend storage.
 * Uses the Memcached PHP extension to communicate with Hazelcast's Memcache protocol
 * interface. Includes a circuit breaker pattern for graceful degradation when the
 * Hazelcast cluster is unavailable.
 *
 * @since 1.0.0
 */
class Hazelcast_WP_Object_Cache {

    /**
     * Singleton instance of the cache object.
     *
     * @var Hazelcast_WP_Object_Cache|null
     */
    private static $instance;

    /**
     * Memcached client instance for Hazelcast communication.
     *
     * @var Memcached|null
     */
    private $memcached;

    /**
     * List of cache groups shared across all sites in multisite.
     *
     * @var array
     */
    private $global_groups = array();

    /**
     * List of cache groups that are not persisted to Hazelcast.
     *
     * @var array
     */
    private $non_persistent_groups = array();

    /**
     * Version numbers for each cache group, used for group invalidation.
     *
     * @var array
     */
    private $group_versions = array();

    /**
     * Local in-memory cache for the current request.
     *
     * @var array
     */
    private $cache = array();

    /**
     * Number of cache hits during this request.
     *
     * @var int
     */
    private $cache_hits = 0;

    /**
     * Number of cache misses during this request.
     *
     * @var int
     */
    private $cache_misses = 0;

    /**
     * Current blog ID prefix for multisite key namespacing.
     *
     * @var int
     */
    private $blog_prefix;

    /**
     * Whether debug logging is enabled.
     *
     * @var bool
     */
    private $debug = false;

    /**
     * Last error message from Memcached operations.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Whether the cache is operating in fallback (local-only) mode.
     *
     * @var bool
     */
    private $fallback_mode = false;

    /**
     * Seconds to wait before retrying connection after entering fallback mode.
     *
     * @var int
     */
    private $fallback_retry_interval = 30;

    /**
     * Timestamp of the last connection attempt to Hazelcast.
     *
     * @var int
     */
    private $last_connection_attempt = 0;

    /**
     * Count of consecutive connection failures (circuit breaker counter).
     *
     * @var int
     */
    private $connection_failures = 0;

    /**
     * Number of failures required to open the circuit breaker.
     *
     * @var int
     */
    private $circuit_breaker_threshold = 5;

    /**
     * Returns the singleton instance of the cache.
     *
     * Creates and initializes a new instance if one doesn't exist.
     * This ensures only one connection to Hazelcast is maintained per request.
     *
     * @return Hazelcast_WP_Object_Cache The singleton cache instance.
     */
    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        $this->debug     = defined( 'HAZELCAST_DEBUG' ) && HAZELCAST_DEBUG;
        $this->memcached = new Memcached( 'hazelcast_persistent' );

        // Hazelcast only supports ASCII Memcache protocol; disable binary mode.
        // Must be set outside the server-check block to apply to reused persistent connections.
        $this->memcached->setOption( Memcached::OPT_BINARY_PROTOCOL, false );

        global $blog_id;
        $this->blog_prefix = ( function_exists( 'is_multisite' ) && is_multisite() ) ? (int) $blog_id : 1;

        $servers  = defined( 'HAZELCAST_SERVERS' ) ? explode( ',', HAZELCAST_SERVERS ) : array( '127.0.0.1:5701' );
        $username = defined( 'HAZELCAST_USERNAME' ) ? HAZELCAST_USERNAME : null;
        $password = defined( 'HAZELCAST_PASSWORD' ) ? HAZELCAST_PASSWORD : null;

        if ( ! count( $this->memcached->getServerList() ) ) {
            $this->memcached->setOption( Memcached::OPT_COMPRESSION, defined( 'HAZELCAST_COMPRESSION' ) ? (bool) HAZELCAST_COMPRESSION : true );

            if ( defined( 'HAZELCAST_KEY_PREFIX' ) ) {
                $key_prefix = HAZELCAST_KEY_PREFIX;
            } elseif ( function_exists( 'site_url' ) ) {
                $key_prefix = 'wp_' . md5( site_url() ) . ':';
            } else {
                $key_prefix = 'wp_' . md5( DB_NAME . ABSPATH ) . ':';
            }
            $this->memcached->setOption( Memcached::OPT_PREFIX_KEY, $key_prefix );

            if ( defined( 'HAZELCAST_TIMEOUT' ) ) {
                $this->memcached->setOption( Memcached::OPT_CONNECT_TIMEOUT, (int) HAZELCAST_TIMEOUT * 1000 );
            }

            if ( defined( 'HAZELCAST_RETRY_TIMEOUT' ) ) {
                $this->memcached->setOption( Memcached::OPT_RETRY_TIMEOUT, (int) HAZELCAST_RETRY_TIMEOUT );
            }

            if ( defined( 'HAZELCAST_SERIALIZER' ) ) {
                $serializer = strtolower( HAZELCAST_SERIALIZER );
                if ( 'igbinary' === $serializer && defined( 'Memcached::SERIALIZER_IGBINARY' ) ) {
                    $this->memcached->setOption( Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY );
                } else {
                    $this->memcached->setOption( Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP );
                }
            }

            if ( defined( 'HAZELCAST_TCP_NODELAY' ) ) {
                $this->memcached->setOption( Memcached::OPT_TCP_NODELAY, (bool) HAZELCAST_TCP_NODELAY );
            }

            if ( defined( 'HAZELCAST_TLS_ENABLED' ) && HAZELCAST_TLS_ENABLED ) {
                if ( defined( 'Memcached::OPT_USE_TLS' ) ) {
                    $this->memcached->setOption( Memcached::OPT_USE_TLS, true );

                    $tls_context = array();

                    if ( defined( 'HAZELCAST_TLS_CERT_PATH' ) && file_exists( HAZELCAST_TLS_CERT_PATH ) ) {
                        $tls_context['local_cert'] = HAZELCAST_TLS_CERT_PATH;
                    }

                    if ( defined( 'HAZELCAST_TLS_KEY_PATH' ) && file_exists( HAZELCAST_TLS_KEY_PATH ) ) {
                        $tls_context['local_pk'] = HAZELCAST_TLS_KEY_PATH;
                    }

                    if ( defined( 'HAZELCAST_TLS_CA_PATH' ) && file_exists( HAZELCAST_TLS_CA_PATH ) ) {
                        $tls_context['cafile'] = HAZELCAST_TLS_CA_PATH;
                    }

                    if ( defined( 'HAZELCAST_TLS_VERIFY_PEER' ) ) {
                        $tls_context['verify_peer'] = (bool) HAZELCAST_TLS_VERIFY_PEER;
                    }

                    if ( ! empty( $tls_context ) && defined( 'Memcached::OPT_TLS_CONTEXT' ) ) {
                        $this->memcached->setOption( Memcached::OPT_TLS_CONTEXT, $tls_context );
                    }

                    $this->log( 'info', 'TLS enabled for Hazelcast connection' );
                } else {
                    $this->log( 'warning', 'TLS requested but Memcached extension does not support OPT_USE_TLS' );
                }
            }

            $server_list = array();
            foreach ( array_map( 'trim', $servers ) as $server ) {
                $parts         = explode( ':', $server );
                $host          = $parts[0];
                $port          = isset( $parts[1] ) ? (int) $parts[1] : 5701;
                $server_list[] = array( $host, $port );
            }
            $this->memcached->addServers( $server_list );

            if ( $username && $password ) {
                $this->memcached->setSaslAuthData( $username, $password );
            }

            $this->log( 'info', 'Initialized connection to servers: ' . implode( ', ', $servers ) );
        }

        $this->fallback_retry_interval = defined( 'HAZELCAST_FALLBACK_RETRY_INTERVAL' )
            ? (int) HAZELCAST_FALLBACK_RETRY_INTERVAL
            : 30;

        $this->circuit_breaker_threshold = defined( 'HAZELCAST_CIRCUIT_BREAKER_THRESHOLD' )
            ? (int) HAZELCAST_CIRCUIT_BREAKER_THRESHOLD
            : 5;

        if ( ! $this->is_connected() ) {
            $this->set_last_error();
            $this->log( 'error', 'Failed to connect to Hazelcast cluster' );
            $this->enter_fallback_mode();
        }
    }

    /**
     * Transitions the cache into fallback mode.
     *
     * Circuit Breaker State Machine - CLOSED -> OPEN transition:
     * When connection failures occur, this method increments the failure counter.
     * Once failures reach the threshold, the circuit "opens" and subsequent
     * operations use only the local in-memory cache until the retry interval elapses.
     *
     * @return void
     */
    private function enter_fallback_mode() {
        // Increment failure counter for circuit breaker tracking.
        $this->connection_failures++;
        $this->last_connection_attempt = time();

        // Transition to fallback mode if not already there.
        if ( ! $this->fallback_mode ) {
            $this->fallback_mode = true;
            $this->log( 'warning', 'Entering fallback mode - using local cache only' );
        }

        // Log when circuit breaker threshold is reached (circuit fully open).
        if ( $this->connection_failures >= $this->circuit_breaker_threshold ) {
            $this->log( 'warning', sprintf(
                'Circuit breaker open after %d consecutive failures',
                $this->connection_failures
            ) );
        }
    }

    /**
     * Transitions the cache out of fallback mode.
     *
     * Circuit Breaker State Machine - OPEN/HALF-OPEN -> CLOSED transition:
     * Called when a connection attempt succeeds. Resets the failure counter
     * and resumes normal Hazelcast operations.
     *
     * @return void
     */
    private function exit_fallback_mode() {
        if ( $this->fallback_mode ) {
            // Reset circuit breaker state completely.
            $this->fallback_mode       = false;
            $this->connection_failures = 0;
            $this->log( 'info', 'Exiting fallback mode - Hazelcast connection restored, circuit breaker reset' );
        }
    }

    /**
     * Resets the connection failure counter after a successful operation.
     *
     * @return void
     */
    private function reset_connection_failures() {
        if ( $this->connection_failures > 0 && ! $this->fallback_mode ) {
            $this->connection_failures = 0;
            $this->log( 'debug', 'Connection failures reset after successful operation' );
        }
    }

    /**
     * Determines if a connection attempt should be made.
     *
     * Circuit Breaker State Machine - HALF-OPEN check:
     * - CLOSED state (failures < threshold): Always allow attempts.
     * - OPEN state (failures >= threshold): Only allow after retry interval.
     *
     * @return bool True if connection should be attempted, false otherwise.
     */
    private function should_attempt_connection() {
        // Circuit CLOSED: allow all connection attempts.
        if ( $this->connection_failures < $this->circuit_breaker_threshold ) {
            return true;
        }

        // Circuit OPEN: check if cooldown period has elapsed (HALF-OPEN state).
        $now = time();
        if ( ( $now - $this->last_connection_attempt ) >= $this->fallback_retry_interval ) {
            $this->log( 'debug', 'Circuit breaker half-open - allowing connection attempt' );
            return true;
        }

        return false;
    }

    /**
     * Attempts to reconnect to Hazelcast if in fallback mode.
     *
     * This is the main entry point for circuit breaker logic on each cache operation.
     * Returns true if Hazelcast is available, false if local cache should be used.
     *
     * @return bool True if Hazelcast connection is available, false otherwise.
     */
    private function maybe_retry_connection() {
        // Not in fallback mode - Hazelcast is available.
        if ( ! $this->fallback_mode ) {
            return true;
        }

        // Circuit breaker prevents retry - use local cache.
        if ( ! $this->should_attempt_connection() ) {
            return false;
        }

        // HALF-OPEN state: attempt reconnection.
        $this->last_connection_attempt = time();
        $this->log( 'debug', 'Attempting to reconnect to Hazelcast cluster' );

        if ( $this->is_connected() ) {
            // Success - transition to CLOSED state.
            $this->exit_fallback_mode();
            return true;
        }

        // Failure - remain in OPEN state, increment counter.
        $this->connection_failures++;
        $this->set_last_error();
        $this->log( 'debug', sprintf(
            'Reconnection attempt failed (failures: %d/%d) - remaining in fallback mode',
            $this->connection_failures,
            $this->circuit_breaker_threshold
        ) );
        return false;
    }

    /**
     * Checks if the circuit breaker is in the open state.
     *
     * When open, the circuit breaker prevents connection attempts until
     * the retry interval has elapsed.
     *
     * @return bool True if circuit is open (failures >= threshold).
     */
    public function is_circuit_open() {
        return $this->connection_failures >= $this->circuit_breaker_threshold;
    }

    /**
     * Returns the current count of consecutive connection failures.
     *
     * @return int Number of consecutive connection failures.
     */
    public function get_connection_failures() {
        return $this->connection_failures;
    }

    /**
     * Checks if the cache is operating in fallback mode.
     *
     * In fallback mode, all cache operations use the local in-memory
     * cache instead of Hazelcast.
     *
     * @return bool True if in fallback mode.
     */
    public function is_fallback_mode() {
        return $this->fallback_mode;
    }

    private function log( $level, $message ) {
        if ( ! $this->debug ) {
            return;
        }
        $prefix = '[Hazelcast Cache]';
        error_log( sprintf( '%s [%s] %s', $prefix, strtoupper( $level ), $message ) );
    }

    private function set_last_error() {
        if ( $this->memcached instanceof Memcached ) {
            $code    = $this->memcached->getResultCode();
            $message = $this->memcached->getResultMessage();
            if ( Memcached::RES_SUCCESS !== $code ) {
                $this->last_error = sprintf( '[%d] %s', $code, $message );
            }
        }
    }

    /**
     * Sanitizes a cache key by removing control characters.
     *
     * Key Sanitization:
     * Memcached keys cannot contain control characters (ASCII 0x00-0x20).
     * This includes null bytes, tabs, newlines, and spaces which could
     * cause protocol errors or security issues.
     *
     * @param mixed $key The cache key to sanitize.
     * @return string The sanitized key with control characters removed.
     */
    private function sanitize_key( $key ) {
        // Cast to string to handle numeric keys.
        $key = (string) $key;
        // Remove ASCII control characters (0x00-0x20) that are invalid in Memcached keys.
        return preg_replace( '/[\x00-\x20]/', '', $key );
    }

    /**
     * Builds a fully-qualified cache key with namespace prefixes.
     *
     * Key Structure: [blog_prefix:]group:vN:sanitized_key
     * - blog_prefix: Omitted for global groups, otherwise the blog ID.
     * - group: The cache group name.
     * - vN: Version number for group invalidation support.
     * - sanitized_key: The user-provided key after sanitization.
     *
     * Keys exceeding 250 characters are hashed to prevent Memcached errors.
     *
     * @param string $key   The cache key.
     * @param string $group The cache group.
     * @return string The fully-qualified cache key.
     */
    private function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $sanitized_key = $this->sanitize_key( $key );
        // Global groups omit blog prefix for cross-site sharing in multisite.
        $prefix        = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';
        $version       = $this->get_group_version( $group );
        $full_key      = $prefix . $group . ':v' . $version . ':' . $sanitized_key;

        // Memcached has a 250-byte key limit; hash long keys to fit.
        if ( strlen( $full_key ) > 250 ) {
            $hashed_key = md5( $sanitized_key );
            $full_key   = $prefix . $group . ':v' . $version . ':' . $hashed_key;
        }

        return $full_key;
    }

    private function get_group_version_key( $group ) {
        $prefix = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';
        return $prefix . '_group_version:' . $group;
    }

    private function get_group_version( $group ) {
        if ( isset( $this->group_versions[ $group ] ) ) {
            return $this->group_versions[ $group ];
        }

        if ( $this->is_non_persistent( $group ) ) {
            $this->group_versions[ $group ] = 1;
            return 1;
        }

        $version_key = $this->get_group_version_key( $group );
        $version     = $this->memcached->get( $version_key );

        if ( false === $version || Memcached::RES_SUCCESS !== $this->memcached->getResultCode() ) {
            $this->group_versions[ $group ] = 1;
            return 1;
        }

        $this->group_versions[ $group ] = (int) $version;
        return $this->group_versions[ $group ];
    }

    private function is_non_persistent( $group ) {
        return in_array( $group, $this->non_persistent_groups, true );
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @param string $key    The cache key.
     * @param mixed  $data   The data to cache.
     * @param string $group  The cache group. Default 'default'.
     * @param int    $expire Expiration time in seconds. 0 for no expiration.
     * @return bool True on success, false if key already exists.
     */
    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        $result = $this->memcached->add( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
            $this->log( 'debug', sprintf( 'ADD %s (group: %s)', $key, $group ) );
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_SUCCESS !== $code && Memcached::RES_NOTSTORED !== $code ) {
                $this->enter_fallback_mode();
                if ( ! isset( $this->cache[ $full_key ] ) ) {
                    $this->cache[ $full_key ] = $data;
                    return true;
                }
                return false;
            }
            $this->set_last_error();
            $this->log( 'debug', sprintf( 'ADD FAILED %s (group: %s) - %s', $key, $group, $this->last_error ) );
        }
        return $result;
    }

    /**
     * Retrieves data from the cache.
     *
     * @param string $key    The cache key.
     * @param string $group  The cache group. Default 'default'.
     * @param bool   $force  Whether to bypass the local cache and fetch from Hazelcast.
     * @param bool   $found  Optional. Whether the key was found. Passed by reference.
     * @return mixed|false The cached data, or false if not found.
     */
    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( isset( $this->cache[ $full_key ] ) ) {
                $found = true;
                $this->cache_hits++;
                return $this->cache[ $full_key ];
            }
            $found = false;
            $this->cache_misses++;
            return false;
        }

        if ( ! $force && isset( $this->cache[ $full_key ] ) ) {
            $found = true;
            $this->cache_hits++;
            return $this->cache[ $full_key ];
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( isset( $this->cache[ $full_key ] ) ) {
                $found = true;
                $this->cache_hits++;
                return $this->cache[ $full_key ];
            }
            $found = false;
            $this->cache_misses++;
            return false;
        }

        $data        = $this->memcached->get( $full_key );
        $result_code = $this->memcached->getResultCode();

        if ( Memcached::RES_SUCCESS === $result_code ) {
            $found                    = true;
            $this->cache_hits++;
            $this->cache[ $full_key ] = $data;
            $this->log( 'debug', sprintf( 'GET HIT %s (group: %s, source: memcached)', $key, $group ) );
            return $data;
        }

        if ( Memcached::RES_NOTFOUND !== $result_code ) {
            $this->enter_fallback_mode();
            if ( isset( $this->cache[ $full_key ] ) ) {
                $found = true;
                $this->cache_hits++;
                return $this->cache[ $full_key ];
            }
        }

        $found = false;
        $this->cache_misses++;
        $this->set_last_error();
        $this->log( 'debug', sprintf( 'GET MISS %s (group: %s)', $key, $group ) );
        return false;
    }

    /**
     * Retrieves data from the cache with a CAS (Compare-And-Swap) token.
     *
     * The CAS token can be used with cas() to perform atomic updates,
     * ensuring no other process has modified the value since it was read.
     *
     * @param string     $key       The cache key.
     * @param string     $group     The cache group. Default 'default'.
     * @param float|null $cas_token The CAS token for the item. Passed by reference.
     * @param bool       $force     Whether to bypass the local cache.
     * @param bool       $found     Optional. Whether the key was found. Passed by reference.
     * @return mixed|false The cached data, or false if not found.
     */
    public function get_with_cas( $key, $group = 'default', &$cas_token = null, $force = false, &$found = null ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key  = $this->build_key( $key, $group );
        $cas_token = null;

        if ( $this->is_non_persistent( $group ) ) {
            if ( isset( $this->cache[ $full_key ] ) ) {
                $found = true;
                $this->cache_hits++;
                return $this->cache[ $full_key ];
            }
            $found = false;
            $this->cache_misses++;
            return false;
        }

        $result      = $this->memcached->get( $full_key, null, Memcached::GET_EXTENDED );
        $result_code = $this->memcached->getResultCode();

        if ( Memcached::RES_SUCCESS === $result_code && is_array( $result ) ) {
            $found                    = true;
            $cas_token                = $result['cas'] ?? null;
            $data                     = $result['value'];
            $this->cache_hits++;
            $this->cache[ $full_key ] = $data;
            return $data;
        }

        $found = false;
        $this->cache_misses++;
        return false;
    }

    /**
     * Stores data in the cache.
     *
     * @param string $key    The cache key.
     * @param mixed  $data   The data to cache.
     * @param string $group  The cache group. Default 'default'.
     * @param int    $expire Expiration time in seconds. 0 for no expiration.
     * @return bool True on success, false on failure.
     */
    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            $this->cache[ $full_key ] = $data;
            return true;
        }

        if ( ! $this->maybe_retry_connection() ) {
            $this->cache[ $full_key ] = $data;
            return true;
        }

        $result = $this->memcached->set( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
            $this->log( 'debug', sprintf( 'SET %s (group: %s, expire: %d)', $key, $group, $expire ) );
        } else {
            $this->set_last_error();
            $this->enter_fallback_mode();
            $this->cache[ $full_key ] = $data;
            $this->log( 'error', sprintf( 'SET FAILED %s (group: %s) - %s, using fallback', $key, $group, $this->last_error ) );
            return true;
        }
        return $result;
    }

    /**
     * Removes data from the cache.
     *
     * @param string $key   The cache key.
     * @param string $group The cache group. Default 'default'.
     * @return bool True on success, false on failure.
     */
    public function delete( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );
        unset( $this->cache[ $full_key ] );

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        if ( ! $this->maybe_retry_connection() ) {
            return true;
        }

        $result = $this->memcached->delete( $full_key );
        if ( $result ) {
            $this->log( 'debug', sprintf( 'DELETE %s (group: %s)', $key, $group ) );
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_NOTFOUND !== $code ) {
                $this->enter_fallback_mode();
                return true;
            }
            $this->set_last_error();
            $this->log( 'debug', sprintf( 'DELETE FAILED %s (group: %s) - %s', $key, $group, $this->last_error ) );
        }
        return $result;
    }

    /**
     * Replaces data in the cache if the key exists.
     *
     * @param string $key    The cache key.
     * @param mixed  $data   The data to cache.
     * @param string $group  The cache group. Default 'default'.
     * @param int    $expire Expiration time in seconds. 0 for no expiration.
     * @return bool True on success, false if key doesn't exist.
     */
    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( ! isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( ! isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        $result = $this->memcached->replace( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
            $this->log( 'debug', sprintf( 'REPLACE %s (group: %s)', $key, $group ) );
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_NOTSTORED !== $code ) {
                $this->enter_fallback_mode();
                if ( isset( $this->cache[ $full_key ] ) ) {
                    $this->cache[ $full_key ] = $data;
                    return true;
                }
            }
            $this->set_last_error();
            $this->log( 'debug', sprintf( 'REPLACE FAILED %s (group: %s) - %s', $key, $group, $this->last_error ) );
        }
        return $result;
    }

    /**
     * Performs a Compare-And-Swap operation.
     *
     * Atomically updates a cache item only if it hasn't been modified
     * since the CAS token was obtained via get_with_cas().
     *
     * @param float  $cas_token The CAS token from get_with_cas().
     * @param string $key       The cache key.
     * @param mixed  $data      The new data to cache.
     * @param string $group     The cache group. Default 'default'.
     * @param int    $expire    Expiration time in seconds. 0 for no expiration.
     * @return bool True on success, false if token mismatch or key doesn't exist.
     */
    public function cas( $cas_token, $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( ! isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        if ( null === $cas_token ) {
            return false;
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( ! isset( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] = $data;
            return true;
        }

        $result = $this->memcached->cas( (float) $cas_token, $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
            $this->log( 'debug', sprintf( 'CAS %s (group: %s)', $key, $group ) );
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_DATA_EXISTS !== $code && Memcached::RES_NOTFOUND !== $code ) {
                $this->enter_fallback_mode();
                if ( isset( $this->cache[ $full_key ] ) ) {
                    $this->cache[ $full_key ] = $data;
                    return true;
                }
            }
            $this->set_last_error();
            $this->log( 'debug', sprintf( 'CAS FAILED %s (group: %s) - %s', $key, $group, $this->last_error ) );
        }
        return $result;
    }

    /**
     * Flushes all data from the cache.
     *
     * Clears both the local in-memory cache and the remote Hazelcast cache.
     *
     * @return bool True on success.
     */
    public function flush() {
        $this->cache          = array();
        $this->group_versions = array();

        if ( ! $this->maybe_retry_connection() ) {
            $this->log( 'info', 'FLUSH - Local cache flushed (fallback mode)' );
            return true;
        }

        $result = $this->memcached->flush();
        if ( $result ) {
            $this->log( 'info', 'FLUSH - Cache flushed successfully' );
        } else {
            $this->set_last_error();
            $this->enter_fallback_mode();
            $this->log( 'error', 'FLUSH FAILED - ' . $this->last_error . ', local cache cleared' );
            return true;
        }
        return $result;
    }

    /**
     * Flushes all data in a specific cache group.
     *
     * Uses version-based invalidation: increments the group version number
     * so all existing keys in the group become orphaned without deletion.
     *
     * @param string $group The cache group to flush.
     * @return bool True on success.
     */
    public function flush_group( $group ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $prefix = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';

        foreach ( array_keys( $this->cache ) as $key ) {
            if ( strpos( $key, $prefix . $group . ':' ) === 0 ) {
                unset( $this->cache[ $key ] );
            }
        }

        unset( $this->group_versions[ $group ] );

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        if ( ! $this->maybe_retry_connection() ) {
            return true;
        }

        $version_key = $this->get_group_version_key( $group );
        $result      = $this->memcached->increment( $version_key, 1 );

        if ( false === $result ) {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_NOTFOUND !== $code ) {
                $this->enter_fallback_mode();
                return true;
            }
            return $this->memcached->set( $version_key, 2, 0 );
        }

        return true;
    }

    /**
     * Increments a numeric cache item's value.
     *
     * @param string $key    The cache key.
     * @param int    $offset The amount to increment by. Default 1.
     * @param string $group  The cache group. Default 'default'.
     * @return int|false The new value on success, false if key doesn't exist or isn't numeric.
     */
    public function incr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( ! isset( $this->cache[ $full_key ] ) || ! is_numeric( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] += (int) $offset;
            if ( $this->cache[ $full_key ] < 0 ) {
                $this->cache[ $full_key ] = 0;
            }
            return $this->cache[ $full_key ];
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( ! isset( $this->cache[ $full_key ] ) || ! is_numeric( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] += (int) $offset;
            if ( $this->cache[ $full_key ] < 0 ) {
                $this->cache[ $full_key ] = 0;
            }
            return $this->cache[ $full_key ];
        }

        $result = $this->memcached->increment( $full_key, (int) $offset );
        if ( false !== $result ) {
            $this->cache[ $full_key ] = $result;
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_NOTFOUND !== $code ) {
                $this->enter_fallback_mode();
                if ( isset( $this->cache[ $full_key ] ) && is_numeric( $this->cache[ $full_key ] ) ) {
                    $this->cache[ $full_key ] += (int) $offset;
                    if ( $this->cache[ $full_key ] < 0 ) {
                        $this->cache[ $full_key ] = 0;
                    }
                    return $this->cache[ $full_key ];
                }
            }
        }
        return $result;
    }

    /**
     * Decrements a numeric cache item's value.
     *
     * The value will not go below zero.
     *
     * @param string $key    The cache key.
     * @param int    $offset The amount to decrement by. Default 1.
     * @param string $group  The cache group. Default 'default'.
     * @return int|false The new value on success, false if key doesn't exist or isn't numeric.
     */
    public function decr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $full_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( ! isset( $this->cache[ $full_key ] ) || ! is_numeric( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] -= (int) $offset;
            if ( $this->cache[ $full_key ] < 0 ) {
                $this->cache[ $full_key ] = 0;
            }
            return $this->cache[ $full_key ];
        }

        if ( ! $this->maybe_retry_connection() ) {
            if ( ! isset( $this->cache[ $full_key ] ) || ! is_numeric( $this->cache[ $full_key ] ) ) {
                return false;
            }
            $this->cache[ $full_key ] -= (int) $offset;
            if ( $this->cache[ $full_key ] < 0 ) {
                $this->cache[ $full_key ] = 0;
            }
            return $this->cache[ $full_key ];
        }

        $result = $this->memcached->decrement( $full_key, (int) $offset );
        if ( false !== $result ) {
            $this->cache[ $full_key ] = $result;
        } else {
            $code = $this->memcached->getResultCode();
            if ( Memcached::RES_NOTFOUND !== $code ) {
                $this->enter_fallback_mode();
                if ( isset( $this->cache[ $full_key ] ) && is_numeric( $this->cache[ $full_key ] ) ) {
                    $this->cache[ $full_key ] -= (int) $offset;
                    if ( $this->cache[ $full_key ] < 0 ) {
                        $this->cache[ $full_key ] = 0;
                    }
                    return $this->cache[ $full_key ];
                }
            }
        }
        return $result;
    }

    /**
     * Registers cache groups as global (shared across multisite).
     *
     * Global groups are not prefixed with the blog ID, allowing
     * cache data to be shared across all sites in a multisite network.
     *
     * @param string|array $groups A group or list of groups to register as global.
     * @return void
     */
    public function add_global_groups( $groups ) {
        $groups              = (array) $groups;
        $this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
    }

    /**
     * Registers cache groups as non-persistent.
     *
     * Non-persistent groups are stored only in the local in-memory cache
     * and are never sent to Hazelcast. Useful for data that shouldn't
     * be shared across requests or servers.
     *
     * @param string|array $groups A group or list of groups to register as non-persistent.
     * @return void
     */
    public function add_non_persistent_groups( $groups ) {
        $groups                      = (array) $groups;
        $this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
    }

    /**
     * Switches the blog context for multisite cache key prefixing.
     *
     * @param int $blog_id The blog ID to switch to.
     * @return void
     */
    public function switch_to_blog( $blog_id ) {
        $this->blog_prefix = (int) $blog_id;
    }

    /**
     * Outputs cache statistics as HTML.
     *
     * @return void
     */
    public function stats() {
        echo '<p>';
        echo '<strong>Cache Hits:</strong> ' . esc_html( $this->cache_hits ) . '<br />';
        echo '<strong>Cache Misses:</strong> ' . esc_html( $this->cache_misses ) . '<br />';
        echo '</p>';
    }

    /**
     * Returns cache statistics as an array.
     *
     * @return array {
     *     Cache statistics.
     *
     *     @type int   $hits         Number of cache hits.
     *     @type int   $misses       Number of cache misses.
     *     @type float $hit_ratio    Cache hit ratio percentage.
     *     @type int   $uptime       Server uptime in seconds.
     *     @type array $server_stats Raw stats from Memcached servers.
     * }
     */
    public function get_stats() {
        $hits      = $this->cache_hits;
        $misses    = $this->cache_misses;
        $total     = $hits + $misses;
        $hit_ratio = $total > 0 ? round( ( $hits / $total ) * 100, 2 ) : 0;

        $server_stats = array();
        $uptime       = 0;

        if ( $this->memcached instanceof Memcached ) {
            $stats = @$this->memcached->getStats();
            if ( ! empty( $stats ) ) {
                $server_stats = $stats;
                foreach ( $stats as $server => $data ) {
                    if ( isset( $data['uptime'] ) ) {
                        $uptime = (int) $data['uptime'];
                        break;
                    }
                }
            }
        }

        return array(
            'hits'         => $hits,
            'misses'       => $misses,
            'hit_ratio'    => $hit_ratio,
            'uptime'       => $uptime,
            'server_stats' => $server_stats,
        );
    }

    /**
     * Returns the list of configured Hazelcast servers.
     *
     * @return array Array of server arrays with host and port.
     */
    public function get_servers() {
        if ( ! $this->memcached instanceof Memcached ) {
            return array();
        }
        return $this->memcached->getServerList();
    }

    /**
     * Checks if the cache is connected to Hazelcast.
     *
     * @return bool True if connected and responsive.
     */
    public function is_connected() {
        if ( ! $this->memcached instanceof Memcached ) {
            return false;
        }
        $servers = $this->memcached->getServerList();
        if ( empty( $servers ) ) {
            return false;
        }
        $stats = @$this->memcached->getStats();
        return ! empty( $stats );
    }

    /**
     * Closes the connection to Hazelcast.
     *
     * @return bool Always returns true.
     */
    public function close() {
        if ( $this->memcached instanceof Memcached ) {
            $this->memcached->quit();
        }
        return true;
    }

    /**
     * Returns the current cache configuration.
     *
     * @return array {
     *     Configuration settings.
     *
     *     @type string $servers                 Comma-separated server list.
     *     @type bool   $compression             Whether compression is enabled.
     *     @type string $key_prefix              The cache key prefix.
     *     @type int    $timeout                 Connection timeout in seconds.
     *     @type int    $retry_timeout           Retry timeout in seconds.
     *     @type string $serializer              Serializer type (php or igbinary).
     *     @type bool   $tcp_nodelay             Whether TCP_NODELAY is enabled.
     *     @type bool   $authentication          Whether SASL auth is configured.
     *     @type bool   $debug                   Whether debug mode is enabled.
     *     @type int    $fallback_retry_interval Seconds between reconnection attempts.
     *     @type bool   $fallback_mode           Whether in fallback mode.
     *     @type bool   $tls_enabled             Whether TLS is enabled.
     *     @type bool   $tls_supported           Whether TLS is supported.
     *     @type int    $circuit_breaker_threshold Failures before circuit opens.
     *     @type bool   $circuit_breaker_open    Whether circuit is open.
     *     @type int    $connection_failures     Current failure count.
     * }
     */
    public function get_config() {
        $serializer = 'php';
        if ( defined( 'HAZELCAST_SERIALIZER' ) ) {
            $serializer = strtolower( HAZELCAST_SERIALIZER );
            if ( 'igbinary' === $serializer && defined( 'Memcached::SERIALIZER_IGBINARY' ) ) {
                $serializer = 'igbinary';
            } else {
                $serializer = 'php';
            }
        }

        $tls_enabled   = defined( 'HAZELCAST_TLS_ENABLED' ) && HAZELCAST_TLS_ENABLED;
        $tls_supported = defined( 'Memcached::OPT_USE_TLS' );

        return array(
            'servers'                 => defined( 'HAZELCAST_SERVERS' ) ? HAZELCAST_SERVERS : '127.0.0.1:5701',
            'compression'             => defined( 'HAZELCAST_COMPRESSION' ) ? (bool) HAZELCAST_COMPRESSION : true,
            'key_prefix'              => defined( 'HAZELCAST_KEY_PREFIX' ) ? HAZELCAST_KEY_PREFIX : ( function_exists( 'site_url' ) ? 'wp_' . md5( site_url() ) . ':' : 'wp_' . md5( DB_NAME . ABSPATH ) . ':' ),
            'timeout'                 => defined( 'HAZELCAST_TIMEOUT' ) ? (int) HAZELCAST_TIMEOUT : null,
            'retry_timeout'           => defined( 'HAZELCAST_RETRY_TIMEOUT' ) ? (int) HAZELCAST_RETRY_TIMEOUT : null,
            'serializer'              => $serializer,
            'tcp_nodelay'             => defined( 'HAZELCAST_TCP_NODELAY' ) ? (bool) HAZELCAST_TCP_NODELAY : null,
            'authentication'          => defined( 'HAZELCAST_USERNAME' ) && defined( 'HAZELCAST_PASSWORD' ),
            'debug'                   => defined( 'HAZELCAST_DEBUG' ) ? (bool) HAZELCAST_DEBUG : false,
            'fallback_retry_interval' => $this->fallback_retry_interval,
            'fallback_mode'           => $this->fallback_mode,
            'tls_enabled'             => $tls_enabled && $tls_supported,
            'tls_supported'           => $tls_supported,
            'tls_cert_path'           => defined( 'HAZELCAST_TLS_CERT_PATH' ) ? HAZELCAST_TLS_CERT_PATH : null,
            'tls_key_path'            => defined( 'HAZELCAST_TLS_KEY_PATH' ) ? HAZELCAST_TLS_KEY_PATH : null,
            'tls_ca_path'             => defined( 'HAZELCAST_TLS_CA_PATH' ) ? HAZELCAST_TLS_CA_PATH : null,
            'tls_verify_peer'              => defined( 'HAZELCAST_TLS_VERIFY_PEER' ) ? (bool) HAZELCAST_TLS_VERIFY_PEER : true,
            'circuit_breaker_threshold'    => $this->circuit_breaker_threshold,
            'circuit_breaker_open'         => $this->is_circuit_open(),
            'connection_failures'          => $this->connection_failures,
        );
    }

    /**
     * Returns the last error message from Memcached operations.
     *
     * @return string The last error message, or empty string if no error.
     */
    public function get_last_error() {
        return $this->last_error;
    }
}

/*
 * -----------------------------------------------------------------------------
 * WordPress Object Cache API Functions
 *
 * These global functions implement the WordPress object cache API by delegating
 * to the Hazelcast_WP_Object_Cache singleton instance.
 * -----------------------------------------------------------------------------
 */

/**
 * Initializes the object cache.
 *
 * @return void
 */
function wp_cache_init() {
    Hazelcast_WP_Object_Cache::instance();
}

/**
 * Adds data to the cache if it doesn't already exist.
 *
 * @param string $key    The cache key.
 * @param mixed  $data   The data to cache.
 * @param string $group  The cache group. Default 'default'.
 * @param int    $expire Expiration time in seconds.
 * @return bool True on success, false if key exists.
 */
function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->add( $key, $data, $group, $expire );
}

/**
 * Retrieves data from the cache.
 *
 * @param string $key   The cache key.
 * @param string $group The cache group. Default 'default'.
 * @param bool   $force Whether to bypass local cache.
 * @param bool   $found Optional. Whether key was found.
 * @return mixed|false Cached data or false.
 */
function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
    return Hazelcast_WP_Object_Cache::instance()->get( $key, $group, $force, $found );
}

/**
 * Retrieves data from the cache with a CAS token.
 *
 * @param string     $key       The cache key.
 * @param string     $group     The cache group. Default 'default'.
 * @param float|null $cas_token CAS token for the item.
 * @param bool       $force     Whether to bypass local cache.
 * @param bool       $found     Optional. Whether key was found.
 * @return mixed|false Cached data or false.
 */
function wp_cache_get_with_cas( $key, $group = 'default', &$cas_token = null, $force = false, &$found = null ) {
    return Hazelcast_WP_Object_Cache::instance()->get_with_cas( $key, $group, $cas_token, $force, $found );
}

/**
 * Stores data in the cache.
 *
 * @param string $key    The cache key.
 * @param mixed  $data   The data to cache.
 * @param string $group  The cache group. Default 'default'.
 * @param int    $expire Expiration time in seconds.
 * @return bool True on success.
 */
function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->set( $key, $data, $group, $expire );
}

/**
 * Removes data from the cache.
 *
 * @param string $key   The cache key.
 * @param string $group The cache group. Default 'default'.
 * @return bool True on success.
 */
function wp_cache_delete( $key, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->delete( $key, $group );
}

/**
 * Replaces data in the cache if the key exists.
 *
 * @param string $key    The cache key.
 * @param mixed  $data   The data to cache.
 * @param string $group  The cache group. Default 'default'.
 * @param int    $expire Expiration time in seconds.
 * @return bool True on success, false if key doesn't exist.
 */
function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->replace( $key, $data, $group, $expire );
}

/**
 * Performs a Compare-And-Swap operation.
 *
 * @param float  $cas_token CAS token from get_with_cas().
 * @param string $key       The cache key.
 * @param mixed  $data      The data to cache.
 * @param string $group     The cache group. Default 'default'.
 * @param int    $expire    Expiration time in seconds.
 * @return bool True on success.
 */
function wp_cache_cas( $cas_token, $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->cas( $cas_token, $key, $data, $group, $expire );
}

/**
 * Flushes all data from the cache.
 *
 * @return bool True on success.
 */
function wp_cache_flush() {
    return Hazelcast_WP_Object_Cache::instance()->flush();
}

/**
 * Increments a numeric cache item's value.
 *
 * @param string $key    The cache key.
 * @param int    $offset Amount to increment. Default 1.
 * @param string $group  The cache group. Default 'default'.
 * @return int|false New value or false on failure.
 */
function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->incr( $key, $offset, $group );
}

/**
 * Decrements a numeric cache item's value.
 *
 * @param string $key    The cache key.
 * @param int    $offset Amount to decrement. Default 1.
 * @param string $group  The cache group. Default 'default'.
 * @return int|false New value or false on failure.
 */
function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->decr( $key, $offset, $group );
}

/**
 * Registers cache groups as global (shared across multisite).
 *
 * @param string|array $groups Groups to register as global.
 * @return void
 */
function wp_cache_add_global_groups( $groups ) {
    Hazelcast_WP_Object_Cache::instance()->add_global_groups( $groups );
}

/**
 * Registers cache groups as non-persistent.
 *
 * @param string|array $groups Groups to register as non-persistent.
 * @return void
 */
function wp_cache_add_non_persistent_groups( $groups ) {
    Hazelcast_WP_Object_Cache::instance()->add_non_persistent_groups( $groups );
}

/**
 * Switches the blog context for multisite.
 *
 * @param int $blog_id The blog ID to switch to.
 * @return void
 */
function wp_cache_switch_to_blog( $blog_id ) {
    Hazelcast_WP_Object_Cache::instance()->switch_to_blog( $blog_id );
}

/**
 * Closes the connection to Hazelcast.
 *
 * @return bool True on success.
 */
function wp_cache_close() {
    return Hazelcast_WP_Object_Cache::instance()->close();
}

/**
 * Returns cache statistics.
 *
 * @return array Cache statistics array.
 */
function wp_cache_get_stats() {
    return Hazelcast_WP_Object_Cache::instance()->get_stats();
}

/**
 * Returns the list of configured servers.
 *
 * @return array Server list array.
 */
function wp_cache_get_servers() {
    return Hazelcast_WP_Object_Cache::instance()->get_servers();
}

/**
 * Checks if connected to Hazelcast.
 *
 * @return bool True if connected.
 */
function wp_cache_is_connected() {
    return Hazelcast_WP_Object_Cache::instance()->is_connected();
}

/**
 * Returns the current cache configuration.
 *
 * @return array Configuration array.
 */
function wp_cache_get_config() {
    return Hazelcast_WP_Object_Cache::instance()->get_config();
}

/**
 * Flushes all data in a specific cache group.
 *
 * @param string $group The cache group to flush.
 * @return bool True on success.
 */
function wp_cache_flush_group( $group ) {
    return Hazelcast_WP_Object_Cache::instance()->flush_group( $group );
}

/**
 * Returns the last error message.
 *
 * @return string Last error message.
 */
function wp_cache_get_last_error() {
    return Hazelcast_WP_Object_Cache::instance()->get_last_error();
}

/**
 * Checks if the cache is in fallback mode.
 *
 * @return bool True if in fallback mode.
 */
function wp_cache_is_fallback_mode() {
    return Hazelcast_WP_Object_Cache::instance()->is_fallback_mode();
}

/**
 * Checks if the circuit breaker is open.
 *
 * @return bool True if circuit is open.
 */
function wp_cache_is_circuit_open() {
    return Hazelcast_WP_Object_Cache::instance()->is_circuit_open();
}

/**
 * Returns the count of consecutive connection failures.
 *
 * @return int Connection failure count.
 */
function wp_cache_get_connection_failures() {
    return Hazelcast_WP_Object_Cache::instance()->get_connection_failures();
}
