<?php
/**
 * Unit tests for Hazelcast Object Cache fallback behavior.
 */

use PHPUnit\Framework\TestCase;

class Object_Cache_Fallback_Test extends TestCase {
    private $cache;
    private $reflection;

    protected function setUp(): void {
        $class = new ReflectionClass( 'Hazelcast_WP_Object_Cache' );

        $instance_prop = $class->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );

        $this->cache      = Hazelcast_WP_Object_Cache::instance();
        $this->reflection = $class;
    }

    protected function tearDown(): void {
        $class = new ReflectionClass( 'Hazelcast_WP_Object_Cache' );

        $instance_prop = $class->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );
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

    public function test_is_fallback_mode_initially_false(): void {
        $this->assertFalse( $this->cache->is_fallback_mode() );
    }

    public function test_fallback_mode_in_config(): void {
        $config = $this->cache->get_config();
        $this->assertArrayHasKey( 'fallback_mode', $config );
        $this->assertArrayHasKey( 'fallback_retry_interval', $config );
    }

    public function test_set_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $result = $this->cache->set( 'fallback_key', 'fallback_value', 'default' );
        $this->assertTrue( $result );

        $found = null;
        $value = $this->cache->get( 'fallback_key', 'default', false, $found );
        $this->assertTrue( $found );
        $this->assertEquals( 'fallback_value', $value );
    }

    public function test_add_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $result = $this->cache->add( 'fallback_add_key', 'add_value', 'default' );
        $this->assertTrue( $result );

        $result2 = $this->cache->add( 'fallback_add_key', 'another_value', 'default' );
        $this->assertFalse( $result2 );
    }

    public function test_delete_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_delete_key', 'value', 'default' );
        $result = $this->cache->delete( 'fallback_delete_key', 'default' );
        $this->assertTrue( $result );

        $found = null;
        $this->cache->get( 'fallback_delete_key', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_replace_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $result_no_key = $this->cache->replace( 'nonexistent_key', 'value', 'default' );
        $this->assertFalse( $result_no_key );

        $this->cache->set( 'fallback_replace_key', 'original', 'default' );
        $result = $this->cache->replace( 'fallback_replace_key', 'replaced', 'default' );
        $this->assertTrue( $result );

        $value = $this->cache->get( 'fallback_replace_key', 'default' );
        $this->assertEquals( 'replaced', $value );
    }

    public function test_incr_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_incr_key', 10, 'default' );
        $result = $this->cache->incr( 'fallback_incr_key', 5, 'default' );
        $this->assertEquals( 15, $result );
    }

    public function test_decr_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_decr_key', 10, 'default' );
        $result = $this->cache->decr( 'fallback_decr_key', 3, 'default' );
        $this->assertEquals( 7, $result );
    }

    public function test_decr_below_zero_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_decr_zero', 5, 'default' );
        $result = $this->cache->decr( 'fallback_decr_zero', 10, 'default' );
        $this->assertEquals( 0, $result );
    }

    public function test_flush_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'key1', 'value1', 'default' );
        $this->cache->set( 'key2', 'value2', 'default' );

        $result = $this->cache->flush();
        $this->assertTrue( $result );

        $found = null;
        $this->cache->get( 'key1', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_flush_group_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'key1', 'value1', 'mygroup' );
        $this->cache->set( 'key2', 'value2', 'othergroup' );

        $result = $this->cache->flush_group( 'mygroup' );
        $this->assertTrue( $result );
    }

    public function test_cas_works_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_cas_key', 'original', 'default' );
        $result = $this->cache->cas( 123.0, 'fallback_cas_key', 'updated', 'default' );
        $this->assertTrue( $result );

        $value = $this->cache->get( 'fallback_cas_key', 'default' );
        $this->assertEquals( 'updated', $value );
    }

    public function test_cas_with_null_token_returns_false(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $this->cache->set( 'fallback_cas_null', 'value', 'default' );
        $result = $this->cache->cas( null, 'fallback_cas_null', 'updated', 'default' );
        $this->assertFalse( $result );
    }

    public function test_retry_interval_respected(): void {
        $this->set_fallback_mode( true );
        $this->set_fallback_retry_interval( 60 );
        $this->set_last_connection_attempt( time() - 30 );

        $method = $this->reflection->getMethod( 'maybe_retry_connection' );
        $method->setAccessible( true );

        $result = $method->invoke( $this->cache );
        $this->assertFalse( $result );
        $this->assertTrue( $this->cache->is_fallback_mode() );
    }

    public function test_global_function_is_fallback_mode(): void {
        $this->assertFalse( wp_cache_is_fallback_mode() );

        $this->set_fallback_mode( true );
        $this->assertTrue( wp_cache_is_fallback_mode() );
    }

    public function test_incr_nonexistent_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $result = $this->cache->incr( 'nonexistent_incr', 1, 'default' );
        $this->assertFalse( $result );
    }

    public function test_decr_nonexistent_in_fallback_mode(): void {
        $this->set_fallback_mode( true );
        $this->set_last_connection_attempt( time() );

        $result = $this->cache->decr( 'nonexistent_decr', 1, 'default' );
        $this->assertFalse( $result );
    }
}
