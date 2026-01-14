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
        add_action( 'admin_init', array( $this, 'handle_flush_group_action' ) );
        add_action( 'wp_ajax_hazelcast_connection_test', array( $this, 'handle_connection_test' ) );
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

    public function handle_connection_test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hazelcast-object-cache' ) ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'hazelcast_connection_test' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'hazelcast-object-cache' ) ) );
        }

        if ( ! function_exists( 'wp_cache_set' ) || ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_delete' ) ) {
            wp_send_json_error( array( 'message' => __( 'Object cache functions not available.', 'hazelcast-object-cache' ) ) );
        }

        $test_key   = 'hazelcast_connection_test_' . time();
        $test_value = 'test_value_' . wp_generate_password( 8, false );
        $test_group = 'hazelcast_test';
        $results    = array();

        $start_set  = microtime( true );
        $set_result = wp_cache_set( $test_key, $test_value, $test_group, 60 );
        $end_set    = microtime( true );
        $results['set'] = array(
            'success' => $set_result,
            'latency' => round( ( $end_set - $start_set ) * 1000, 2 ),
        );

        if ( ! $set_result ) {
            wp_send_json_error( array(
                'message' => __( 'Failed to SET test value.', 'hazelcast-object-cache' ),
                'results' => $results,
            ) );
        }

        $start_get  = microtime( true );
        $get_result = wp_cache_get( $test_key, $test_group, true );
        $end_get    = microtime( true );
        $results['get'] = array(
            'success' => $get_result === $test_value,
            'latency' => round( ( $end_get - $start_get ) * 1000, 2 ),
        );

        if ( $get_result !== $test_value ) {
            wp_send_json_error( array(
                'message' => __( 'Failed to GET test value or value mismatch.', 'hazelcast-object-cache' ),
                'results' => $results,
            ) );
        }

        $start_delete  = microtime( true );
        $delete_result = wp_cache_delete( $test_key, $test_group );
        $end_delete    = microtime( true );
        $results['delete'] = array(
            'success' => $delete_result,
            'latency' => round( ( $end_delete - $start_delete ) * 1000, 2 ),
        );

        if ( ! $delete_result ) {
            wp_send_json_error( array(
                'message' => __( 'Failed to DELETE test value.', 'hazelcast-object-cache' ),
                'results' => $results,
            ) );
        }

        $results['total_latency'] = round(
            $results['set']['latency'] + $results['get']['latency'] + $results['delete']['latency'],
            2
        );

        wp_send_json_success( array(
            'message' => __( 'Connection test passed successfully!', 'hazelcast-object-cache' ),
            'results' => $results,
        ) );
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

    public function handle_flush_group_action() {
        if ( ! isset( $_POST['hazelcast_flush_group'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['hazelcast_flush_group_nonce'] ) ||
             ! wp_verify_nonce( sanitize_key( $_POST['hazelcast_flush_group_nonce'] ), 'hazelcast_flush_group' ) ) {
            return;
        }

        $group = isset( $_POST['hazelcast_cache_group'] ) ? sanitize_key( $_POST['hazelcast_cache_group'] ) : '';

        if ( empty( $group ) ) {
            add_settings_error(
                'hazelcast_messages',
                'hazelcast_flush_group_error',
                __( 'Please select a cache group to flush.', 'hazelcast-object-cache' ),
                'error'
            );
            return;
        }

        $allowed_groups = $this->get_flushable_groups();
        if ( ! isset( $allowed_groups[ $group ] ) ) {
            add_settings_error(
                'hazelcast_messages',
                'hazelcast_flush_group_error',
                __( 'Invalid cache group selected.', 'hazelcast-object-cache' ),
                'error'
            );
            return;
        }

        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( $group );
            add_settings_error(
                'hazelcast_messages',
                'hazelcast_group_flushed',
                sprintf(
                    /* translators: %s: cache group name */
                    __( 'Cache group "%s" flushed successfully.', 'hazelcast-object-cache' ),
                    esc_html( $allowed_groups[ $group ] )
                ),
                'updated'
            );
        }
    }

    private function get_flushable_groups() {
        return array(
            'options'        => __( 'Options', 'hazelcast-object-cache' ),
            'posts'          => __( 'Posts', 'hazelcast-object-cache' ),
            'terms'          => __( 'Terms', 'hazelcast-object-cache' ),
            'users'          => __( 'Users', 'hazelcast-object-cache' ),
            'transient'      => __( 'Transients', 'hazelcast-object-cache' ),
            'site-transient' => __( 'Site Transients', 'hazelcast-object-cache' ),
            'comment'        => __( 'Comments', 'hazelcast-object-cache' ),
            'counts'         => __( 'Counts', 'hazelcast-object-cache' ),
            'plugins'        => __( 'Plugins', 'hazelcast-object-cache' ),
            'themes'         => __( 'Themes', 'hazelcast-object-cache' ),
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats               = array(
            'hits'         => 0,
            'misses'       => 0,
            'hit_ratio'    => 0,
            'uptime'       => 0,
            'server_stats' => array(),
        );
        $servers             = array();
        $connected           = false;
        $last_error          = '';
        $fallback_mode       = false;
        $circuit_open        = false;
        $connection_failures = 0;

        if ( function_exists( 'wp_cache_get_stats' ) ) {
            $stats = wp_cache_get_stats();
        }

        if ( class_exists( 'Hazelcast_WP_Object_Cache' ) ) {
            $cache               = Hazelcast_WP_Object_Cache::instance();
            $servers             = $cache->get_servers();
            $connected           = $cache->is_connected();
            $last_error          = $cache->get_last_error();
            $fallback_mode       = $cache->is_fallback_mode();
            $circuit_open        = $cache->is_circuit_open();
            $connection_failures = $cache->get_connection_failures();
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
                    <?php if ( ! empty( $last_error ) ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Last Error', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <span style="color: red;"><code><?php echo esc_html( $last_error ); ?></code></span>
                        </td>
                    </tr>
                    <?php endif; ?>
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
            $dropin_status              = $this->get_dropin_status();
            $config                     = function_exists( 'wp_cache_get_config' ) ? wp_cache_get_config() : array();
            $fallback_retry_interval    = $config['fallback_retry_interval'] ?? 30;
            $circuit_breaker_threshold  = $config['circuit_breaker_threshold'] ?? 5;
            ?>

            <div class="card">
                <h2><?php esc_html_e( 'Connection Health', 'hazelcast-object-cache' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fallback Mode', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <?php if ( $fallback_mode ) : ?>
                                <span style="color: red;">&#10007; <?php esc_html_e( 'Active', 'hazelcast-object-cache' ); ?></span>
                                <p class="description"><?php esc_html_e( 'Cache operations are using local memory only. Hazelcast cluster is unreachable.', 'hazelcast-object-cache' ); ?></p>
                            <?php else : ?>
                                <span style="color: green;">&#10003; <?php esc_html_e( 'Inactive', 'hazelcast-object-cache' ); ?></span>
                                <p class="description"><?php esc_html_e( 'Cache is operating normally with Hazelcast cluster.', 'hazelcast-object-cache' ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Circuit Breaker', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <?php if ( $circuit_open ) : ?>
                                <span style="color: red;">&#10007; <?php esc_html_e( 'Open', 'hazelcast-object-cache' ); ?></span>
                                <p class="description"><?php esc_html_e( 'Connection attempts are paused to prevent cascading failures.', 'hazelcast-object-cache' ); ?></p>
                            <?php else : ?>
                                <span style="color: green;">&#10003; <?php esc_html_e( 'Closed', 'hazelcast-object-cache' ); ?></span>
                                <p class="description"><?php esc_html_e( 'Connection attempts are allowed normally.', 'hazelcast-object-cache' ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection Failures', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <?php
                            $failure_style = 'color: green;';
                            if ( $connection_failures > 0 && $connection_failures < $circuit_breaker_threshold ) {
                                $failure_style = 'color: orange;';
                            } elseif ( $connection_failures >= $circuit_breaker_threshold ) {
                                $failure_style = 'color: red;';
                            }
                            ?>
                            <span style="<?php echo esc_attr( $failure_style ); ?>">
                                <strong><?php echo esc_html( $connection_failures ); ?></strong> / <?php echo esc_html( $circuit_breaker_threshold ); ?>
                            </span>
                            <p class="description">
                                <?php esc_html_e( 'Consecutive failures before circuit breaker opens.', 'hazelcast-object-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Retry Interval', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <strong><?php printf( esc_html__( '%d seconds', 'hazelcast-object-cache' ), $fallback_retry_interval ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Time between reconnection attempts in fallback mode. Set via HAZELCAST_FALLBACK_RETRY_INTERVAL constant.', 'hazelcast-object-cache' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Circuit Breaker Threshold', 'hazelcast-object-cache' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( $circuit_breaker_threshold ); ?> <?php esc_html_e( 'failures', 'hazelcast-object-cache' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Maximum consecutive failures before circuit opens. Set via HAZELCAST_CIRCUIT_BREAKER_THRESHOLD constant.', 'hazelcast-object-cache' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

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

                    $tls_supported = isset( $config['tls_supported'] ) && $config['tls_supported'];
                    $tls_enabled   = isset( $config['tls_enabled'] ) && $config['tls_enabled'];
                    if ( ! $tls_supported ) {
                        $tls_value = __( 'Not supported (Memcached extension too old)', 'hazelcast-object-cache' );
                        $tls_valid = false;
                    } elseif ( $tls_enabled ) {
                        $tls_value = __( 'Enabled', 'hazelcast-object-cache' );
                        $tls_valid = true;
                    } else {
                        $tls_value = __( 'Disabled', 'hazelcast-object-cache' );
                        $tls_valid = true;
                    }
                    $this->render_config_row(
                        __( 'TLS/SSL', 'hazelcast-object-cache' ),
                        $tls_value,
                        $tls_valid,
                        __( 'Encrypts connections to Hazelcast. Set via HAZELCAST_TLS_ENABLED constant.', 'hazelcast-object-cache' )
                    );

                    if ( $tls_enabled ) {
                        $cert_path  = $config['tls_cert_path'] ?? '';
                        $cert_valid = ! empty( $cert_path ) && file_exists( $cert_path );
                        $cert_value = $cert_valid ? basename( $cert_path ) : __( 'Not configured', 'hazelcast-object-cache' );
                        $this->render_config_row(
                            __( 'TLS Certificate', 'hazelcast-object-cache' ),
                            $cert_value,
                            true,
                            __( 'Client certificate file path. Set via HAZELCAST_TLS_CERT_PATH constant.', 'hazelcast-object-cache' )
                        );

                        $key_path  = $config['tls_key_path'] ?? '';
                        $key_valid = ! empty( $key_path ) && file_exists( $key_path );
                        $key_value = $key_valid ? basename( $key_path ) : __( 'Not configured', 'hazelcast-object-cache' );
                        $this->render_config_row(
                            __( 'TLS Private Key', 'hazelcast-object-cache' ),
                            $key_value,
                            true,
                            __( 'Client private key file path. Set via HAZELCAST_TLS_KEY_PATH constant.', 'hazelcast-object-cache' )
                        );

                        $ca_path  = $config['tls_ca_path'] ?? '';
                        $ca_value = ! empty( $ca_path ) ? basename( $ca_path ) : __( 'System default', 'hazelcast-object-cache' );
                        $this->render_config_row(
                            __( 'TLS CA Certificate', 'hazelcast-object-cache' ),
                            $ca_value,
                            true,
                            __( 'CA certificate file for server verification. Set via HAZELCAST_TLS_CA_PATH constant.', 'hazelcast-object-cache' )
                        );

                        $verify_peer = $config['tls_verify_peer'] ?? true;
                        $verify_value = $verify_peer ? __( 'Enabled', 'hazelcast-object-cache' ) : __( 'Disabled', 'hazelcast-object-cache' );
                        $this->render_config_row(
                            __( 'TLS Peer Verification', 'hazelcast-object-cache' ),
                            $verify_value,
                            $verify_peer,
                            __( 'Validates server certificate. Set via HAZELCAST_TLS_VERIFY_PEER constant.', 'hazelcast-object-cache' )
                        );
                    }

                    $debug_value = isset( $config['debug'] ) && $config['debug'] ? __( 'Enabled', 'hazelcast-object-cache' ) : __( 'Disabled', 'hazelcast-object-cache' );
                    $this->render_config_row(
                        __( 'Debug Logging', 'hazelcast-object-cache' ),
                        $debug_value,
                        true,
                        __( 'Logs cache operations to error_log() for debugging. Set via HAZELCAST_DEBUG constant.', 'hazelcast-object-cache' )
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
                <hr />
                <h3><?php esc_html_e( 'Selective Group Flush', 'hazelcast-object-cache' ); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field( 'hazelcast_flush_group', 'hazelcast_flush_group_nonce' ); ?>
                    <p>
                        <label for="hazelcast_cache_group"><?php esc_html_e( 'Select Group:', 'hazelcast-object-cache' ); ?></label>
                        <select name="hazelcast_cache_group" id="hazelcast_cache_group">
                            <option value=""><?php esc_html_e( '— Select a group —', 'hazelcast-object-cache' ); ?></option>
                            <?php foreach ( $this->get_flushable_groups() as $group_key => $group_label ) : ?>
                                <option value="<?php echo esc_attr( $group_key ); ?>"><?php echo esc_html( $group_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="submit" name="hazelcast_flush_group" class="button button-secondary"
                               value="<?php esc_attr_e( 'Flush Group', 'hazelcast-object-cache' ); ?>" />
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Invalidates all cached data for the selected group using group versioning.', 'hazelcast-object-cache' ); ?>
                    </p>
                </form>
                <hr />
                <p>
                    <button type="button" id="hazelcast-connection-test" class="button button-secondary">
                        <?php esc_attr_e( 'Test Connection', 'hazelcast-object-cache' ); ?>
                    </button>
                    <span id="hazelcast-test-spinner" class="spinner" style="float: none; margin-top: 0;"></span>
                </p>
                <p class="description">
                    <?php esc_html_e( 'Performs a set/get/delete cycle to verify the connection and measure latency.', 'hazelcast-object-cache' ); ?>
                </p>
                <div id="hazelcast-test-results" style="display: none; margin-top: 15px;">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Operation', 'hazelcast-object-cache' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'hazelcast-object-cache' ); ?></th>
                                <th><?php esc_html_e( 'Latency', 'hazelcast-object-cache' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>SET</td>
                                <td id="hazelcast-test-set-status">-</td>
                                <td id="hazelcast-test-set-latency">-</td>
                            </tr>
                            <tr>
                                <td>GET</td>
                                <td id="hazelcast-test-get-status">-</td>
                                <td id="hazelcast-test-get-latency">-</td>
                            </tr>
                            <tr>
                                <td>DELETE</td>
                                <td id="hazelcast-test-delete-status">-</td>
                                <td id="hazelcast-test-delete-latency">-</td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Total', 'hazelcast-object-cache' ); ?></th>
                                <td id="hazelcast-test-total-status">-</td>
                                <td id="hazelcast-test-total-latency">-</td>
                            </tr>
                        </tbody>
                    </table>
                    <div id="hazelcast-test-message" style="margin-top: 10px;"></div>
                </div>
            </div>

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#hazelcast-connection-test').on('click', function() {
                    var $button = $(this);
                    var $spinner = $('#hazelcast-test-spinner');
                    var $results = $('#hazelcast-test-results');
                    var $message = $('#hazelcast-test-message');

                    $button.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $results.hide();
                    $message.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'hazelcast_connection_test',
                            nonce: '<?php echo esc_js( wp_create_nonce( 'hazelcast_connection_test' ) ); ?>'
                        },
                        success: function(response) {
                            $results.show();

                            var statusIcon = function(success) {
                                return success
                                    ? '<span style="color: green;">&#10003;</span>'
                                    : '<span style="color: red;">&#10007;</span>';
                            };

                            if (response.data && response.data.results) {
                                var r = response.data.results;

                                if (r.set) {
                                    $('#hazelcast-test-set-status').html(statusIcon(r.set.success));
                                    $('#hazelcast-test-set-latency').text(r.set.latency + ' ms');
                                }
                                if (r.get) {
                                    $('#hazelcast-test-get-status').html(statusIcon(r.get.success));
                                    $('#hazelcast-test-get-latency').text(r.get.latency + ' ms');
                                }
                                if (r.delete) {
                                    $('#hazelcast-test-delete-status').html(statusIcon(r.delete.success));
                                    $('#hazelcast-test-delete-latency').text(r.delete.latency + ' ms');
                                }
                                if (r.total_latency !== undefined) {
                                    $('#hazelcast-test-total-status').html(statusIcon(response.success));
                                    $('#hazelcast-test-total-latency').html('<strong>' + r.total_latency + ' ms</strong>');
                                }
                            }

                            if (response.success) {
                                $message.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                            } else {
                                $message.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $results.show();
                            $message.html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'AJAX request failed:', 'hazelcast-object-cache' ) ); ?> ' + error + '</p></div>');
                        },
                        complete: function() {
                            $button.prop('disabled', false);
                            $spinner.removeClass('is-active');
                        }
                    });
                });
            });
            </script>
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
