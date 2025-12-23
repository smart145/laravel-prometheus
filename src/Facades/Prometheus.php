<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Facades;

use Illuminate\Support\Facades\Facade;
use Smart145\Prometheus\MetricBuilders\CounterBuilder;
use Smart145\Prometheus\MetricBuilders\GaugeBuilder;
use Smart145\Prometheus\MetricBuilders\HistogramBuilder;
use Smart145\Prometheus\PrometheusService;

/**
 * Facade for the Prometheus service.
 *
 * @method static CounterBuilder counter(string $name, string $help, array $labelNames = [])
 * @method static GaugeBuilder gauge(string $name, string $help, array $labelNames = [])
 * @method static HistogramBuilder histogram(string $name, string $help, array $labelNames = [], ?array $buckets = null)
 * @method static void registerGaugeCallback(string $name, string $help, callable $callback, array $labelNames = [], array $labelValues = [])
 * @method static string render()
 * @method static string getContentType()
 * @method static void wipe()
 *
 * @see PrometheusService
 */
class Prometheus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PrometheusService::class;
    }
}

