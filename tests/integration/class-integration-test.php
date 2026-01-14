<?php
/**
 * Integration tests for Hazelcast Object Cache against a real Hazelcast instance.
 *
 * Requires: Memcached PECL extension and running Hazelcast container.
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( dirname( __DIR__ ) ) . '/' );
}

if ( ! defined( 'WP_CACHE' ) ) {
    define( 'WP_CACHE', true );
}

if ( ! defined( 'HAZELCAST_SERVERS' ) ) {
    define( 'HAZELCAST_SERVERS', '127.0.0.1:5701' );
}

if ( ! function_exists( 'site_url' ) ) {
    function site_url() {
        return 'http://integration-test.local';
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

class Integration_Test extends TestCase {

    private static $skip_tests = false;
    private static $skip_reason = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if ( ! extension_loaded( 'memcached' ) ) {
            self::$skip_tests  = true;
            self::$skip_reason = 'Memcached extension not available';
            return;
        }

        $memcached = new Memcached();
        $memcached->addServer( '127.0.0.1', 5701 );
        $stats = @$memcached->getStats();

        if ( empty( $stats ) ) {
            self::$skip_tests  = true;
            self::$skip_reason = 'Hazelcast not available at 127.0.0.1:5701';
            return;
        }

        require_once dirname( dirname( __DIR__ ) ) . '/includes/object-cache.php';
    }

    protected function setUp(): void {
        parent::setUp();

        if ( self::$skip_tests ) {
            $this->markTestSkipped( self::$skip_reason );
        }

        wp_cache_init();
        wp_cache_flush();
    }

    protected function tearDown(): void {
        if ( ! self::$skip_tests ) {
            wp_cache_flush();
        }
        parent::tearDown();
    }

    public function test_connection(): void {
        $this->assertTrue( wp_cache_is_connected(), 'Should be connected to Hazelcast' );
    }

    public function test_servers_configured(): void {
        $servers = wp_cache_get_servers();
        $this->assertNotEmpty( $servers, 'Server list should not be empty' );
        $this->assertEquals( '127.0.0.1', $servers[0]['host'] );
        $this->assertEquals( 5701, $servers[0]['port'] );
    }

    public function test_set_and_get(): void {
        $result = wp_cache_set( 'integration_key', 'integration_value' );
        $this->assertTrue( $result, 'Set should succeed' );

        $value = wp_cache_get( 'integration_key' );
        $this->assertEquals( 'integration_value', $value );
    }

    public function test_set_with_expiration(): void {
        $result = wp_cache_set( 'expiring_key', 'expiring_value', 'default', 3600 );
        $this->assertTrue( $result );

        $value = wp_cache_get( 'expiring_key' );
        $this->assertEquals( 'expiring_value', $value );
    }

    public function test_add(): void {
        $result = wp_cache_add( 'add_key', 'add_value' );
        $this->assertTrue( $result, 'First add should succeed' );

        $result = wp_cache_add( 'add_key', 'new_value' );
        $this->assertFalse( $result, 'Second add should fail' );

        $this->assertEquals( 'add_value', wp_cache_get( 'add_key' ) );
    }

    public function test_replace(): void {
        $result = wp_cache_replace( 'replace_key', 'value' );
        $this->assertFalse( $result, 'Replace nonexistent should fail' );

        wp_cache_set( 'replace_key', 'original' );
        $result = wp_cache_replace( 'replace_key', 'replaced' );
        $this->assertTrue( $result, 'Replace existing should succeed' );

        $this->assertEquals( 'replaced', wp_cache_get( 'replace_key' ) );
    }

    public function test_delete(): void {
        wp_cache_set( 'delete_key', 'delete_value' );

        $result = wp_cache_delete( 'delete_key' );
        $this->assertTrue( $result );

        $found = null;
        wp_cache_get( 'delete_key', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_increment(): void {
        wp_cache_set( 'incr_key', 10 );

        $result = wp_cache_incr( 'incr_key', 5 );
        $this->assertEquals( 15, $result );

        $value = wp_cache_get( 'incr_key' );
        $this->assertEquals( 15, $value );
    }

    public function test_decrement(): void {
        wp_cache_set( 'decr_key', 10 );

        $result = wp_cache_decr( 'decr_key', 3 );
        $this->assertEquals( 7, $result );

        $value = wp_cache_get( 'decr_key' );
        $this->assertEquals( 7, $value );
    }

    public function test_flush(): void {
        wp_cache_set( 'flush_key1', 'value1' );
        wp_cache_set( 'flush_key2', 'value2' );

        $result = wp_cache_flush();
        $this->assertTrue( $result );

        $found = null;
        wp_cache_get( 'flush_key1', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_complex_data_types(): void {
        $array_data = array(
            'string' => 'value',
            'number' => 42,
            'nested' => array( 'a', 'b', 'c' ),
        );
        wp_cache_set( 'array_key', $array_data );
        $this->assertEquals( $array_data, wp_cache_get( 'array_key' ) );

        $object       = new stdClass();
        $object->name = 'test';
        $object->data = array( 1, 2, 3 );
        wp_cache_set( 'object_key', $object );
        $this->assertEquals( $object, wp_cache_get( 'object_key' ) );
    }

    public function test_groups(): void {
        wp_cache_set( 'grouped_key', 'value_default', 'default' );
        wp_cache_set( 'grouped_key', 'value_custom', 'custom_group' );

        $this->assertEquals( 'value_default', wp_cache_get( 'grouped_key', 'default' ) );
        $this->assertEquals( 'value_custom', wp_cache_get( 'grouped_key', 'custom_group' ) );
    }

    public function test_get_stats(): void {
        wp_cache_set( 'stats_key', 'stats_value' );
        wp_cache_get( 'stats_key' );
        wp_cache_get( 'nonexistent_key' );

        $stats = wp_cache_get_stats();

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'hits', $stats );
        $this->assertArrayHasKey( 'misses', $stats );
        $this->assertArrayHasKey( 'hit_ratio', $stats );
        $this->assertArrayHasKey( 'uptime', $stats );
        $this->assertArrayHasKey( 'server_stats', $stats );
    }

    public function test_cas_operations(): void {
        wp_cache_set( 'cas_key', 'initial_value' );

        $cas_token = null;
        $found     = null;
        $value     = wp_cache_get_with_cas( 'cas_key', 'default', $cas_token, false, $found );

        $this->assertTrue( $found );
        $this->assertEquals( 'initial_value', $value );
        $this->assertNotNull( $cas_token );

        $result = wp_cache_cas( $cas_token, 'cas_key', 'updated_value' );
        $this->assertTrue( $result );

        $this->assertEquals( 'updated_value', wp_cache_get( 'cas_key' ) );
    }

    public function test_large_value(): void {
        $large_data = str_repeat( 'x', 100000 );
        wp_cache_set( 'large_key', $large_data );

        $retrieved = wp_cache_get( 'large_key' );
        $this->assertEquals( $large_data, $retrieved );
    }

    public function test_special_characters_in_key(): void {
        $key = 'special:key:with:colons';
        wp_cache_set( $key, 'special_value' );
        $this->assertEquals( 'special_value', wp_cache_get( $key ) );
    }

    public function test_close(): void {
        $result = wp_cache_close();
        $this->assertTrue( $result );
    }
}
