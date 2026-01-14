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
            $this->memcached->setOption( Memcached::OPT_PREFIX_KEY, 'wp_' . md5( site_url() ) . ':' );

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
        $prefix = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix . ':';
        return $prefix . $group . ':' . $key;
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

    public function flush() {
        $this->cache = array();
        return $this->memcached->flush();
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
        return array(
            'hits'   => $this->cache_hits,
            'misses' => $this->cache_misses,
        );
    }

    public function close() {
        if ( $this->memcached instanceof Memcached ) {
            $this->memcached->quit();
        }
        return true;
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

function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->set( $key, $data, $group, $expire );
}

function wp_cache_delete( $key, $group = 'default' ) {
    return Hazelcast_WP_Object_Cache::instance()->delete( $key, $group );
}

function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
    return Hazelcast_WP_Object_Cache::instance()->replace( $key, $data, $group, $expire );
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
