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
    | - redis: Persists metrics in Redis (recommended for production)
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

];

