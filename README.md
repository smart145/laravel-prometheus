# Laravel Prometheus

A Laravel package for exposing Prometheus metrics, built on top of `promphp/prometheus_client_php`.

## Installation

Install the package via Composer:

```bash
composer require smart145/laravel-prometheus
```

The package will be automatically discovered by Laravel. If you need to manually register the service provider, add it to your `config/app.php`:

```php
'providers' => [
    // ...
    Smart145\Prometheus\PrometheusServiceProvider::class,
],
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=prometheus-config
```

### Environment Variables

```bash
# Enable/disable the metrics endpoint
PROMETHEUS_ENABLED=true

# Metric namespace prefix (e.g., app_metric_name)
PROMETHEUS_NAMESPACE=app

# Metrics endpoint path
PROMETHEUS_ROUTE=prometheus

# Comma-separated list of IPs allowed to access the endpoint
# Leave empty to allow all IPs (not recommended for production)
PROMETHEUS_ALLOWED_IPS=127.0.0.1,10.0.0.1

# Storage driver: memory, redis
# Use redis for persistence across requests (required for counters/histograms)
PROMETHEUS_STORAGE=redis

# Redis connection name from config/database.php (optional)
PROMETHEUS_REDIS_CONNECTION=

# Auto-wipe: Clear metrics after every scrape (push-gateway style)
PROMETHEUS_AUTO_WIPE=false

# Wipe via query parameter
PROMETHEUS_WIPE_PARAM_ENABLED=true
PROMETHEUS_WIPE_PARAM=wipe
PROMETHEUS_WIPE_VALUE=1

# Keep gauges when a scrape wipes (Redis storage): set to false
PROMETHEUS_WIPE_GAUGES=true

# Remove a gauge sample that was not written for this many seconds (0 = never)
PROMETHEUS_GAUGE_TTL=0

# What to do with a recording that cannot be stored: throw, log, ignore
PROMETHEUS_LABEL_MISMATCH_BEHAVIOR=throw
```

### Redis Storage

- Every recording is one Redis step (a Lua script). A scrape never reads a
  half-written sample.
- A scrape reads all samples in one Redis call. It does not use `KEYS`.
- The adapter needs Redis scripting (`EVAL`). It works with the `phpredis`
  and `predis` clients. It does not support Redis Cluster.
- All application instances that share a Redis database share one set of
  metrics. Scrape one instance (for example through the load balancer), not
  every instance. If Prometheus scrapes N instances, each value is in
  Prometheus N times.

### Wipe Modes

Select the mode first. It decides which PromQL functions give correct results.

| Mode | A counter shows | Correct PromQL |
| --- | --- | --- |
| No wipe (default) | The total since the last reset | `rate()`, `increase()`, `histogram_quantile(..., rate(...))` |
| Wipe on each scrape | Only the events since the last scrape | `sum_over_time()` |

