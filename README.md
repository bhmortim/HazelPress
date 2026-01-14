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

// Optional: Enable TLS/SSL encryption
// define('HAZELCAST_TLS_ENABLED', true);
// define('HAZELCAST_TLS_CERT_PATH', '/path/to/client.crt');
// define('HAZELCAST_TLS_KEY_PATH', '/path/to/client.key');
// define('HAZELCAST_TLS_CA_PATH', '/path/to/ca.crt');
// define('HAZELCAST_TLS_VERIFY_PEER', true);
```

## TLS/SSL Configuration

To enable encrypted connections between WordPress and your Hazelcast cluster:

### Requirements

- Memcached PECL extension **3.2.0+** (with TLS support)
- Hazelcast configured with TLS enabled on the Memcache protocol endpoint
- Valid TLS certificates

### Configuration Constants

| Constant | Type | Default | Description |
|----------|------|---------|-------------|
| `HAZELCAST_TLS_ENABLED` | bool | `false` | Enable TLS encryption |
| `HAZELCAST_TLS_CERT_PATH` | string | `null` | Path to client certificate file (PEM format) |
| `HAZELCAST_TLS_KEY_PATH` | string | `null` | Path to client private key file (PEM format) |
| `HAZELCAST_TLS_CA_PATH` | string | `null` | Path to CA certificate for server verification |
| `HAZELCAST_TLS_VERIFY_PEER` | bool | `true` | Verify server certificate (disable only for testing) |

### Example Configuration

```php
// Enable TLS
define('HAZELCAST_TLS_ENABLED', true);

// Client certificate authentication (if required by server)
define('HAZELCAST_TLS_CERT_PATH', '/etc/ssl/hazelcast/client.crt');
define('HAZELCAST_TLS_KEY_PATH', '/etc/ssl/hazelcast/client.key');

// Custom CA for self-signed server certificates
define('HAZELCAST_TLS_CA_PATH', '/etc/ssl/hazelcast/ca.crt');

// Always verify in production
define('HAZELCAST_TLS_VERIFY_PEER', true);
```

### Troubleshooting TLS

1. **"TLS not supported"**: Upgrade your Memcached PECL extension to 3.2.0 or later
2. **Connection failures**: Verify certificate paths exist and are readable by PHP
3. **Certificate errors**: Ensure the CA certificate matches your Hazelcast server's certificate chain

## Running Tests

### Unit Tests

Run unit tests with the mock Memcached class:

```bash
composer install
./vendor/bin/phpunit --testsuite unit
```

### Integration Tests

Integration tests run against a real Hazelcast instance. You need Docker and the Memcached PECL extension installed.

1. **Start Hazelcast container:**
   ```bash
   docker-compose up -d
   ```

2. **Wait for Hazelcast to be ready** (about 30 seconds):
   ```bash
   docker-compose ps
   ```

3. **Run integration tests:**
   ```bash
   ./vendor/bin/phpunit tests/integration/class-integration-test.php
   ```

4. **Stop the container when done:**
   ```bash
   docker-compose down
   ```

The integration tests will be skipped automatically if:
- The Memcached PECL extension is not installed
- Hazelcast is not running on `127.0.0.1:5701`
