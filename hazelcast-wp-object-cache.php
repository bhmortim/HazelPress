<?php
/**
 * Plugin Name:       Hazelcast Object Cache
 * Plugin URI:        https://github.com/hazelcast/hazelcast-wp-object-cache
 * Description:       Enables Hazelcast as the object cache backend for WordPress via the Memcache protocol.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Hazelcast, Inc.
 * Author URI:        https://hazelcast.com
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       hazelcast-object-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'HAZELCAST_OBJECT_CACHE_VERSION', '1.0.0' );
define( 'HAZELCAST_OBJECT_CACHE_FILE', __FILE__ );
define( 'HAZELCAST_OBJECT_CACHE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAZELCAST_OBJECT_CACHE_URL', plugin_dir_url( __FILE__ ) );

// Check for Memcached extension.
if ( ! class_exists( 'Memcached' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Hazelcast Object Cache requires the Memcached PECL extension.', 'hazelcast-object-cache' ) . '</p></div>';
    } );
    return;
}

// Load admin page if in admin.
if ( is_admin() ) {
    require_once HAZELCAST_OBJECT_CACHE_DIR . 'admin/class-hazelcast-admin.php';
    new Hazelcast_WP_Admin();
}

// Register WP-CLI commands if available.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once HAZELCAST_OBJECT_CACHE_DIR . 'includes/class-hazelcast-cli.php';
    WP_CLI::add_command( 'hazelcast', 'Hazelcast_WP_CLI' );
}

/**
 * Enable the object-cache.php drop-in.
 *
 * Creates a symlink (preferred) or copy of the drop-in file.
 * Stores failure reason in transient if installation fails.
 */
function hazelcast_object_cache_enable() {
    $dropin = WP_CONTENT_DIR . '/object-cache.php';
    $source = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

    delete_transient( 'hazelcast_dropin_install_failed' );

    if ( ! file_exists( $source ) ) {
        set_transient( 'hazelcast_dropin_install_failed', 'source_missing', HOUR_IN_SECONDS );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Hazelcast Object Cache: Source file not found at ' . $source );
        }
        return;
    }

    if ( file_exists( $dropin ) || is_link( $dropin ) ) {
        if ( is_link( $dropin ) && readlink( $dropin ) === $source ) {
            return;
        }
        if ( file_exists( $dropin ) && file_get_contents( $dropin ) === file_get_contents( $source ) ) {
            return;
        }
        set_transient( 'hazelcast_dropin_install_failed', 'existing_dropin', HOUR_IN_SECONDS );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Hazelcast Object Cache: A different object-cache.php drop-in already exists.' );
        }
        return;
    }

    if ( function_exists( 'symlink' ) ) {
        if ( @symlink( $source, $dropin ) ) {
            return;
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Hazelcast Object Cache: symlink() failed, falling back to copy().' );
        }
    }

    if ( @copy( $source, $dropin ) ) {
        return;
    }

    set_transient( 'hazelcast_dropin_install_failed', 'write_failed', HOUR_IN_SECONDS );
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Hazelcast Object Cache: Failed to install drop-in. Check permissions on ' . WP_CONTENT_DIR );
    }
}

/**
 * Disable the object-cache.php drop-in.
 *
 * Only removes the drop-in if it belongs to this plugin (symlink or matching content).
 */
function hazelcast_object_cache_disable() {
    $dropin = WP_CONTENT_DIR . '/object-cache.php';
    $source = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

    delete_transient( 'hazelcast_dropin_install_failed' );

    if ( ! file_exists( $dropin ) && ! is_link( $dropin ) ) {
        return;
    }

    $is_ours = false;

    if ( is_link( $dropin ) ) {
        $link_target = readlink( $dropin );
        if ( $link_target === $source || realpath( $link_target ) === realpath( $source ) ) {
            $is_ours = true;
        }
    } elseif ( file_exists( $dropin ) && file_exists( $source ) ) {
        if ( file_get_contents( $dropin ) === file_get_contents( $source ) ) {
            $is_ours = true;
        }
    }

    if ( $is_ours ) {
        if ( ! @unlink( $dropin ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Hazelcast Object Cache: Failed to remove drop-in. Check file permissions.' );
            }
        }
    }
}

// Activation/deactivation hooks.
register_activation_hook( __FILE__, 'hazelcast_object_cache_enable' );
register_deactivation_hook( __FILE__, 'hazelcast_object_cache_disable' );

// Optional: Auto-enable on activation, show status in admin.
