<?php
/**
 * PHPUnit bootstrap file for Hazelcast Object Cache tests.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CACHE', true );

$blog_id = 1;

if ( ! class_exists( 'Memcached' ) ) {
    class Memcached {
        const OPT_COMPRESSION = 1;
        const OPT_PREFIX_KEY  = 2;
        const RES_SUCCESS     = 0;
        const RES_NOTFOUND    = 16;
        const GET_EXTENDED    = 1;

        private static $persistent_instances = array();
        private $persistent_id;
        private $data         = array();
        private $servers      = array();
        private $options      = array();
        private $result_code  = self::RES_SUCCESS;
        private $cas_counters = array();

        public function __construct( $persistent_id = null ) {
            $this->persistent_id = $persistent_id;
            if ( $persistent_id && isset( self::$persistent_instances[ $persistent_id ] ) ) {
                $instance           = self::$persistent_instances[ $persistent_id ];
                $this->data         = &$instance['data'];
                $this->servers      = &$instance['servers'];
                $this->options      = &$instance['options'];
                $this->cas_counters = &$instance['cas_counters'];
            } elseif ( $persistent_id ) {
                self::$persistent_instances[ $persistent_id ] = array(
                    'data'         => &$this->data,
                    'servers'      => &$this->servers,
                    'options'      => &$this->options,
                    'cas_counters' => &$this->cas_counters,
                );
            }
        }

        public function setOption( $option, $value ) {
            $this->options[ $option ] = $value;
            return true;
        }

        public function getOption( $option ) {
            return $this->options[ $option ] ?? null;
        }

        public function addServers( array $servers ) {
            foreach ( $servers as $server ) {
                $this->servers[] = array(
                    'host' => $server[0],
                    'port' => $server[1] ?? 11211,
                );
            }
            return true;
        }

        public function getServerList() {
            return $this->servers;
        }

        public function setSaslAuthData( $username, $password ) {
            return true;
        }

        public function add( $key, $value, $expiration = 0 ) {
            if ( isset( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->data[ $key ]         = $value;
            $this->cas_counters[ $key ] = microtime( true );
            $this->result_code          = self::RES_SUCCESS;
            return true;
        }

        public function get( $key, $cache_cb = null, $flags = 0 ) {
            if ( ! isset( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->result_code = self::RES_SUCCESS;
            if ( $flags === self::GET_EXTENDED ) {
                return array(
                    'value' => $this->data[ $key ],
                    'cas'   => $this->cas_counters[ $key ] ?? microtime( true ),
                );
            }
            return $this->data[ $key ];
        }

        public function set( $key, $value, $expiration = 0 ) {
            $this->data[ $key ]         = $value;
            $this->cas_counters[ $key ] = microtime( true );
            $this->result_code          = self::RES_SUCCESS;
            return true;
        }

        public function delete( $key ) {
            if ( ! isset( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            unset( $this->data[ $key ], $this->cas_counters[ $key ] );
            $this->result_code = self::RES_SUCCESS;
            return true;
        }

        public function replace( $key, $value, $expiration = 0 ) {
            if ( ! isset( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->data[ $key ]         = $value;
            $this->cas_counters[ $key ] = microtime( true );
            $this->result_code          = self::RES_SUCCESS;
            return true;
        }

        public function cas( $cas_token, $key, $value, $expiration = 0 ) {
            if ( ! isset( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            if ( abs( $this->cas_counters[ $key ] - $cas_token ) > 0.0001 ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->data[ $key ]         = $value;
            $this->cas_counters[ $key ] = microtime( true );
            $this->result_code          = self::RES_SUCCESS;
            return true;
        }

        public function increment( $key, $offset = 1 ) {
            if ( ! isset( $this->data[ $key ] ) || ! is_numeric( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->data[ $key ] += (int) $offset;
            if ( $this->data[ $key ] < 0 ) {
                $this->data[ $key ] = 0;
            }
            $this->result_code = self::RES_SUCCESS;
            return $this->data[ $key ];
        }

        public function decrement( $key, $offset = 1 ) {
            if ( ! isset( $this->data[ $key ] ) || ! is_numeric( $this->data[ $key ] ) ) {
                $this->result_code = self::RES_NOTFOUND;
                return false;
            }
            $this->data[ $key ] -= (int) $offset;
            if ( $this->data[ $key ] < 0 ) {
                $this->data[ $key ] = 0;
            }
            $this->result_code = self::RES_SUCCESS;
            return $this->data[ $key ];
        }

        public function flush() {
            $this->data         = array();
            $this->cas_counters = array();
            $this->result_code  = self::RES_SUCCESS;
            return true;
        }

        public function getResultCode() {
            return $this->result_code;
        }

        public function getStats() {
            if ( empty( $this->servers ) ) {
                return array();
            }
            $stats = array();
            foreach ( $this->servers as $server ) {
                $key           = $server['host'] . ':' . $server['port'];
                $stats[ $key ] = array(
                    'uptime'      => 3600,
                    'curr_items'  => count( $this->data ),
                    'total_items' => count( $this->data ),
                );
            }
            return $stats;
        }

        public function quit() {
            return true;
        }

        public static function resetPersistentInstances() {
            self::$persistent_instances = array();
        }
    }
}

if ( ! function_exists( 'site_url' ) ) {
    function site_url() {
        return 'http://example.com';
    }
}

if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite() {
        return false;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

require_once dirname( __DIR__ ) . '/includes/object-cache.php';
