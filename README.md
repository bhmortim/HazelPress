# Hazelcast Object Cache for WordPress

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://www.apache.org/licenses/LICENSE-2.0)
[![WordPress](https://img.shields.io/wordpress/plugin/v/hazelcast-object-cache?label=WordPress%20Plugin)](https://wordpress.org/plugins/hazelcast-object-cache/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![Hazelcast](https://img.shields.io/badge/Hazelcast-5.x%2B-green)](https://hazelcast.com/)

**Hazelcast Object Cache** is an official WordPress plugin that provides a drop-in object cache backend powered by Hazelcast. It leverages the Memcache protocol compatibility built into Hazelcast, allowing you to use the standard PHP `Memcached` PECL extension to connect to your Hazelcast cluster.

This enables enterprise-grade distributed caching for high-traffic WordPress sites, with features like automatic partitioning, replication, high availability, and seamless scaling - perfect for organizations already using Hazelcast in their Java stack or looking for a robust Memcached alternative.

## Features

- Full compatibility with WordPress Object Cache API
- Uses Hazelcast's native Memcache protocol (no custom client needed)
- Supports single-node and clustered Hazelcast deployments
- Configuration via `wp-config.php` constants
- Basic diagnostics and stats in the WordPress admin
- Multisite compatible
- Graceful fallback and error handling
- Open source under Apache 2.0

## Requirements

- WordPress 6.0 or higher
- PHP 8.1 or higher
- [Memcached PECL extension](https://pecl.php.net/package/memcached)
- Running Hazelcast cluster (5.x+ recommended; open-source or Enterprise)

## Installation

1. **Install the plugin**
   - Download the latest release from GitHub or (once published) from the WordPress Plugin Directory.
   - Upload the `hazelcast-wp-object-cache` folder to your `/wp-content/plugins/` directory.
   - Activate the plugin through the "Plugins" menu in WordPress.

2. **Install the Memcached PECL extension** (if not already present):
   pecl install memcached

   Then enable it in your `php.ini`:

   extension=memcached.so

   
3. **Configure your Hazelcast connection** in `wp-config.php` (above the "That's all, stop editing!" line):
```php
define('WP_CACHE', true); // Required for any object cache

// List of Hazelcast members (default Memcache port is 5701, but configurable in Hazelcast)
define('HAZELCAST_SERVERS', 'hz-node1:5701,hz-node2:5701,hz-node3:5701');

// Optional: SASL authentication (Hazelcast Enterprise)
// define('HAZELCAST_USERNAME', 'your-username');
// define('HAZELCAST_PASSWORD', 'your-password');

// Optional: Enable compression (default: true)
// define('HAZELCAST_COMPRESSION', true);
