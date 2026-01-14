# Hazelcast Server Configuration for WordPress Object Cache

This guide covers the recommended Hazelcast server-side configuration for optimal WordPress object caching performance.

## Overview

The Hazelcast Object Cache plugin connects to Hazelcast using the **Memcache protocol**. This requires:

1. Enabling the Memcache protocol listener in Hazelcast
2. Configuring an IMap for cache storage
3. Setting appropriate eviction, TTL, and backup policies

## Quick Start

Add the following to your Hazelcast configuration to enable WordPress caching:

### hazelcast.yaml (Recommended)

```yaml
hazelcast:
  network:
    memcache-protocol:
      enabled: true

  map:
    default:
      backup-count: 1
      async-backup-count: 0
      time-to-live-seconds: 3600
      max-idle-seconds: 1800
      eviction:
        eviction-policy: LRU
        max-size-policy: USED_HEAP_PERCENTAGE
        size: 25
      merge-policy:
        batch-size: 100
        class-name: com.hazelcast.spi.merge.LatestUpdateMergePolicy
```

### hazelcast.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<hazelcast xmlns="http://www.hazelcast.com/schema/config"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://www.hazelcast.com/schema/config
           http://www.hazelcast.com/schema/config/hazelcast-config-5.3.xsd">

    <network>
        <memcache-protocol enabled="true"/>
    </network>

    <map name="default">
        <backup-count>1</backup-count>
        <async-backup-count>0</async-backup-count>
        <time-to-live-seconds>3600</time-to-live-seconds>
        <max-idle-seconds>1800</max-idle-seconds>
        <eviction eviction-policy="LRU"
                  max-size-policy="USED_HEAP_PERCENTAGE"
                  size="25"/>
        <merge-policy batch-size="100">
            com.hazelcast.spi.merge.LatestUpdateMergePolicy
        </merge-policy>
    </map>

</hazelcast>
```

## Configuration Reference

### Memcache Protocol Settings

The Memcache protocol listener is required for the WordPress plugin to connect.

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Must be `true` to accept Memcache connections |
| Port | `5701` | Uses the same port as the Hazelcast member |

#### YAML Example

```yaml
hazelcast:
  network:
    memcache-protocol:
      enabled: true
    port:
      port: 5701
      auto-increment: true
      port-count: 100
```

#### XML Example

```xml
<network>
    <memcache-protocol enabled="true"/>
    <port auto-increment="true" port-count="100">5701</port>
</network>
```

### IMap Configuration

WordPress cache data is stored in Hazelcast IMaps. Configure the `default` map or create a dedicated map.

#### Eviction Policy

| Policy | Description | Recommendation |
|--------|-------------|----------------|
| `LRU` | Least Recently Used | **Recommended** for WordPress caching |
| `LFU` | Least Frequently Used | Good for stable access patterns |
| `RANDOM` | Random eviction | Not recommended for caching |
| `NONE` | No eviction | Only if memory is unlimited |

#### Max Size Policy

| Policy | Description | Use Case |
|--------|-------------|----------|
| `USED_HEAP_PERCENTAGE` | Percentage of JVM heap | **Recommended** - adaptive to available memory |
| `USED_HEAP_SIZE` | Absolute heap bytes used | Fixed memory budget |
| `FREE_HEAP_PERCENTAGE` | Evict when free heap drops | Memory-sensitive environments |
| `PER_NODE` | Max entries per node | Simple entry-count limiting |
| `PER_PARTITION` | Max entries per partition | Fine-grained control |

#### Recommended Settings

```yaml
hazelcast:
  map:
    default:
      # Eviction configuration
      eviction:
        eviction-policy: LRU
        max-size-policy: USED_HEAP_PERCENTAGE
        size: 25  # Use up to 25% of heap for cache

      # Expiration settings
      time-to-live-seconds: 3600      # 1 hour default TTL
      max-idle-seconds: 1800          # Evict after 30 min of no access

      # High availability
      backup-count: 1                 # 1 sync backup for HA
      async-backup-count: 0           # No async backups

      # Read optimization
      read-backup-data: true          # Allow reads from backups
