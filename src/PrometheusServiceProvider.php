<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Smart145\Prometheus\Console\Commands\CleanupMetricsCommand;

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupMetricsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('prometheus.cleanup.enabled', true)) {
                $schedule->command('prometheus:cleanup')
                    ->cron(config('prometheus.cleanup.schedule', '0 * * * *'));
            }
        });
    }
}

