<?php
/**
 * PHPUnit tests for Hazelcast Object Cache.
 */

use PHPUnit\Framework\TestCase;

class Object_Cache_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Memcached::resetPersistentInstances();
        wp_cache_init();
        wp_cache_flush();
    }

    protected function tearDown(): void {
        wp_cache_flush();
        parent::tearDown();
    }

    public function test_add(): void {
        $result = wp_cache_add( 'test_key', 'test_value' );
        $this->assertTrue( $result );

        $result = wp_cache_add( 'test_key', 'new_value' );
        $this->assertFalse( $result );

        $value = wp_cache_get( 'test_key' );
        $this->assertEquals( 'test_value', $value );
    }

    public function test_add_with_group(): void {
        $result = wp_cache_add( 'key1', 'value1', 'group1' );
        $this->assertTrue( $result );

        $result = wp_cache_add( 'key1', 'value2', 'group2' );
        $this->assertTrue( $result );

        $this->assertEquals( 'value1', wp_cache_get( 'key1', 'group1' ) );
        $this->assertEquals( 'value2', wp_cache_get( 'key1', 'group2' ) );
    }

    public function test_get(): void {
        wp_cache_set( 'existing_key', 'existing_value' );

        $found = null;
        $value = wp_cache_get( 'existing_key', 'default', false, $found );
        $this->assertEquals( 'existing_value', $value );
        $this->assertTrue( $found );

        $found = null;
        $value = wp_cache_get( 'nonexistent_key', 'default', false, $found );
        $this->assertFalse( $value );
        $this->assertFalse( $found );
    }

    public function test_get_with_force(): void {
        wp_cache_set( 'force_key', 'original_value' );
        wp_cache_get( 'force_key' );

        wp_cache_set( 'force_key', 'updated_value' );

        $value = wp_cache_get( 'force_key', 'default', true );
        $this->assertEquals( 'updated_value', $value );
    }

    public function test_get_with_cas(): void {
        wp_cache_set( 'cas_key', 'cas_value' );

        $cas_token = null;
        $found     = null;
        $value     = wp_cache_get_with_cas( 'cas_key', 'default', $cas_token, false, $found );

        $this->assertEquals( 'cas_value', $value );
        $this->assertTrue( $found );
        $this->assertNotNull( $cas_token );
    }

    public function test_set(): void {
        $result = wp_cache_set( 'set_key', 'set_value' );
        $this->assertTrue( $result );

        $value = wp_cache_get( 'set_key' );
        $this->assertEquals( 'set_value', $value );

        $result = wp_cache_set( 'set_key', 'new_value' );
        $this->assertTrue( $result );

        $value = wp_cache_get( 'set_key' );
        $this->assertEquals( 'new_value', $value );
    }

    public function test_set_with_complex_data(): void {
        $array_data = array( 'key' => 'value', 'nested' => array( 1, 2, 3 ) );
        wp_cache_set( 'array_key', $array_data );
        $this->assertEquals( $array_data, wp_cache_get( 'array_key' ) );

        $object      = new stdClass();
        $object->foo = 'bar';
        wp_cache_set( 'object_key', $object );
        $this->assertEquals( $object, wp_cache_get( 'object_key' ) );
    }

    public function test_delete(): void {
        wp_cache_set( 'delete_key', 'delete_value' );
        $this->assertEquals( 'delete_value', wp_cache_get( 'delete_key' ) );

        $result = wp_cache_delete( 'delete_key' );
        $this->assertTrue( $result );

        $found = null;
        wp_cache_get( 'delete_key', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_delete_nonexistent(): void {
        $result = wp_cache_delete( 'nonexistent_delete_key' );
        $this->assertFalse( $result );
    }

    public function test_replace(): void {
        $result = wp_cache_replace( 'replace_key', 'value' );
        $this->assertFalse( $result );

        wp_cache_set( 'replace_key', 'original' );
        $result = wp_cache_replace( 'replace_key', 'replaced' );
        $this->assertTrue( $result );

        $this->assertEquals( 'replaced', wp_cache_get( 'replace_key' ) );
    }

    public function test_cas(): void {
        wp_cache_set( 'cas_test_key', 'initial' );

        $cas_token = null;
        wp_cache_get_with_cas( 'cas_test_key', 'default', $cas_token );

        $result = wp_cache_cas( $cas_token, 'cas_test_key', 'updated' );
        $this->assertTrue( $result );
        $this->assertEquals( 'updated', wp_cache_get( 'cas_test_key' ) );
    }

    public function test_cas_with_invalid_token(): void {
        wp_cache_set( 'cas_invalid_key', 'initial' );

        $result = wp_cache_cas( 999999.999, 'cas_invalid_key', 'updated' );
        $this->assertFalse( $result );
    }

    public function test_cas_with_null_token(): void {
        wp_cache_set( 'cas_null_key', 'initial' );

        $result = wp_cache_cas( null, 'cas_null_key', 'updated' );
        $this->assertFalse( $result );
    }

    public function test_flush(): void {
        wp_cache_set( 'flush_key1', 'value1' );
        wp_cache_set( 'flush_key2', 'value2' );

        $result = wp_cache_flush();
        $this->assertTrue( $result );

        $found = null;
        wp_cache_get( 'flush_key1', 'default', false, $found );
        $this->assertFalse( $found );

        wp_cache_get( 'flush_key2', 'default', false, $found );
        $this->assertFalse( $found );
    }

    public function test_incr(): void {
        wp_cache_set( 'incr_key', 10 );

        $result = wp_cache_incr( 'incr_key' );
        $this->assertEquals( 11, $result );

        $result = wp_cache_incr( 'incr_key', 5 );
        $this->assertEquals( 16, $result );
    }

    public function test_incr_nonexistent(): void {
        $result = wp_cache_incr( 'nonexistent_incr_key' );
        $this->assertFalse( $result );
    }

    public function test_incr_non_numeric(): void {
        wp_cache_set( 'string_key', 'not_a_number' );
        $result = wp_cache_incr( 'string_key' );
        $this->assertFalse( $result );
    }

    public function test_decr(): void {
        wp_cache_set( 'decr_key', 10 );

        $result = wp_cache_decr( 'decr_key' );
        $this->assertEquals( 9, $result );

        $result = wp_cache_decr( 'decr_key', 5 );
        $this->assertEquals( 4, $result );
    }

    public function test_decr_nonexistent(): void {
        $result = wp_cache_decr( 'nonexistent_decr_key' );
        $this->assertFalse( $result );
    }

    public function test_decr_below_zero(): void {
        wp_cache_set( 'decr_zero_key', 5 );

        $result = wp_cache_decr( 'decr_zero_key', 10 );
        $this->assertEquals( 0, $result );
    }

    public function test_groups(): void {
        wp_cache_set( 'key', 'value_default' );
        wp_cache_set( 'key', 'value_group1', 'group1' );
        wp_cache_set( 'key', 'value_group2', 'group2' );

        $this->assertEquals( 'value_default', wp_cache_get( 'key' ) );
        $this->assertEquals( 'value_group1', wp_cache_get( 'key', 'group1' ) );
        $this->assertEquals( 'value_group2', wp_cache_get( 'key', 'group2' ) );
    }

    public function test_global_groups(): void {
        wp_cache_add_global_groups( array( 'global_group' ) );

        wp_cache_set( 'global_key', 'global_value', 'global_group' );

        wp_cache_switch_to_blog( 2 );

        $value = wp_cache_get( 'global_key', 'global_group' );
        $this->assertEquals( 'global_value', $value );
    }

    public function test_non_persistent_groups(): void {
        wp_cache_add_non_persistent_groups( array( 'np_group' ) );

        wp_cache_set( 'np_key', 'np_value', 'np_group' );
        $this->assertEquals( 'np_value', wp_cache_get( 'np_key', 'np_group' ) );

        $result = wp_cache_add( 'np_key', 'new_value', 'np_group' );
        $this->assertFalse( $result );

        $result = wp_cache_replace( 'np_key', 'replaced_value', 'np_group' );
        $this->assertTrue( $result );
        $this->assertEquals( 'replaced_value', wp_cache_get( 'np_key', 'np_group' ) );
    }

    public function test_non_persistent_groups_add(): void {
        wp_cache_add_non_persistent_groups( array( 'np_add_group' ) );

        $result = wp_cache_add( 'np_add_key', 'value1', 'np_add_group' );
        $this->assertTrue( $result );

        $result = wp_cache_add( 'np_add_key', 'value2', 'np_add_group' );
        $this->assertFalse( $result );

        $this->assertEquals( 'value1', wp_cache_get( 'np_add_key', 'np_add_group' ) );
    }

    public function test_non_persistent_groups_delete(): void {
        wp_cache_add_non_persistent_groups( array( 'np_delete_group' ) );

        wp_cache_set( 'np_delete_key', 'value', 'np_delete_group' );
        $result = wp_cache_delete( 'np_delete_key', 'np_delete_group' );
        $this->assertTrue( $result );

        $found = null;
        wp_cache_get( 'np_delete_key', 'np_delete_group', false, $found );
        $this->assertFalse( $found );
    }

    public function test_non_persistent_groups_incr_decr(): void {
        wp_cache_add_non_persistent_groups( array( 'np_math_group' ) );

        wp_cache_set( 'np_math_key', 10, 'np_math_group' );

        $result = wp_cache_incr( 'np_math_key', 5, 'np_math_group' );
        $this->assertEquals( 15, $result );

        $result = wp_cache_decr( 'np_math_key', 3, 'np_math_group' );
        $this->assertEquals( 12, $result );
    }

    public function test_non_persistent_groups_decr_below_zero(): void {
        wp_cache_add_non_persistent_groups( array( 'np_decr_group' ) );

        wp_cache_set( 'np_decr_key', 5, 'np_decr_group' );
        $result = wp_cache_decr( 'np_decr_key', 10, 'np_decr_group' );
        $this->assertEquals( 0, $result );
    }

    public function test_multisite_switch(): void {
        wp_cache_set( 'blog_key', 'blog1_value' );

        wp_cache_switch_to_blog( 2 );
        wp_cache_set( 'blog_key', 'blog2_value' );

        $this->assertEquals( 'blog2_value', wp_cache_get( 'blog_key' ) );

        wp_cache_switch_to_blog( 1 );
        $this->assertEquals( 'blog1_value', wp_cache_get( 'blog_key' ) );
    }

    public function test_empty_group_defaults_to_default(): void {
        wp_cache_set( 'empty_group_key', 'value', '' );
        $this->assertEquals( 'value', wp_cache_get( 'empty_group_key', '' ) );
        $this->assertEquals( 'value', wp_cache_get( 'empty_group_key', 'default' ) );
    }

    public function test_get_stats(): void {
        wp_cache_set( 'stats_key', 'stats_value' );
        wp_cache_get( 'stats_key' );
        wp_cache_get( 'nonexistent_stats_key' );

        $stats = wp_cache_get_stats();

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'hits', $stats );
        $this->assertArrayHasKey( 'misses', $stats );
        $this->assertArrayHasKey( 'hit_ratio', $stats );
        $this->assertGreaterThanOrEqual( 1, $stats['hits'] );
        $this->assertGreaterThanOrEqual( 1, $stats['misses'] );
    }

    public function test_get_servers(): void {
        $servers = wp_cache_get_servers();
        $this->assertIsArray( $servers );
    }

    public function test_is_connected(): void {
        $connected = wp_cache_is_connected();
        $this->assertTrue( $connected );
    }

    public function test_close(): void {
        $result = wp_cache_close();
        $this->assertTrue( $result );
    }
}
