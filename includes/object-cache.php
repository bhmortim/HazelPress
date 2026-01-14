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

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
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
        }
    }

    private function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }
        $prefix  = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';
        $version = $this->get_group_version( $group );
        return $prefix . $group . ':v' . $version . ':' . $key;
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

        $result = $this->memcached->add( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
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

        $data        = $this->memcached->get( $full_key );
        $result_code = $this->memcached->getResultCode();

        if ( Memcached::RES_SUCCESS === $result_code ) {
            $found                    = true;
            $this->cache_hits++;
            $this->cache[ $full_key ] = $data;
            return $data;
        }

        $found = false;
        $this->cache_misses++;
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

        $result = $this->memcached->set( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
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

        return $this->memcached->delete( $full_key );
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

        $result = $this->memcached->replace( $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
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

        $result = $this->memcached->cas( (float) $cas_token, $full_key, $data, (int) $expire );
        if ( $result ) {
            $this->cache[ $full_key ] = $data;
        }
        return $result;
    }

    public function flush() {
        $this->cache          = array();
        $this->group_versions = array();
        return $this->memcached->flush();
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

        $version_key = $this->get_group_version_key( $group );
        $result      = $this->memcached->increment( $version_key, 1 );

        if ( false === $result ) {
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

        $result = $this->memcached->increment( $full_key, (int) $offset );
        if ( false !== $result ) {
            $this->cache[ $full_key ] = $result;
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

        $result = $this->memcached->decrement( $full_key, (int) $offset );
        if ( false !== $result ) {
            $this->cache[ $full_key ] = $result;
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

        return array(
            'servers'        => defined( 'HAZELCAST_SERVERS' ) ? HAZELCAST_SERVERS : '127.0.0.1:5701',
            'compression'    => defined( 'HAZELCAST_COMPRESSION' ) ? (bool) HAZELCAST_COMPRESSION : true,
            'key_prefix'     => defined( 'HAZELCAST_KEY_PREFIX' ) ? HAZELCAST_KEY_PREFIX : 'wp_' . md5( site_url() ) . ':',
            'timeout'        => defined( 'HAZELCAST_TIMEOUT' ) ? (int) HAZELCAST_TIMEOUT : null,
            'retry_timeout'  => defined( 'HAZELCAST_RETRY_TIMEOUT' ) ? (int) HAZELCAST_RETRY_TIMEOUT : null,
            'serializer'     => $serializer,
            'tcp_nodelay'    => defined( 'HAZELCAST_TCP_NODELAY' ) ? (bool) HAZELCAST_TCP_NODELAY : null,
            'authentication' => defined( 'HAZELCAST_USERNAME' ) && defined( 'HAZELCAST_PASSWORD' ),
        );
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
