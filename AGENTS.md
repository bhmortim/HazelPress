# Hazelcast WordPress Plugin Style Guide

This guide outlines the specific coding conventions and architectural patterns used in the Hazelcast Object Cache plugin.

## 1. WordPress Integration & Drop-ins
*   **Drop-in Lifecycle**: Use activation/deactivation hooks to manage the `wp-content/object-cache.php` drop-in. 
    *   Prefer `symlink()` for linking the plugin's internal `includes/object-cache.php` to the WordPress content directory.
    *   Implement a fallback to `copy()` if `symlink()` is unavailable.
    *   Verify the file path using `realpath()` before unlinking during deactivation to ensure only the plugin's drop-in is removed.
*   **Graceful Degradation**: Always check for environment dependencies (e.g., `class_exists( 'Memcached' )`) and use `admin_notices` to alert users if requirements are missing without breaking the site.

## 2. Object Cache Implementation
*   **Persistent Connections**: Initialize the `Memcached` extension with a persistent ID (e.g., `new Memcached( 'hazelcast_persistent' )`) to maintain connections across requests.
*   **Singleton Pattern**: Use a `static instance()` method for the main cache class to ensure a single connection to the Hazelcast cluster per request.
*   **Server Management**: Use `getServerList()` to check if servers are already defined for a persistent instance before calling `addServers()` to avoid duplicate connections.
*   **Key Namespacing**: Generate a unique prefix for cache keys using the site URL: `'wp_' . md5( site_url() ) . ':'`.

## 3. Configuration & Constants
*   **Constant Overrides**: Prioritize `wp-config.php` constants over default values.
*   **Naming Convention**: Prefix all configuration constants with `HAZELCAST_` (e.g., `HAZELCAST_SERVERS`, `HAZELCAST_USERNAME`).
*   **Internal Constants**: Define path and version constants in the main plugin file for easy reference:
    ```php
    define( 'HAZELCAST_OBJECT_CACHE_DIR', plugin_dir_path( __FILE__ ) );
    ```

## 4. PHP Conventions
*   **PHP 8.1+ Features**: Leverage modern PHP features while maintaining compatibility with the WordPress ecosystem.
*   **Strict Security**: Use `if ( ! defined( 'ABSPATH' ) ) exit;` at the top of every PHP file to prevent direct script access.
*   **Array Handling**: Prefer `array_map( 'trim', ... )` when processing comma-separated strings from constants.

## 5. Documentation & Header Requirements
*   **File Headers**: Every file must contain a standard WordPress file header or a DocBlock explaining its specific role in the caching layer.
*   **Licensing**: Ensure the Apache-2.0 license is referenced in the plugin header and the full `LICENSE` file is present in the root.

## 6. Admin & UI
*   **Conditional Loading**: Wrap admin-specific logic (like class instantiation) in `if ( is_admin() )` to keep the front-end execution path lean.
*   **Escaping**: Always use WordPress escaping functions (e.g., `esc_html__()`) when outputting text in the admin dashboard.