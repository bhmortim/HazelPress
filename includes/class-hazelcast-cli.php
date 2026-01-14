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
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        $source = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

        WP_CLI::line( '' );
        WP_CLI::line( WP_CLI::colorize( '%BDrop-in Status:%n' ) );

        if ( ! file_exists( $dropin ) && ! is_link( $dropin ) ) {
            WP_CLI::line( WP_CLI::colorize( '  %rNot installed%n' ) );
            WP_CLI::line( '' );
            WP_CLI::line( '  To enable caching, run:' );
            WP_CLI::line( WP_CLI::colorize( '    %gwp hazelcast install%n' ) );
            WP_CLI::line( '' );
            WP_CLI::line( '  Or manually create a symlink:' );
            WP_CLI::line( '    ln -s ' . $source . ' ' . $dropin );
        } elseif ( is_link( $dropin ) && ! file_exists( $dropin ) ) {
            WP_CLI::line( WP_CLI::colorize( '  %rBroken symlink%n' ) );
            WP_CLI::line( '' );
            WP_CLI::line( '  To fix, run:' );
            WP_CLI::line( WP_CLI::colorize( '    %gwp hazelcast install --force%n' ) );
        } elseif ( $this->is_our_dropin( $dropin, $source ) ) {
            $type = is_link( $dropin ) ? 'Symlink' : 'File copy';
            WP_CLI::line( WP_CLI::colorize( '  %gInstalled%n' ) . ' (' . $type . ')' );
        } else {
            WP_CLI::line( WP_CLI::colorize( '  %yDifferent drop-in installed%n' ) );
            WP_CLI::line( '' );
            WP_CLI::line( '  To replace with Hazelcast, run:' );
            WP_CLI::line( WP_CLI::colorize( '    %gwp hazelcast install --force%n' ) );
        }

        if ( ! class_exists( 'Hazelcast_WP_Object_Cache' ) ) {
            WP_CLI::line( '' );
            WP_CLI::error( 'Hazelcast Object Cache class is not loaded. Ensure the drop-in is installed.' );
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
     * Install the object-cache.php drop-in.
     *
     * Creates a symlink (preferred) or copy of the drop-in file to enable
     * Hazelcast object caching.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Overwrite an existing drop-in file, even if it belongs to another plugin.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast install
     *     wp hazelcast install --force
     *
     * @when after_wp_load
     */
    public function install( $args, $assoc_args ) {
        $force   = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
        $dropin  = WP_CONTENT_DIR . '/object-cache.php';
        $source  = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

        if ( ! file_exists( $source ) ) {
            WP_CLI::error( 'Source file not found: ' . $source );
        }

        if ( file_exists( $dropin ) || is_link( $dropin ) ) {
            $is_ours = $this->is_our_dropin( $dropin, $source );

            if ( $is_ours && ! $force ) {
                WP_CLI::success( 'Hazelcast object-cache.php drop-in is already installed.' );
                return;
            }

            if ( ! $is_ours && ! $force ) {
                WP_CLI::error( 'A different object-cache.php drop-in already exists. Use --force to overwrite.' );
            }

            if ( ! @unlink( $dropin ) ) {
                $this->handle_permission_error( 'remove', $dropin );
            }
            WP_CLI::log( 'Removed existing drop-in.' );
        }

        if ( function_exists( 'symlink' ) ) {
            if ( @symlink( $source, $dropin ) ) {
                WP_CLI::success( 'Installed object-cache.php drop-in via symlink.' );
                return;
            }
            WP_CLI::log( 'Symlink failed, attempting copy...' );
        }

        if ( @copy( $source, $dropin ) ) {
            WP_CLI::success( 'Installed object-cache.php drop-in via copy.' );
            return;
        }

        $this->handle_permission_error( 'write to', WP_CONTENT_DIR );
    }

    /**
     * Uninstall the object-cache.php drop-in.
     *
     * Removes the drop-in file only if it belongs to this plugin.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Remove the drop-in even if it does not appear to belong to this plugin.
     *
     * ## EXAMPLES
     *
     *     wp hazelcast uninstall
     *     wp hazelcast uninstall --force
     *
     * @when after_wp_load
     */
    public function uninstall( $args, $assoc_args ) {
        $force   = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
        $dropin  = WP_CONTENT_DIR . '/object-cache.php';
        $source  = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

        if ( ! file_exists( $dropin ) && ! is_link( $dropin ) ) {
            WP_CLI::warning( 'No object-cache.php drop-in found.' );
            return;
        }

        $is_ours = $this->is_our_dropin( $dropin, $source );

        if ( ! $is_ours && ! $force ) {
            WP_CLI::error( 'The installed drop-in does not belong to this plugin. Use --force to remove anyway.' );
        }

        if ( ! $is_ours && $force ) {
            WP_CLI::warning( 'Removing drop-in that does not belong to this plugin (--force used).' );
        }

        if ( ! @unlink( $dropin ) ) {
            $this->handle_permission_error( 'remove', $dropin );
        }

        delete_transient( 'hazelcast_dropin_install_failed' );

        WP_CLI::success( 'Removed object-cache.php drop-in.' );
    }

    /**
     * Checks if the drop-in belongs to this plugin.
     *
     * @param string $dropin Path to the drop-in file.
     * @param string $source Path to the plugin's source file.
     * @return bool True if the drop-in belongs to this plugin.
     */
    private function is_our_dropin( $dropin, $source ) {
        if ( is_link( $dropin ) ) {
            $link_target = readlink( $dropin );
            if ( $link_target === $source || realpath( $link_target ) === realpath( $source ) ) {
                return true;
            }
            return false;
        }

        if ( file_exists( $dropin ) && file_exists( $source ) ) {
            if ( file_get_contents( $dropin ) === file_get_contents( $source ) ) {
                return true;
            }
            $content = file_get_contents( $dropin );
            if ( false !== $content && strpos( $content, 'Hazelcast_WP_Object_Cache' ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handles permission errors with helpful troubleshooting hints.
     *
     * @param string $action The action that failed (e.g., 'write to', 'remove').
     * @param string $path   The path that could not be accessed.
     */
    private function handle_permission_error( $action, $path ) {
        WP_CLI::error_multi_line( array(
            sprintf( 'Failed to %s: %s', $action, $path ),
            '',
            'Troubleshooting:',
            '  1. Check file/directory ownership and permissions.',
            '  2. Try running with elevated privileges:',
            sprintf( '     sudo -u www-data wp hazelcast %s', 'write to' === $action ? 'install' : 'uninstall' ),
            '  3. Manually adjust permissions:',
            sprintf( '     sudo chown %s:%s %s', get_current_user(), get_current_user(), dirname( $path ) ),
            sprintf( '     sudo chmod 755 %s', dirname( $path ) ),
        ) );
        exit( 1 );
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
