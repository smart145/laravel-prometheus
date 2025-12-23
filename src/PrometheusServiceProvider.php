<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use Illuminate\Support\ServiceProvider;

class PrometheusServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/prometheus.php', 'prometheus');

        $this->app->singleton(PrometheusService::class, function () {
            return new PrometheusService();
        });
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/prometheus.php' => config_path('prometheus.php'),
        ], 'prometheus-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}

