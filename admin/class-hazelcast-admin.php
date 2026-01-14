<?php
/**
 * Hazelcast Admin Dashboard
 *
 * Provides WordPress admin interface for monitoring and managing the Hazelcast object cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hazelcast_WP_Admin {

    private static $instance;

    public static function instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_flush_action' ) );
    }

    public function add_admin_menu() {
        add_options_page(
            __( 'Hazelcast Object Cache', 'hazelcast-object-cache' ),
            __( 'Hazelcast Cache', 'hazelcast-object-cache' ),
            'manage_options',
            'hazelcast-object-cache',
            array( $this, 'render_admin_page' )
        );
    }

    public function handle_flush_action() {
        if ( ! isset( $_POST['hazelcast_flush_cache'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['hazelcast_flush_nonce'] ) ||
             ! wp_verify_nonce( sanitize_key( $_POST['hazelcast_flush_nonce'] ), 'hazelcast_flush_cache' ) ) {
            return;
        }

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
            add_settings_error(
                'hazelcast_messages',
                'hazelcast_flushed',
                __( 'Cache flushed successfully.', 'hazelcast-object-cache' ),
                'updated'
            );
        }
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats     = array(
            'hits'         => 0,
            'misses'       => 0,
            'hit_ratio'    => 0,
            'uptime'       => 0,
            'server_stats' => array(),
        );
        $servers   = array();
        $connected = false;

        if ( function_exists( 'wp_cache_get_stats' ) ) {
            $stats = wp_cache_get_stats();
        }

        if ( class_exists( 'Hazelcast_WP_Object_Cache' ) ) {
            $cache     = Hazelcast_WP_Object_Cache::instance();
            $servers   = $cache->get_servers();
            $connected = $cache->is_connected();
        }

        $ratio  = $stats['hit_ratio'];
        $uptime = $stats['uptime'];

        settings_errors( 'hazelcast_messages' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <div class="card">
                <h2><?php esc_html_e( 'Connection Status', 'hazelcast-object-cache' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Status', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <?php if ( $connected ) : ?>
                                <span style="color: green;">&#10003; <?php esc_html_e( 'Connected', 'hazelcast-object-cache' ); ?></span>
                            <?php else : ?>
                                <span style="color: red;">&#10007; <?php esc_html_e( 'Disconnected', 'hazelcast-object-cache' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Servers', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <?php if ( ! empty( $servers ) ) : ?>
                                <ul style="margin: 0;">
                                    <?php foreach ( $servers as $server ) : ?>
                                        <li><code><?php echo esc_html( $server['host'] . ':' . $server['port'] ); ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <em><?php esc_html_e( 'No servers configured', 'hazelcast-object-cache' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e( 'Cache Statistics', 'hazelcast-object-cache' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cache Hits', 'hazelcast-object-cache' ); ?></th>
                        <td><strong><?php echo esc_html( number_format( $stats['hits'] ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cache Misses', 'hazelcast-object-cache' ); ?></th>
                        <td><strong><?php echo esc_html( number_format( $stats['misses'] ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Hit Ratio', 'hazelcast-object-cache' ); ?></th>
                        <td><strong><?php echo esc_html( $ratio ); ?>%</strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Server Uptime', 'hazelcast-object-cache' ); ?></th>
                        <td><strong><?php echo esc_html( $this->format_uptime( $uptime ) ); ?></strong></td>
                    </tr>
                </table>
            </div>

            <?php if ( ! empty( $stats['server_stats'] ) ) : ?>
            <div class="card">
                <h2><?php esc_html_e( 'Server Statistics', 'hazelcast-object-cache' ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Server', 'hazelcast-object-cache' ); ?></th>
                            <th><?php esc_html_e( 'Connections', 'hazelcast-object-cache' ); ?></th>
                            <th><?php esc_html_e( 'Items', 'hazelcast-object-cache' ); ?></th>
                            <th><?php esc_html_e( 'Memory Used', 'hazelcast-object-cache' ); ?></th>
                            <th><?php esc_html_e( 'Evictions', 'hazelcast-object-cache' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $stats['server_stats'] as $server => $data ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $server ); ?></code></td>
                            <td><?php echo esc_html( number_format( $data['curr_connections'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( number_format( $data['curr_items'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( $this->format_bytes( $data['bytes'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( number_format( $data['evictions'] ?? 0 ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php esc_html_e( 'Actions', 'hazelcast-object-cache' ); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field( 'hazelcast_flush_cache', 'hazelcast_flush_nonce' ); ?>
                    <p>
                        <input type="submit" name="hazelcast_flush_cache" class="button button-primary"
                               value="<?php esc_attr_e( 'Flush Cache', 'hazelcast-object-cache' ); ?>" />
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'This will clear all cached data from the Hazelcast cluster.', 'hazelcast-object-cache' ); ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private function format_uptime( $seconds ) {
        if ( $seconds < 60 ) {
            return sprintf( _n( '%d second', '%d seconds', $seconds, 'hazelcast-object-cache' ), $seconds );
        }
        if ( $seconds < 3600 ) {
            $minutes = floor( $seconds / 60 );
            return sprintf( _n( '%d minute', '%d minutes', $minutes, 'hazelcast-object-cache' ), $minutes );
        }
        if ( $seconds < 86400 ) {
            $hours = floor( $seconds / 3600 );
            return sprintf( _n( '%d hour', '%d hours', $hours, 'hazelcast-object-cache' ), $hours );
        }
        $days = floor( $seconds / 86400 );
        return sprintf( _n( '%d day', '%d days', $days, 'hazelcast-object-cache' ), $days );
    }

    private function format_bytes( $bytes ) {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $bytes = max( $bytes, 0 );
        $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow   = min( $pow, count( $units ) - 1 );
        $bytes /= pow( 1024, $pow );
        return round( $bytes, 2 ) . ' ' . $units[ $pow ];
    }
}
