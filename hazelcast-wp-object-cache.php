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

// Enable/drop the object-cache.php drop-in.
function hazelcast_object_cache_enable() {
    $dropin = WP_CONTENT_DIR . '/object-cache.php';
    $source = HAZELCAST_OBJECT_CACHE_DIR . 'includes/object-cache.php';

    if ( file_exists( $source ) && ! file_exists( $dropin ) ) {
        // Use symlink if possible, fallback to copy.
        if ( function_exists( 'symlink' ) ) {
            symlink( $source, $dropin );
        } else {
            copy( $source, $dropin );
        }
    }
}

function hazelcast_object_cache_disable() {
    $dropin = WP_CONTENT_DIR . '/object-cache.php';
    if ( file_exists( $dropin ) && false !== strpos( realpath( $dropin ), realpath( HAZELCAST_OBJECT_CACHE_DIR ) ) ) {
        unlink( $dropin );
    }
}

// Activation/deactivation hooks.
register_activation_hook( __FILE__, 'hazelcast_object_cache_enable' );
register_deactivation_hook( __FILE__, 'hazelcast_object_cache_disable' );

// Optional: Auto-enable on activation, show status in admin.