```

### High Availability Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `backup-count` | `1` | Synchronous backups (blocks until replicated) |
| `async-backup-count` | `0` | Asynchronous backups (non-blocking) |
| `read-backup-data` | `false` | Allow reads from backup partitions |

#### Production HA Configuration

```yaml
hazelcast:
  map:
    default:
      backup-count: 1           # At least 1 for HA
      async-backup-count: 1     # Additional async backup
      read-backup-data: true    # Faster reads, eventual consistency OK for cache
```

#### Development/Single-Node Configuration

```yaml
hazelcast:
  map:
    default:
      backup-count: 0           # No backups needed
      async-backup-count: 0
```

### TTL and Expiration

| Setting | Description |
|---------|-------------|
| `time-to-live-seconds` | Maximum age of entries (0 = infinite) |
| `max-idle-seconds` | Evict if not accessed within this time (0 = infinite) |

WordPress cache entries often have their own expiration set by plugins. The Hazelcast TTL acts as a safety net:

```yaml
hazelcast:
  map:
    default:
      time-to-live-seconds: 86400   # 24 hour maximum
      max-idle-seconds: 3600        # 1 hour idle timeout
```

## Complete Production Configuration

### hazelcast.yaml

```yaml
hazelcast:
  cluster-name: wordpress-cache

  network:
    memcache-protocol:
      enabled: true
    port:
      port: 5701
      auto-increment: true
    join:
      multicast:
        enabled: false
      tcp-ip:
        enabled: true
        member-list:
          - 192.168.1.10
          - 192.168.1.11
          - 192.168.1.12

  map:
    default:
      backup-count: 1
      async-backup-count: 1
      time-to-live-seconds: 3600
      max-idle-seconds: 1800
      read-backup-data: true
      eviction:
        eviction-policy: LRU
        max-size-policy: USED_HEAP_PERCENTAGE
        size: 25
      merge-policy:
        batch-size: 100
        class-name: com.hazelcast.spi.merge.LatestUpdateMergePolicy

  # Statistics for monitoring
  metrics:
    enabled: true
    management-center:
      enabled: true
```

### hazelcast.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<hazelcast xmlns="http://www.hazelcast.com/schema/config"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://www.hazelcast.com/schema/config
           http://www.hazelcast.com/schema/config/hazelcast-config-5.3.xsd">

    <cluster-name>wordpress-cache</cluster-name>

    <network>
        <memcache-protocol enabled="true"/>
        <port auto-increment="true">5701</port>
        <join>
            <multicast enabled="false"/>
            <tcp-ip enabled="true">
                <member-list>
                    <member>192.168.1.10</member>
                    <member>192.168.1.11</member>
                    <member>192.168.1.12</member>
                </member-list>
            </tcp-ip>
        </join>
    </network>

    <map name="default">
        <backup-count>1</backup-count>
        <async-backup-count>1</async-backup-count>
        <time-to-live-seconds>3600</time-to-live-seconds>
        <max-idle-seconds>1800</max-idle-seconds>
        <read-backup-data>true</read-backup-data>
        <eviction eviction-policy="LRU"
                  max-size-policy="USED_HEAP_PERCENTAGE"
                  size="25"/>
        <merge-policy batch-size="100">
            com.hazelcast.spi.merge.LatestUpdateMergePolicy
        </merge-policy>
    </map>

    <metrics enabled="true">
        <management-center enabled="true"/>
    </metrics>

</hazelcast>
```

## TLS/SSL Configuration

For encrypted connections between WordPress and Hazelcast:

### hazelcast.yaml

```yaml
hazelcast:
  network:
    memcache-protocol:
      enabled: true
    ssl:
      enabled: true
      factory-class-name: com.hazelcast.nio.ssl.BasicSSLContextFactory
      properties:
        keyStore: /path/to/keystore.jks
        keyStorePassword: your-keystore-password
        keyStoreType: JKS
        trustStore: /path/to/truststore.jks
        trustStorePassword: your-truststore-password
        trustStoreType: JKS
        protocol: TLSv1.3
```

