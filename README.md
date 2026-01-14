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

## Documentation

- **[Hazelcast Server Configuration](docs/hazelcast-configuration.md)** - Recommended IMap settings, eviction policies, and complete `hazelcast.xml`/`hazelcast.yaml` examples

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

## Configuration Reference

All configuration is done via PHP constants defined in `wp-config.php` (above the "That's all, stop editing!" line).

| Constant | Type | Default | Description |
|----------|------|---------|-------------|
| `WP_CACHE` | bool | `false` | **Required.** Must be `true` to enable any object cache |
| `HAZELCAST_SERVERS` | string | `'127.0.0.1:5701'` | Comma-separated list of Hazelcast server addresses (host:port) |
| `HAZELCAST_USERNAME` | string | `null` | SASL username for authentication (Hazelcast Enterprise) |
| `HAZELCAST_PASSWORD` | string | `null` | SASL password for authentication (Hazelcast Enterprise) |
| `HAZELCAST_COMPRESSION` | bool | `true` | Enable data compression for cached values |
| `HAZELCAST_KEY_PREFIX` | string | `'wp_' . md5(site_url()) . ':'` | Prefix for all cache keys (auto-generated if not set) |
| `HAZELCAST_TIMEOUT` | int | `null` | Connection timeout in seconds |
| `HAZELCAST_RETRY_TIMEOUT` | int | `null` | Seconds before retrying a failed server |
| `HAZELCAST_SERIALIZER` | string | `'php'` | Serializer for cached data (`php` or `igbinary`) |
| `HAZELCAST_TCP_NODELAY` | bool | `null` | Enable TCP_NODELAY socket option for reduced latency |
| `HAZELCAST_DEBUG` | bool | `false` | Enable debug logging to PHP error log |
| `HAZELCAST_FALLBACK_RETRY_INTERVAL` | int | `30` | Seconds between reconnection attempts when in fallback mode |
| `HAZELCAST_CIRCUIT_BREAKER_THRESHOLD` | int | `5` | Consecutive failures before circuit breaker opens |
| `HAZELCAST_TLS_ENABLED` | bool | `false` | Enable TLS/SSL encryption |
| `HAZELCAST_TLS_CERT_PATH` | string | `null` | Path to client certificate file (PEM format) |
| `HAZELCAST_TLS_KEY_PATH` | string | `null` | Path to client private key file (PEM format) |
| `HAZELCAST_TLS_CA_PATH` | string | `null` | Path to CA certificate for server verification |
| `HAZELCAST_TLS_VERIFY_PEER` | bool | `true` | Verify server certificate (disable only for testing) |

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

## WP-CLI Commands

The plugin provides WP-CLI commands for managing and monitoring the Hazelcast object cache from the command line.

### wp hazelcast status

Display cache connection status, server list, statistics, and configuration.

```bash
wp hazelcast status
```

**Output includes:**
- Connection status
- Configured servers
- Cache hits/misses and hit ratio
- Current configuration settings

### wp hazelcast flush

Flush the entire object cache or a specific cache group.

```bash
# Flush entire cache
wp hazelcast flush

# Flush a specific group
wp hazelcast flush --group=options
wp hazelcast flush --group=posts
```

| Option | Description |
|--------|-------------|
| `--group=<group>` | Flush only the specified cache group instead of the entire cache |

### wp hazelcast get

Retrieve and display a cached value.

```bash
# Get a key from the default group
wp hazelcast get my_key

# Get a key from a specific group
wp hazelcast get my_key --group=options

# Output as JSON
wp hazelcast get my_key --format=json
```

| Option | Default | Description |
|--------|---------|-------------|
| `--group=<group>` | `default` | The cache group to retrieve from |
| `--format=<format>` | `dump` | Output format: `dump`, `json`, or `serialize` |

### wp hazelcast test

Run a connection test by performing a set/get/delete cycle to verify connectivity and measure latency.

```bash
wp hazelcast test
```

**Output:** A table showing the status and latency for each operation (SET, GET, DELETE, TOTAL).

### wp hazelcast install

Install the `object-cache.php` drop-in file to enable Hazelcast caching.

```bash
# Install the drop-in (creates symlink or copy)
wp hazelcast install

# Force overwrite an existing drop-in from another plugin
wp hazelcast install --force
```

| Option | Description |
|--------|-------------|
| `--force` | Overwrite an existing drop-in file, even if it belongs to another plugin |

