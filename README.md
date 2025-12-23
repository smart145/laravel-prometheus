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
PROMETHEUS_WIPE_VALUE=true
```

### Wipe Modes

**Auto-wipe after every scrape:**
```bash
PROMETHEUS_AUTO_WIPE=true
```
Every time Prometheus (or any client) reads the endpoint, metrics are automatically cleared.

**Wipe via query parameter (default):**
```bash
# /prometheus?wipe=true (default)
PROMETHEUS_WIPE_PARAM_ENABLED=true
PROMETHEUS_WIPE_PARAM=wipe
PROMETHEUS_WIPE_VALUE=true

# Custom: /prometheus?clear=yes
PROMETHEUS_WIPE_PARAM=clear
PROMETHEUS_WIPE_VALUE=yes

# Custom: /prometheus?reset=1
PROMETHEUS_WIPE_PARAM=reset
PROMETHEUS_WIPE_VALUE=1
```

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

### With Timestamps

Record the exact time an event occurred (not when Prometheus scrapes):

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

Register callbacks that are evaluated at scrape time:

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
# Via query parameter (default: ?wipe=true)
curl "https://your-app.com/prometheus?wipe=true"
```

This returns the current metrics and then clears storage, useful for push-gateway style setups.

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

Example PromQL queries for Grafana dashboards:

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

## Testing

Run the package tests:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
