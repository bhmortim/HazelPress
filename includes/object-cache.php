<?php
/**
 * Hazelcast Object Cache Drop-in
 *
 * Uses PHP Memcached extension to connect to Hazelcast cluster via Memcache protocol.
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

class Hazelcast_WP_Object_Cache {
    private static $instance;
    private $memcached;
    private $global_groups = array();
    private $non_persistent_groups = array();
    private $group_versions = array();
    private $cache = array();
    private $cache_hits = 0;
    private $cache_misses = 0;
    private $blog_prefix;
    private $debug = false;
    private $last_error = '';
    private $fallback_mode = false;
    private $fallback_retry_interval = 30;
    private $last_connection_attempt = 0;
    private $connection_failures = 0;
    private $circuit_breaker_threshold = 5;

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

        global $blog_id;
        $this->blog_prefix = is_multisite() ? (int) $blog_id : 1;

        $servers  = defined( 'HAZELCAST_SERVERS' ) ? explode( ',', HAZELCAST_SERVERS ) : array( '127.0.0.1:5701' );
        $username = defined( 'HAZELCAST_USERNAME' ) ? HAZELCAST_USERNAME : null;
        $password = defined( 'HAZELCAST_PASSWORD' ) ? HAZELCAST_PASSWORD : null;

        if ( ! count( $this->memcached->getServerList() ) ) {
            $this->memcached->setOption( Memcached::OPT_COMPRESSION, defined( 'HAZELCAST_COMPRESSION' ) ? (bool) HAZELCAST_COMPRESSION : true );

            $key_prefix = defined( 'HAZELCAST_KEY_PREFIX' ) ? HAZELCAST_KEY_PREFIX : 'wp_' . md5( site_url() ) . ':';
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

    private function enter_fallback_mode() {
        $this->connection_failures++;
        $this->last_connection_attempt = time();

        if ( ! $this->fallback_mode ) {
            $this->fallback_mode = true;
            $this->log( 'warning', 'Entering fallback mode - using local cache only' );
        }

        if ( $this->connection_failures >= $this->circuit_breaker_threshold ) {
            $this->log( 'warning', sprintf(
                'Circuit breaker open after %d consecutive failures',
                $this->connection_failures
            ) );
        }
    }

    private function exit_fallback_mode() {
        if ( $this->fallback_mode ) {
            $this->fallback_mode       = false;
            $this->connection_failures = 0;
            $this->log( 'info', 'Exiting fallback mode - Hazelcast connection restored, circuit breaker reset' );
        }
    }

    private function reset_connection_failures() {
        if ( $this->connection_failures > 0 && ! $this->fallback_mode ) {
            $this->connection_failures = 0;
            $this->log( 'debug', 'Connection failures reset after successful operation' );
        }
    }

    private function should_attempt_connection() {
        if ( $this->connection_failures < $this->circuit_breaker_threshold ) {
            return true;
        }

        $now = time();
        if ( ( $now - $this->last_connection_attempt ) >= $this->fallback_retry_interval ) {
            $this->log( 'debug', 'Circuit breaker half-open - allowing connection attempt' );
            return true;
        }

        return false;
    }

    private function maybe_retry_connection() {
        if ( ! $this->fallback_mode ) {
            return true;
        }

        if ( ! $this->should_attempt_connection() ) {
            return false;
        }

        $this->last_connection_attempt = time();
        $this->log( 'debug', 'Attempting to reconnect to Hazelcast cluster' );

        if ( $this->is_connected() ) {
            $this->exit_fallback_mode();
            return true;
        }

        $this->connection_failures++;
        $this->set_last_error();
        $this->log( 'debug', sprintf(
            'Reconnection attempt failed (failures: %d/%d) - remaining in fallback mode',
            $this->connection_failures,
            $this->circuit_breaker_threshold
        ) );
        return false;
    }

    public function is_circuit_open() {
        return $this->connection_failures >= $this->circuit_breaker_threshold;
    }

    public function get_connection_failures() {
        return $this->connection_failures;
    }

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

    private function sanitize_key( $key ) {
        $key = (string) $key;
        return preg_replace( '/[\x00-\x20]/', '', $key );
    }

    private function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $sanitized_key = $this->sanitize_key( $key );
        $prefix        = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';
        $version       = $this->get_group_version( $group );
        $full_key      = $prefix . $group . ':v' . $version . ':' . $sanitized_key;

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

    public function add_global_groups( $groups ) {
        $groups              = (array) $groups;
        $this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
    }

    public function add_non_persistent_groups( $groups ) {
        $groups                      = (array) $groups;
        $this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
    }

    public function switch_to_blog( $blog_id ) {
        $this->blog_prefix = (int) $blog_id;
    }

    public function stats() {
        echo '<p>';
        echo '<strong>Cache Hits:</strong> ' . esc_html( $this->cache_hits ) . '<br />';
        echo '<strong>Cache Misses:</strong> ' . esc_html( $this->cache_misses ) . '<br />';
        echo '</p>';
    }

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

    public function get_servers() {
        if ( ! $this->memcached instanceof Memcached ) {
            return array();
        }
        return $this->memcached->getServerList();
    }

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

    public function close() {
        if ( $this->memcached instanceof Memcached ) {
            $this->memcached->quit();
        }
        return true;
    }

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
            'key_prefix'              => defined( 'HAZELCAST_KEY_PREFIX' ) ? HAZELCAST_KEY_PREFIX : 'wp_' . md5( site_url() ) . ':',
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

    public function get_last_error() {
        return $this->last_error;
    }
}

function wp_cache_init() {
    Hazelcast_WP_Object_Cache::instance();
}

function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->add( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
    return Hazelcast_WP_Object_Cache::instance()->get( $key, $group, $force, $found );
}

function wp_cache_get_with_cas( $key, $group = 'default', &$cas_token = null, $force = false, &$found = null ) {
    return Hazelcast_WP_Object_Cache::instance()->get_with_cas( $key, $group, $cas_token, $force, $found );
}

function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->set( $key, $data, $group, $expire );
}

function wp_cache_delete( $key, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->delete( $key, $group );
}

function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->replace( $key, $data, $group, $expire );
}

function wp_cache_cas( $cas_token, $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->cas( $cas_token, $key, $data, $group, $expire );
}

function wp_cache_flush() {
    return Hazelcast_WP_Object_Cache::instance()->flush();
}

function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->decr( $key, $offset, $group );
}

function wp_cache_add_global_groups( $groups ) {
    Hazelcast_WP_Object_Cache::instance()->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
    Hazelcast_WP_Object_Cache::instance()->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
    Hazelcast_WP_Object_Cache::instance()->switch_to_blog( $blog_id );
}

function wp_cache_close() {
    return Hazelcast_WP_Object_Cache::instance()->close();
}

function wp_cache_get_stats() {
    return Hazelcast_WP_Object_Cache::instance()->get_stats();
}

function wp_cache_get_servers() {
    return Hazelcast_WP_Object_Cache::instance()->get_servers();
}

function wp_cache_is_connected() {
    return Hazelcast_WP_Object_Cache::instance()->is_connected();
}

function wp_cache_get_config() {
    return Hazelcast_WP_Object_Cache::instance()->get_config();
}

function wp_cache_flush_group( $group ) {
    return Hazelcast_WP_Object_Cache::instance()->flush_group( $group );
}

function wp_cache_get_last_error() {
    return Hazelcast_WP_Object_Cache::instance()->get_last_error();
}

function wp_cache_is_fallback_mode() {
    return Hazelcast_WP_Object_Cache::instance()->is_fallback_mode();
}

function wp_cache_is_circuit_open() {
    return Hazelcast_WP_Object_Cache::instance()->is_circuit_open();
}

function wp_cache_get_connection_failures() {
    return Hazelcast_WP_Object_Cache::instance()->get_connection_failures();
}
