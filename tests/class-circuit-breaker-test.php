<?php
/**
 * Circuit Breaker Tests
 *
 * Tests for the circuit breaker pattern implementation in Hazelcast Object Cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use PHPUnit\Framework\TestCase;

class Circuit_Breaker_Test extends TestCase {
    private $cache;
    private $reflection;

    protected function setUp(): void {
        Memcached::resetPersistentInstances();
        Hazelcast_WP_Object_Cache::instance();
        $this->cache      = Hazelcast_WP_Object_Cache::instance();
        $this->reflection = new ReflectionClass( $this->cache );
    }

    protected function tearDown(): void {
        $instance_prop = $this->reflection->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );
        Memcached::resetPersistentInstances();
    }

    private function set_connection_failures( int $count ): void {
        $prop = $this->reflection->getProperty( 'connection_failures' );
        $prop->setAccessible( true );
        $prop->setValue( $this->cache, $count );
    }

    private function get_connection_failures(): int {
        $prop = $this->reflection->getProperty( 'connection_failures' );
        $prop->setAccessible( true );
        return $prop->getValue( $this->cache );
    }

    private function set_circuit_breaker_threshold( int $threshold ): void {
        $prop = $this->reflection->getProperty( 'circuit_breaker_threshold' );
        $prop->setAccessible( true );
        $prop->setValue( $this->cache, $threshold );
    }

    private function set_fallback_mode( bool $enabled ): void {
        $prop = $this->reflection->getProperty( 'fallback_mode' );
        $prop->setAccessible( true );
        $prop->setValue( $this->cache, $enabled );
    }

    private function set_last_connection_attempt( int $timestamp ): void {
        $prop = $this->reflection->getProperty( 'last_connection_attempt' );
        $prop->setAccessible( true );
        $prop->setValue( $this->cache, $timestamp );
    }

    private function set_fallback_retry_interval( int $seconds ): void {
        $prop = $this->reflection->getProperty( 'fallback_retry_interval' );
        $prop->setAccessible( true );
        $prop->setValue( $this->cache, $seconds );
    }

    private function call_should_attempt_connection(): bool {
        $method = $this->reflection->getMethod( 'should_attempt_connection' );
        $method->setAccessible( true );
        return $method->invoke( $this->cache );
    }

    private function call_enter_fallback_mode(): void {
        $method = $this->reflection->getMethod( 'enter_fallback_mode' );
        $method->setAccessible( true );
        $method->invoke( $this->cache );
    }

    private function call_exit_fallback_mode(): void {
        $method = $this->reflection->getMethod( 'exit_fallback_mode' );
        $method->setAccessible( true );
        $method->invoke( $this->cache );
    }

    public function test_circuit_initially_closed(): void {
        $this->assertFalse( $this->cache->is_circuit_open() );
        $this->assertEquals( 0, $this->cache->get_connection_failures() );
    }

    public function test_circuit_opens_after_threshold_failures(): void {
        $this->set_circuit_breaker_threshold( 3 );

        $this->call_enter_fallback_mode();
        $this->assertFalse( $this->cache->is_circuit_open() );
        $this->assertEquals( 1, $this->get_connection_failures() );

        $this->call_enter_fallback_mode();
        $this->assertFalse( $this->cache->is_circuit_open() );
        $this->assertEquals( 2, $this->get_connection_failures() );

        $this->call_enter_fallback_mode();
        $this->assertTrue( $this->cache->is_circuit_open() );
        $this->assertEquals( 3, $this->get_connection_failures() );
    }

    public function test_circuit_resets_on_exit_fallback_mode(): void {
        $this->set_circuit_breaker_threshold( 3 );
        $this->set_connection_failures( 5 );
        $this->set_fallback_mode( true );

        $this->assertTrue( $this->cache->is_circuit_open() );

        $this->call_exit_fallback_mode();

        $this->assertFalse( $this->cache->is_circuit_open() );
        $this->assertEquals( 0, $this->get_connection_failures() );
    }

    public function test_should_attempt_connection_below_threshold(): void {
        $this->set_circuit_breaker_threshold( 5 );
        $this->set_connection_failures( 3 );

        $this->assertTrue( $this->call_should_attempt_connection() );
    }

    public function test_should_not_attempt_connection_at_threshold_before_cooldown(): void {
        $this->set_circuit_breaker_threshold( 5 );
        $this->set_connection_failures( 5 );
        $this->set_fallback_retry_interval( 30 );
        $this->set_last_connection_attempt( time() );

        $this->assertFalse( $this->call_should_attempt_connection() );
    }

    public function test_should_attempt_connection_after_cooldown(): void {
        $this->set_circuit_breaker_threshold( 5 );
        $this->set_connection_failures( 5 );
        $this->set_fallback_retry_interval( 30 );
        $this->set_last_connection_attempt( time() - 31 );

        $this->assertTrue( $this->call_should_attempt_connection() );
    }

    public function test_default_threshold_is_five(): void {
        $prop = $this->reflection->getProperty( 'circuit_breaker_threshold' );
        $prop->setAccessible( true );
        $threshold = $prop->getValue( $this->cache );

        $this->assertEquals( 5, $threshold );
    }

    public function test_get_config_includes_circuit_breaker_info(): void {
        $this->set_circuit_breaker_threshold( 10 );
        $this->set_connection_failures( 3 );

        $config = $this->cache->get_config();

        $this->assertArrayHasKey( 'circuit_breaker_threshold', $config );
        $this->assertArrayHasKey( 'circuit_breaker_open', $config );
        $this->assertArrayHasKey( 'connection_failures', $config );
        $this->assertEquals( 10, $config['circuit_breaker_threshold'] );
        $this->assertFalse( $config['circuit_breaker_open'] );
        $this->assertEquals( 3, $config['connection_failures'] );
    }

    public function test_global_function_is_circuit_open(): void {
        $this->set_circuit_breaker_threshold( 2 );
        $this->set_connection_failures( 2 );

        $this->assertTrue( wp_cache_is_circuit_open() );
    }

    public function test_global_function_get_connection_failures(): void {
        $this->set_connection_failures( 7 );

        $this->assertEquals( 7, wp_cache_get_connection_failures() );
    }

    public function test_operations_work_with_open_circuit_in_fallback(): void {
        $this->set_circuit_breaker_threshold( 3 );
        $this->set_connection_failures( 5 );
        $this->set_fallback_mode( true );

        $this->assertTrue( $this->cache->is_circuit_open() );
        $this->assertTrue( $this->cache->is_fallback_mode() );

        $this->assertTrue( $this->cache->set( 'test_key', 'test_value' ) );

        $found = null;
        $value = $this->cache->get( 'test_key', 'default', false, $found );
        $this->assertTrue( $found );
        $this->assertEquals( 'test_value', $value );
    }

    public function test_circuit_breaker_increments_on_reconnection_failure(): void {
        $this->set_fallback_mode( true );
        $this->set_circuit_breaker_threshold( 10 );
        $this->set_connection_failures( 2 );
        $this->set_fallback_retry_interval( 0 );
        $this->set_last_connection_attempt( time() - 100 );

        $memcached_prop = $this->reflection->getProperty( 'memcached' );
        $memcached_prop->setAccessible( true );
        $memcached = $memcached_prop->getValue( $this->cache );

        $servers_reflection = new ReflectionClass( $memcached );
        $servers_prop       = $servers_reflection->getProperty( 'servers' );
        $servers_prop->setAccessible( true );
        $servers_prop->setValue( $memcached, array() );

        $method = $this->reflection->getMethod( 'maybe_retry_connection' );
        $method->setAccessible( true );
        $result = $method->invoke( $this->cache );

        $this->assertFalse( $result );
        $this->assertEquals( 3, $this->get_connection_failures() );
    }
}
