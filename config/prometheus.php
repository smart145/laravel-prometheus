<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Prometheus Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the Prometheus metrics endpoint. When disabled,
    | accessing the endpoint will return a 403 Forbidden response.
    |
    */
    'enabled' => env('PROMETHEUS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Namespace
    |--------------------------------------------------------------------------
    |
    | The default namespace for all metrics. This will be prepended to
    | all metric names (e.g., 'app_requests_total').
    |
    */
    'namespace' => env('PROMETHEUS_NAMESPACE', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Metrics Route
    |--------------------------------------------------------------------------
    |
    | The URL path where metrics will be exposed. By default, metrics
    | are available at /prometheus.
    |
    */
    'route' => env('PROMETHEUS_ROUTE', 'prometheus'),

    /*
    |--------------------------------------------------------------------------
    | Allowed IPs
    |--------------------------------------------------------------------------
    |
    | List of IP addresses that are allowed to access the metrics endpoint.
    | An empty array allows access from any IP (useful for development).
    |
    | Set via environment variable as comma-separated values:
    | PROMETHEUS_ALLOWED_IPS=10.0.0.1,10.0.0.2
    |
    */
    'allowed_ips' => env('PROMETHEUS_ALLOWED_IPS', ''),

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | The storage driver to use for persisting metrics between requests.
    |
    | Supported: "redis", "memory"
    |
    | - redis: Persists metrics in Redis (recommended for production).
    |          Needs Redis scripting (EVAL). Redis Cluster is not supported.
    | - memory: Stores metrics in memory (lost between requests, for testing)
    |
    */
    'storage' => env('PROMETHEUS_STORAGE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection to use when storage is set to 'redis'.
    | Uses Laravel's default Redis connection if not specified.
    |
    */
    'redis_connection' => env('PROMETHEUS_REDIS_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware to apply to the Prometheus metrics route.
    |
    */
    'middleware' => [
        \Smart145\Prometheus\Http\Middleware\AllowedIps::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Wipe After Scrape
    |--------------------------------------------------------------------------
    |
    | When enabled, metrics will be automatically wiped after each scrape.
    | This is useful for push-gateway style setups where you want to clear
    | metrics after Prometheus reads them.
    |
    | A wiped counter holds only the events since the last scrape. Query it
    | with sum_over_time(), not rate() or increase(), and use one scraper.
    |
    | Note: This takes precedence over wipe_param settings.
    |
    */
    'auto_wipe' => env('PROMETHEUS_AUTO_WIPE', false),

    /*
    |--------------------------------------------------------------------------
    | Wipe Parameter
    |--------------------------------------------------------------------------
    |
    | Configure how the wipe-on-request feature works.
    |
    | - enabled: Whether to allow wiping via query parameter
    | - param: The query parameter name to trigger wipe (default: "wipe")
    | - value: The value that triggers wipe (default: "1")
    |
    | Examples:
    |   /prometheus?wipe=1        (default)
    |   /prometheus?clear=1       (param=clear, value=1)
    |   /prometheus?reset=yes     (param=reset, value=yes)
    |
    | Note: Use "1" instead of "true" as the default to avoid Laravel's
    | env() converting "true" to boolean.
    |
    */
    'wipe_param' => [
        'enabled' => env('PROMETHEUS_WIPE_PARAM_ENABLED', true),
        'param' => env('PROMETHEUS_WIPE_PARAM', 'wipe'),
        'value' => env('PROMETHEUS_WIPE_VALUE', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wipe Gauges
    |--------------------------------------------------------------------------
    |
    | Controls whether a scrape that wipes (auto_wipe or the wipe parameter)
    | also wipes the gauges. Redis storage only.
    |
    | - true: The scrape wipes all metrics (default).
    | - false: The scrape wipes counters and histograms and keeps the gauges.
    |          A gauge is a current value, so inc()/dec() and set() stay
    |          correct across scrapes.
    |
    | A full wipe (`php artisan prometheus:cleanup --force`, the idle cleanup
    | and `Prometheus::wipe()`) always removes the gauges too.
    |
    */
    'wipe_gauges' => env('PROMETHEUS_WIPE_GAUGES', true),

    /*
    |--------------------------------------------------------------------------
    | Gauge TTL
    |--------------------------------------------------------------------------
    |
    | Seconds after which a gauge sample that nobody wrote is removed during
    | a scrape. Redis storage only. 0 keeps gauge samples until a wipe.
    |
    | Use it with wipe_gauges = false, so that a gauge series that the
    | application no longer writes (an old host, queue or tenant) does not
    | stay in Redis and in the output forever. Set it longer than the longest
    | time between two writes of a gauge that is still in use.
    |
    | Samples written by a version without this option have no write time.
    | They are kept until their next write.
    |
    */
    'gauge_ttl' => (int) env('PROMETHEUS_GAUGE_TTL', 0),

    /*
    |--------------------------------------------------------------------------
    | Label Mismatch Behavior
    |--------------------------------------------------------------------------
    |
    | Controls how the package handles label count mismatches when recording
    | metrics. A mismatch occurs when the number of label values provided
    | doesn't match the number of label names defined for the metric.
    |
    | The same behavior applies to every recording that cannot be stored:
    | a negative or non-finite counter increment, a non-finite histogram
    | observation, and a name that is already stored as another metric type.
    |
    | Supported: "throw", "log", "ignore"
    |
    | - throw: Throw an InvalidArgumentException immediately (default)
    |          Best for development to catch bugs early.
    |
    | - log: Log a warning and skip recording the metric.
    |        The metric will not be stored, but the request continues.
    |        Best for production if you want resilience over strictness.
    |
    | - ignore: Silently skip recording the metric without logging.
    |           Use with caution as bugs may go unnoticed.
    |
    */
    'label_mismatch_behavior' => env('PROMETHEUS_LABEL_MISMATCH_BEHAVIOR', 'throw'),

    /*
    |--------------------------------------------------------------------------
    | Auto-Clean Corrupted Samples
    |--------------------------------------------------------------------------
    |
    | When enabled, corrupted samples with label mismatches found in Redis
    | during metric collection will be automatically deleted. This cleans up
    | stale or invalid data that could cause rendering errors.
    |
    | A warning is logged for each removed sample with details about the
    | mismatch, helping you identify and fix the root cause.
    |
    */
    'auto_clean_corrupted_samples' => env('PROMETHEUS_AUTO_CLEAN_CORRUPTED', true),

    /*
    |--------------------------------------------------------------------------
    | Idle Cleanup
    |--------------------------------------------------------------------------
    |
    | Metrics are normally cleared when a Prometheus scrape hits the endpoint.
    | If nothing scrapes them, the storage keeps growing until it can crash
    | Redis. The `prometheus:cleanup` command wipes all metrics once they've
    | gone unscraped for longer than the idle threshold.
    |
    | - enabled: When true, the package auto-registers a scheduled task that
    |   runs the cleanup command. Set to false to schedule it yourself.
    | - idle_threshold: Seconds without a scrape before metrics are wiped.
    | - schedule: Cron expression for how often the cleanup check runs.
    |
    | Note: The check only fires if your application runs Laravel's scheduler
    | (e.g. `schedule:run` via cron or `schedule:work`).
    |
    */
    'cleanup' => [
        'enabled' => env('PROMETHEUS_CLEANUP_ENABLED', true),
        'idle_threshold' => (int) env('PROMETHEUS_CLEANUP_IDLE_THRESHOLD', 3600),
        'schedule' => env('PROMETHEUS_CLEANUP_SCHEDULE', '0 * * * *'),
    ],

];