`rate()` and `increase()` are wrong on wiped counters: Prometheus reads each
lower value as a counter reset. See [Grafana Queries](#grafana-queries).

In wipe mode:

- Use one scraper only. Each scrape removes the data, so a second Prometheus
  server or a manual request with the wipe parameter takes data away.
- By default the wipe removes gauges too. A gauge that uses `inc()` / `dec()`
  then restarts from zero, and a `set()` gauge is absent until its next
  write. Set `PROMETHEUS_WIPE_GAUGES=false` to keep the gauges: the scrape
  then wipes counters and histograms only, and no value is calculated at
  scrape time.
- The read and the wipe are one Redis step. A recording that arrives during a
  scrape is in that scrape or in the next one.

**Auto-wipe after every scrape:**
```bash
PROMETHEUS_AUTO_WIPE=true
```
Every time Prometheus (or any client) reads the endpoint, metrics are automatically cleared.

**Wipe via query parameter (default):**
```bash
# /prometheus?wipe=1 (default)
PROMETHEUS_WIPE_PARAM_ENABLED=true
PROMETHEUS_WIPE_PARAM=wipe
PROMETHEUS_WIPE_VALUE=1

# Custom: /prometheus?clear=yes
PROMETHEUS_WIPE_PARAM=clear
PROMETHEUS_WIPE_VALUE=yes

# Custom: /prometheus?reset=1
PROMETHEUS_WIPE_PARAM=reset
PROMETHEUS_WIPE_VALUE=1
```

**Keep gauges, and remove the ones that are no longer written:**
```bash
PROMETHEUS_WIPE_GAUGES=false
# A gauge sample with no write for 1 hour is removed in the next scrape
PROMETHEUS_GAUGE_TTL=3600
```
Gauges are removed in three ways:

- `PROMETHEUS_GAUGE_TTL`: one gauge sample, when nobody wrote it for that time.
- `php artisan prometheus:cleanup --force` or `Prometheus::wipe()`: all metrics, gauges included.
- The idle cleanup: all metrics, when nothing scraped for the idle threshold.

**Disable wipe entirely:**
```bash
PROMETHEUS_AUTO_WIPE=false
PROMETHEUS_WIPE_PARAM_ENABLED=false
```

## Usage

### Basic Metrics

Use the `Prometheus` facade to create metrics. The namespace is automatically read from `PROMETHEUS_NAMESPACE` config:

```php
use Smart145\Prometheus\Facades\Prometheus;

// Counter - for values that only increment
Prometheus::counter('requests_total', 'Total HTTP requests', ['method', 'status'])
    ->inc(['GET', '200']);

// Increment by a specific value
Prometheus::counter('processed_items', 'Items processed')
    ->incBy(10);

// Gauge - for values that can go up or down
Prometheus::gauge('active_connections', 'Current active connections')
    ->set(42);

// Increment/decrement gauges
Prometheus::gauge('queue_size', 'Current queue size')
    ->inc(); // +1
Prometheus::gauge('queue_size', 'Current queue size')
    ->dec(); // -1

// Histogram - for measuring distributions
Prometheus::histogram('request_duration_seconds', 'Request duration', ['method'], [0.1, 0.5, 1, 2, 5])
    ->observe(0.25, ['GET']);
```

### With Labels

Labels allow you to add dimensions to your metrics:

```php
// Define label names when creating the metric
$counter = Prometheus::counter('http_requests_total', 'Total requests', ['method', 'endpoint', 'status']);

// Provide label values when recording
$counter->inc(['GET', '/api/users', '200']);
$counter->inc(['POST', '/api/users', '201']);
$counter->inc(['GET', '/api/users', '500']);
```

Rules for correct values:

- Use the same label names, in the same order, each time you record a metric.
- Label values are text: `200` and `'200'` are the same series.
- Keep the number of label values small and fixed (method, status, route
  name). Do not use a user ID, an email, a record ID or a full URL as a label
  value: each new value is a new series in Redis and in Prometheus.
- A counter only goes up. A negative or non-finite increment is rejected.
- One name has one type. A name that is stored as a counter cannot also be
  recorded as a gauge or a histogram.
- For a duration or a size, use a histogram, not a gauge. A gauge keeps only
  the last value before the scrape.
- Give a histogram buckets that fit its unit. The default buckets are for
  seconds (0.005 to 10).

A rejected recording follows `PROMETHEUS_LABEL_MISMATCH_BEHAVIOR`.

### With Timestamps

Record the exact time an event occurred (not when Prometheus scrapes).

Use a timestamp only for a gauge that you set at a known time. Do not use it
for counters and histograms: Prometheus ignores a sample whose timestamp did
not change, and `rate()` needs the scrape time. A recording without a
timestamp removes the timestamp of the sample.

```php
// Add timestamp to the metric
Prometheus::counter('events_total', 'Total events', ['type'])
    ->withTimestamp()
    ->inc(['user_signup']);

// Gauges and histograms also support timestamps
Prometheus::gauge('last_backup_timestamp', 'Unix timestamp of last backup')
    ->withTimestamp()
    ->set((float) time());

Prometheus::histogram('job_duration_seconds', 'Job duration')
    ->withTimestamp()
    ->observe(15.5);
```

The timestamp appears in the Prometheus output after the value:
```
app_events_total{type="user_signup"} 1 1703184523000
```

### Gauge Callbacks

Register callbacks that are evaluated at scrape time. The value is not
stored. If a callback fails, the error is reported and that gauge is not in
the scrape.

```php
use Smart145\Prometheus\Facades\Prometheus;

// In a service provider's boot() method
Prometheus::registerGaugeCallback(
    'users_total',
    'Total number of users',
    fn () => User::count()
);

Prometheus::registerGaugeCallback(
    'active_jobs',
    'Number of pending jobs',
    fn () => DB::table('jobs')->count()
);
```

### Wipe Metrics

Clear all stored metrics:

```bash
# Via query parameter (default: ?wipe=1)
curl "https://your-app.com/prometheus?wipe=1"
```

This returns the current metrics and clears storage in the same step. Read [Wipe Modes](#wipe-modes) before you use it for each scrape.

## Prometheus Server Configuration

Add the following to your `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: 'laravel-app'
    scrape_interval: 15s
    scrape_timeout: 10s
    metrics_path: /prometheus
    static_configs:
      - targets: ['your-app-domain.com:443']
    scheme: https
```

### Docker Compose Example

```yaml
version: '3.8'

services:
  prometheus:
    image: prom/prometheus:latest
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin
    volumes:
      - grafana_data:/var/lib/grafana
    depends_on:
      - prometheus

volumes:
  prometheus_data:
  grafana_data:
```

## Grafana Queries

Example PromQL queries for Grafana dashboards, without wipe (the default):

```promql
# Request rate per second
rate(app_requests_total[5m])

# Average request duration
rate(app_request_duration_seconds_sum[5m]) / rate(app_request_duration_seconds_count[5m])

# 95th percentile request duration
histogram_quantile(0.95, rate(app_request_duration_seconds_bucket[5m]))

# Error rate percentage
sum(rate(app_requests_total{status=~"5.."}[5m])) / sum(rate(app_requests_total[5m])) * 100

# Current gauge value
app_active_connections
```

The same queries when each scrape wipes the metrics:

```promql
# Requests in the last 5 minutes
sum(sum_over_time(app_requests_total[5m]))

# Requests per second, average of the last 5 minutes
sum(sum_over_time(app_requests_total[5m])) / 300

# Average request duration
sum(sum_over_time(app_request_duration_seconds_sum[5m])) / sum(sum_over_time(app_request_duration_seconds_count[5m]))

# 95th percentile request duration
histogram_quantile(0.95, sum by (le) (sum_over_time(app_request_duration_seconds_bucket[5m])))

# Error rate percentage
sum(sum_over_time(app_requests_total{status=~"5.."}[5m])) / sum(sum_over_time(app_requests_total[5m])) * 100
```

## Testing

Run the package tests:

```bash
composer install
vendor/bin/phpunit
```

The storage tests need a Redis server. They are skipped when none answers:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 vendor/bin/phpunit
```

## License

MIT
