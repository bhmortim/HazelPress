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

            <?php
            $dropin_status = $this->get_dropin_status();
            $config        = function_exists( 'wp_cache_get_config' ) ? wp_cache_get_config() : array();
            ?>
            <div class="card">
                <h2><?php esc_html_e( 'Configuration', 'hazelcast-object-cache' ); ?></h2>
                <table class="form-table">
                    <?php
                    $this->render_config_row(
                        __( 'Drop-in Status', 'hazelcast-object-cache' ),
                        $dropin_status['message'],
                        $dropin_status['valid'],
                        __( 'The object-cache.php drop-in must be installed in wp-content for caching to work.', 'hazelcast-object-cache' )
                    );

                    $servers_value = $config['servers'] ?? '127.0.0.1:5701';
                    $servers_valid = ! empty( $servers_value );
                    $this->render_config_row(
                        __( 'Servers', 'hazelcast-object-cache' ),
                        $servers_value,
                        $servers_valid,
                        __( 'Hazelcast cluster endpoints. Set via HAZELCAST_SERVERS constant.', 'hazelcast-object-cache' )
                    );

                    $compression_value = isset( $config['compression'] ) && $config['compression'] ? __( 'Enabled', 'hazelcast-object-cache' ) : __( 'Disabled', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'Compression', 'hazelcast-object-cache' ),
                        $compression_value,
                        true,
                        __( 'Compresses cached data to reduce memory usage. Set via HAZELCAST_COMPRESSION constant.', 'hazelcast-object-cache' )
                    );

                    $timeout_value = isset( $config['timeout'] ) && null !== $config['timeout']
                        ? sprintf( __( '%d seconds', 'hazelcast-object-cache' ), $config['timeout'] )
                        : __( 'Default', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'Connection Timeout', 'hazelcast-object-cache' ),
                        $timeout_value,
                        true,
                        __( 'Maximum time to wait for server connection. Set via HAZELCAST_TIMEOUT constant.', 'hazelcast-object-cache' )
                    );

                    $retry_value = isset( $config['retry_timeout'] ) && null !== $config['retry_timeout']
                        ? sprintf( __( '%d seconds', 'hazelcast-object-cache' ), $config['retry_timeout'] )
                        : __( 'Default', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'Retry Timeout', 'hazelcast-object-cache' ),
                        $retry_value,
                        true,
                        __( 'Time before retrying a failed server. Set via HAZELCAST_RETRY_TIMEOUT constant.', 'hazelcast-object-cache' )
                    );

                    $serializer_value = $config['serializer'] ?? 'php';
                    $serializer_valid = in_array( $serializer_value, array( 'php', 'igbinary' ), true );
                    $this->render_config_row(
                        __( 'Serializer', 'hazelcast-object-cache' ),
                        strtoupper( $serializer_value ),
                        $serializer_valid,
                        __( 'Method used to serialize cached data. igbinary offers better performance if available. Set via HAZELCAST_SERIALIZER constant.', 'hazelcast-object-cache' )
                    );

                    $prefix_value = $config['key_prefix'] ?? '';
                    $prefix_valid = ! empty( $prefix_value );
                    $this->render_config_row(
                        __( 'Key Prefix', 'hazelcast-object-cache' ),
                        $prefix_value,
                        $prefix_valid,
                        __( 'Prefix added to all cache keys to prevent collisions. Set via HAZELCAST_KEY_PREFIX constant.', 'hazelcast-object-cache' )
                    );

                    $tcp_nodelay_value = isset( $config['tcp_nodelay'] ) && null !== $config['tcp_nodelay']
                        ? ( $config['tcp_nodelay'] ? __( 'Enabled', 'hazelcast-object-cache' ) : __( 'Disabled', 'hazelcast-object-cache' ) )
                        : __( 'Default', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'TCP No Delay', 'hazelcast-object-cache' ),
                        $tcp_nodelay_value,
                        true,
                        __( 'Disables Nagle algorithm for lower latency. Set via HAZELCAST_TCP_NODELAY constant.', 'hazelcast-object-cache' )
                    );

                    $auth_value = isset( $config['authentication'] ) && $config['authentication'] ? __( 'Configured', 'hazelcast-object-cache' ) : __( 'Not configured', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'Authentication', 'hazelcast-object-cache' ),
                        $auth_value,
                        true,
                        __( 'SASL authentication credentials. Set via HAZELCAST_USERNAME and HAZELCAST_PASSWORD constants.', 'hazelcast-object-cache' )
                    );
                    ?>
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

    private function get_dropin_status() {
        $dropin_path = WP_CONTENT_DIR . '/object-cache.php';

        if ( ! file_exists( $dropin_path ) ) {
            return array(
                'installed' => false,
                'valid'     => false,
                'message'   => __( 'Drop-in not installed', 'hazelcast-object-cache' ),
            );
        }

        $content = file_get_contents( $dropin_path );
        if ( false === $content ) {
            return array(
                'installed' => true,
                'valid'     => false,
                'message'   => __( 'Unable to read drop-in file', 'hazelcast-object-cache' ),
            );
        }

        if ( strpos( $content, 'Hazelcast_WP_Object_Cache' ) !== false ) {
            return array(
                'installed' => true,
                'valid'     => true,
                'message'   => __( 'Hazelcast drop-in active', 'hazelcast-object-cache' ),
            );
        }

        return array(
            'installed' => true,
            'valid'     => false,
            'message'   => __( 'Different object cache drop-in installed', 'hazelcast-object-cache' ),
        );
    }

    private function render_config_row( $label, $value, $valid, $description ) {
        $icon = $valid
            ? '<span style="color: green;">&#10003;</span>'
            : '<span style="color: red;">&#10007;</span>';
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <?php echo $icon; ?>
                <strong><?php echo esc_html( $value ); ?></strong>
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </td>
        </tr>
        <?php
    }
}