### wp hazelcast uninstall

Remove the `object-cache.php` drop-in file to disable Hazelcast caching.

```bash
# Uninstall the drop-in
wp hazelcast uninstall

# Force removal even if the drop-in doesn't appear to belong to this plugin
wp hazelcast uninstall --force
```

| Option | Description |
|--------|-------------|
| `--force` | Remove the drop-in even if it does not appear to belong to this plugin |

## Troubleshooting

### Understanding WordPress Drop-ins

A **drop-in** is a special type of WordPress plugin that replaces core WordPress functionality. Unlike regular plugins (stored in `wp-content/plugins/`), drop-ins are single PHP files placed directly in the `wp-content/` directory.

The `object-cache.php` drop-in replaces WordPress's default in-memory object cache with a persistent cache backend (in this case, Hazelcast). WordPress automatically loads this file if it exists, bypassing its built-in caching.

**Key points:**
- Only one `object-cache.php` can be active at a time
- The file must be in `wp-content/object-cache.php` (not in a subdirectory)
- WordPress loads it very early in the boot process

### Verifying Drop-in Installation

To check if the drop-in is correctly installed:

```bash
# Check if the file exists and view its type (symlink vs regular file)
ls -la wp-content/object-cache.php

# Expected output for symlink:
# lrwxrwxrwx 1 www-data www-data ... object-cache.php -> /path/to/plugins/hazelcast-wp-object-cache/includes/object-cache.php

# Expected output for file copy:
# -rw-r--r-- 1 www-data www-data ... object-cache.php
```

You can also verify via WP-CLI:

```bash
wp hazelcast status
```

### Permission Issues (WP-CLI vs Web Server)

A common issue occurs when WP-CLI runs as a different user than your web server. For example:
- Web server runs as `www-data`
- SSH/terminal user is `ubuntu` or `deploy`

**Symptoms:**
- Plugin activation via WordPress admin fails to create the drop-in
- `wp hazelcast install` creates files owned by the wrong user
- Web server cannot read/write to files created by WP-CLI

**Solutions:**

1. **Run WP-CLI as the web server user:**
   ```bash
   sudo -u www-data wp hazelcast install
   ```

2. **Fix ownership after installation:**
   ```bash
   sudo chown www-data:www-data wp-content/object-cache.php
   ```

3. **For shared hosting**, contact your host about correct file ownership, or use the manual installation method below.

### Manual Drop-in Installation

If automatic installation fails (due to permissions or other issues), install the drop-in manually:

**Option 1: Symlink (Recommended)**

Symlinks automatically stay updated when the plugin is updated:

```bash
# Navigate to your WordPress root
cd /path/to/wordpress

# Create the symlink
ln -s wp-content/plugins/hazelcast-wp-object-cache/includes/object-cache.php wp-content/object-cache.php

# Verify
ls -la wp-content/object-cache.php
```

**Option 2: Copy**

Use this if your hosting environment doesn't support symlinks:

```bash
# Navigate to your WordPress root
cd /path/to/wordpress

# Copy the file
cp wp-content/plugins/hazelcast-wp-object-cache/includes/object-cache.php wp-content/object-cache.php

# Verify
ls -la wp-content/object-cache.php
```

> **Note:** If you copy the file, you must repeat this step after plugin updates to get bug fixes and new features.

**Fix permissions after manual installation:**

```bash
# Set correct ownership (adjust user/group for your environment)
sudo chown www-data:www-data wp-content/object-cache.php

# Set correct permissions
chmod 644 wp-content/object-cache.php
```

### Common Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| "A different object-cache.php drop-in already exists" | Another caching plugin's drop-in is installed | Deactivate the other caching plugin first, or use `wp hazelcast install --force` |
| "Failed to write to wp-content directory" | Insufficient permissions | Check directory ownership and permissions; see "Permission Issues" above |
| "The object-cache.php symlink is broken" | Plugin was moved or deleted | Run `wp hazelcast install --force` to recreate the symlink |
| "Hazelcast Object Cache requires the Memcached PECL extension" | PHP extension not installed | Install the Memcached extension: `pecl install memcached` |

### Debug Logging

Enable debug logging to troubleshoot connection issues:

```php
// In wp-config.php
define('HAZELCAST_DEBUG', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check the debug log at `wp-content/debug.log` for detailed error messages.

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