### hazelcast.xml

```xml
<network>
    <memcache-protocol enabled="true"/>
    <ssl enabled="true">
        <factory-class-name>
            com.hazelcast.nio.ssl.BasicSSLContextFactory
        </factory-class-name>
        <properties>
            <property name="keyStore">/path/to/keystore.jks</property>
            <property name="keyStorePassword">your-keystore-password</property>
            <property name="keyStoreType">JKS</property>
            <property name="trustStore">/path/to/truststore.jks</property>
            <property name="trustStorePassword">your-truststore-password</property>
            <property name="trustStoreType">JKS</property>
            <property name="protocol">TLSv1.3</property>
        </properties>
    </ssl>
</network>
```

> **Note**: TLS for Memcache protocol requires Hazelcast Enterprise.

## Authentication (Enterprise)

Enable SASL authentication for the Memcache protocol:

### hazelcast.yaml

```yaml
hazelcast:
  security:
    enabled: true
    realms:
      - name: memcacheRealm
        authentication:
          simple:
            users:
              - username: wordpress
                password: 'secure-password-here'
                roles:
                  - admin
    client-authentication:
      realm: memcacheRealm
```

Then configure WordPress:

```php
define('HAZELCAST_USERNAME', 'wordpress');
define('HAZELCAST_PASSWORD', 'secure-password-here');
```

## Memory Tuning

### JVM Heap Settings

For a dedicated WordPress cache cluster, allocate appropriate heap:

```bash
# Start Hazelcast with 4GB heap
JAVA_OPTS="-Xms4g -Xmx4g" bin/hz-start
```

### Sizing Guidelines

| WordPress Traffic | Recommended Heap | Max Cache Size |
|-------------------|------------------|----------------|
| Small (< 10k daily) | 512MB - 1GB | 128MB - 256MB |
| Medium (10k-100k daily) | 1GB - 4GB | 256MB - 1GB |
| Large (> 100k daily) | 4GB - 16GB | 1GB - 4GB |

With `USED_HEAP_PERCENTAGE` at 25%, a 4GB heap provides ~1GB for cache storage.

## Monitoring

Enable Management Center integration for monitoring:

```yaml
hazelcast:
  management-center:
    scripting-enabled: false
    console-enabled: true

  metrics:
    enabled: true
    management-center:
      enabled: true
      retention-seconds: 5
```

Key metrics to monitor:

| Metric | Description | Alert Threshold |
|--------|-------------|-----------------|
| `map.hits` | Cache hit count | Monitor trend |
| `map.misses` | Cache miss count | High miss rate |
| `map.evictionCount` | Eviction count | Sudden spikes |
| `map.ownedEntryCount` | Entries per node | Near max-size |

## Troubleshooting

### Connection Issues

1. **Verify Memcache protocol is enabled:**
   ```bash
   # Check Hazelcast logs for:
   # "Memcache text protocol enabled on port 5701"
   ```

2. **Test connectivity:**
   ```bash
   echo "version" | nc hazelcast-host 5701
   ```

3. **Check firewall rules** for port 5701 (or your configured port)

### Performance Issues

1. **High eviction rate**: Increase `max-size-policy` size or add heap
2. **Slow responses**: Check network latency, enable `read-backup-data`
3. **Memory pressure**: Reduce TTL values, lower `USED_HEAP_PERCENTAGE`

### Data Consistency

For WordPress caching, eventual consistency is acceptable. If you need stronger guarantees:

```yaml
hazelcast:
  map:
    default:
      backup-count: 2           # More sync backups
      async-backup-count: 0     # No async
      read-backup-data: false   # Only read from primary
```

## Additional Resources

- [Hazelcast Reference Manual](https://docs.hazelcast.com/hazelcast/latest/)
- [Memcache Protocol Documentation](https://docs.hazelcast.com/hazelcast/latest/clients/memcache)
- [IMap Configuration](https://docs.hazelcast.com/hazelcast/latest/data-structures/map)
- [Hazelcast Management Center](https://docs.hazelcast.com/management-center/latest/)
