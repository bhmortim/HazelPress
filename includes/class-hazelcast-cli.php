<?php
/**
 * Hazelcast WP-CLI Commands
 *
 * Provides WP-CLI commands for managing and monitoring the Hazelcast object cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hazelcast_WP_CLI {

    /**
     * Display cache connection status and statistics.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        if ( ! class_exists( 'Hazelcast_WP_Object_Cache' ) ) {
            WP_CLI::error( 'Hazelcast Object Cache is not loaded.' );
        }

        $cache      = Hazelcast_WP_Object_Cache::instance();
        $connected  = $cache->is_connected();
        $servers    = $cache->get_servers();
        $stats      = $cache->get_stats();
        $config     = $cache->get_config();
        $last_error = $cache->get_last_error();

        WP_CLI::line( '' );
        if ( $connected ) {
            WP_CLI::success( 'Connected to Hazelcast cluster' );
        } else {
            WP_CLI::warning( 'Not connected to Hazelcast cluster' );
            if ( ! empty( $last_error ) ) {
                WP_CLI::line( WP_CLI::colorize( '%RLast Error:%n ' . $last_error ) );
            }
        }

        WP_CLI::line( '' );
        WP_CLI::line( WP_CLI::colorize( '%BServers:%n' ) );
        if ( ! empty( $servers ) ) {
            foreach ( $servers as $server ) {
                WP_CLI::line( '  - ' . $server['host'] . ':' . $server['port'] );
            }
        } else {
            WP_CLI::line( '  No servers configured' );
        }

        WP_CLI::line( '' );
        WP_CLI::line( WP_CLI::colorize( '%BStatistics:%n' ) );
        WP_CLI::line( sprintf( '  Cache Hits:   %d', $stats['hits'] ) );
        WP_CLI::line( sprintf( '  Cache Misses: %d', $stats['misses'] ) );
        WP_CLI::line( sprintf( '  Hit Ratio:    %.2f%%', $stats['hit_ratio'] ) );
        WP_CLI::line( sprintf( '  Uptime:       %s', $this->format_uptime( $stats['uptime'] ) ) );

        WP_CLI::line( '' );
        WP_CLI::line( WP_CLI::colorize( '%BConfiguration:%n' ) );
        WP_CLI::line( sprintf( '  Servers:      %s', $config['servers'] ) );
        WP_CLI::line( sprintf( '  Compression:  %s', $config['compression'] ? 'Enabled' : 'Disabled' ) );
        WP_CLI::line( sprintf( '  Serializer:   %s', strtoupper( $config['serializer'] ) ) );
        WP_CLI::line( sprintf( '  Key Prefix:   %s', $config['key_prefix'] ) );
        WP_CLI::line( sprintf( '  Auth:         %s', $config['authentication'] ? 'Configured' : 'Not configured' ) );
        WP_CLI::line( sprintf( '  Debug:        %s', $config['debug'] ? 'Enabled' : 'Disabled' ) );
        WP_CLI::line( '' );
    }

    /**
     * Flush the object cache.
     *
     * ## OPTIONS
     *
     * [--group=<group>]
     * : Flush only a specific cache group instead of the entire cache.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast flush
     *     wp hazelcast flush --group=options
     *     wp hazelcast flush --group=posts
     *
     * @when after_wp_load
     */
    public function flush( $args, $assoc_args ) {
        $group = WP_CLI\Utils\get_flag_value( $assoc_args, 'group', null );

        if ( $group ) {
            if ( ! function_exists( 'wp_cache_flush_group' ) ) {
                WP_CLI::error( 'wp_cache_flush_group() is not available.' );
            }

            $result = wp_cache_flush_group( $group );
            if ( $result ) {
                WP_CLI::success( sprintf( 'Cache group "%s" flushed successfully.', $group ) );
            } else {
                WP_CLI::error( sprintf( 'Failed to flush cache group "%s".', $group ) );
            }
        } else {
            if ( ! function_exists( 'wp_cache_flush' ) ) {
                WP_CLI::error( 'wp_cache_flush() is not available.' );
            }

            $result = wp_cache_flush();
            if ( $result ) {
                WP_CLI::success( 'Cache flushed successfully.' );
            } else {
                WP_CLI::error( 'Failed to flush cache.' );
            }
        }
    }

    /**
     * Retrieve and display a cached value.
     *
     * ## OPTIONS
     *
     * <key>
     * : The cache key to retrieve.
     *
     * [--group=<group>]
     * : The cache group. Default: 'default'.
     *
     * [--format=<format>]
     * : Output format. Options: dump, json, serialize. Default: dump.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast get my_key
     *     wp hazelcast get my_key --group=options
     *     wp hazelcast get my_key --format=json
     *
     * @when after_wp_load
     */
    public function get( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide a cache key.' );
        }

        $key    = $args[0];
        $group  = WP_CLI\Utils\get_flag_value( $assoc_args, 'group', 'default' );
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'dump' );

        if ( ! function_exists( 'wp_cache_get' ) ) {
            WP_CLI::error( 'wp_cache_get() is not available.' );
        }

        $found = false;
        $value = wp_cache_get( $key, $group, false, $found );

        if ( ! $found ) {
            WP_CLI::warning( sprintf( 'Key "%s" not found in group "%s".', $key, $group ) );
            return;
        }

        WP_CLI::line( sprintf( 'Key: %s', $key ) );
        WP_CLI::line( sprintf( 'Group: %s', $group ) );
        WP_CLI::line( sprintf( 'Type: %s', gettype( $value ) ) );
        WP_CLI::line( '' );

        switch ( $format ) {
            case 'json':
                WP_CLI::line( wp_json_encode( $value, JSON_PRETTY_PRINT ) );
                break;
            case 'serialize':
                WP_CLI::line( serialize( $value ) );
                break;
            case 'dump':
            default:
                WP_CLI::print_value( $value );
                break;
        }
    }

    /**
     * Run a connection test.
     *
     * Performs a set/get/delete cycle to verify the connection and measure latency.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast test
     *
     * @when after_wp_load
     */
    public function test( $args, $assoc_args ) {
        if ( ! function_exists( 'wp_cache_set' ) || ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_delete' ) ) {
            WP_CLI::error( 'Object cache functions not available.' );
        }

        $test_key   = 'hazelcast_cli_test_' . time();
        $test_value = 'test_value_' . wp_generate_password( 8, false );
        $test_group = 'hazelcast_test';
        $results    = array();

        WP_CLI::line( 'Running connection test...' );
        WP_CLI::line( '' );

        $start       = microtime( true );
        $set_result  = wp_cache_set( $test_key, $test_value, $test_group, 60 );
        $set_latency = round( ( microtime( true ) - $start ) * 1000, 2 );
        $results[]   = array(
            'Operation' => 'SET',
            'Status'    => $set_result ? 'OK' : 'FAILED',
            'Latency'   => $set_latency . ' ms',
        );

        if ( ! $set_result ) {
            WP_CLI\Utils\format_items( 'table', $results, array( 'Operation', 'Status', 'Latency' ) );
            WP_CLI::error( 'Connection test failed at SET operation.' );
        }

        $start       = microtime( true );
        $get_result  = wp_cache_get( $test_key, $test_group, true );
        $get_latency = round( ( microtime( true ) - $start ) * 1000, 2 );
        $get_success = $get_result === $test_value;
        $results[]   = array(
            'Operation' => 'GET',
            'Status'    => $get_success ? 'OK' : 'FAILED',
            'Latency'   => $get_latency . ' ms',
        );

        if ( ! $get_success ) {
            WP_CLI\Utils\format_items( 'table', $results, array( 'Operation', 'Status', 'Latency' ) );
            WP_CLI::error( 'Connection test failed at GET operation (value mismatch).' );
        }

        $start          = microtime( true );
        $delete_result  = wp_cache_delete( $test_key, $test_group );
        $delete_latency = round( ( microtime( true ) - $start ) * 1000, 2 );
        $results[]      = array(
            'Operation' => 'DELETE',
            'Status'    => $delete_result ? 'OK' : 'FAILED',
            'Latency'   => $delete_latency . ' ms',
        );

        $total_latency = $set_latency + $get_latency + $delete_latency;
        $results[]     = array(
            'Operation' => 'TOTAL',
            'Status'    => '-',
            'Latency'   => $total_latency . ' ms',
        );

        WP_CLI\Utils\format_items( 'table', $results, array( 'Operation', 'Status', 'Latency' ) );

        if ( ! $delete_result ) {
            WP_CLI::error( 'Connection test failed at DELETE operation.' );
        }

        WP_CLI::success( 'Connection test passed successfully!' );
    }

    /**
     * Format uptime in human-readable format.
     *
     * @param int $seconds Uptime in seconds.
     * @return string Formatted uptime.
     */
    private function format_uptime( $seconds ) {
        if ( $seconds < 60 ) {
            return $seconds . ' seconds';
        }
        if ( $seconds < 3600 ) {
            return floor( $seconds / 60 ) . ' minutes';
        }
        if ( $seconds < 86400 ) {
            return floor( $seconds / 3600 ) . ' hours';
        }
        return floor( $seconds / 86400 ) . ' days';
    }
}
