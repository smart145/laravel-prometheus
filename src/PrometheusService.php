<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Smart145\Prometheus\Adapters\LaravelRedisAdapter;
use Smart145\Prometheus\MetricBuilders\CounterBuilder;
use Smart145\Prometheus\MetricBuilders\GaugeBuilder;
use Smart145\Prometheus\MetricBuilders\HistogramBuilder;

/**
 * Core Prometheus service for Laravel.
 *
 * Provides a fluent API for creating and managing Prometheus metrics.
 *
 * Usage:
 *   Prometheus::counter('requests_total', 'Total requests', ['method'])
 *       ->withTimestamp()
 *       ->inc(['GET']);
 */
class PrometheusService
{
    private CollectorRegistry $registry;

    private Adapter $adapter;

    private array $gaugeCallbacks = [];

    public function __construct(?Adapter $adapter = null)
    {
        $this->adapter = $adapter ?? $this->createAdapter();
        $this->registry = new CollectorRegistry($this->adapter, false);
    }

    /**
     * Create the storage adapter based on configuration.
     */
    private function createAdapter(): Adapter
    {
        $storage = config('prometheus.storage', 'redis');

        if ($storage === 'memory') {
            return new InMemory();
        }

        return new LaravelRedisAdapter(config('prometheus.redis_connection'));
    }

    /**
     * Get the default namespace from config.
     */
    private function getNamespace(): string
    {
        return config('prometheus.namespace', 'app');
    }

    /**
     * Get the collector registry.
     */
    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }

    /**
     * Get the storage adapter.
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Create a counter metric builder.
     *
     * Counters are cumulative metrics that only increase (or reset to zero).
     * Ideal for counting requests, errors, completed tasks, etc.
     *
     * @param  string  $name  The metric name (e.g., 'requests_total')
     * @param  string  $help  Description of the metric
     * @param  array  $labelNames  Label names for the metric
     */
    public function counter(string $name, string $help, array $labelNames = []): CounterBuilder
    {
        $counter = $this->registry->getOrRegisterCounter($this->getNamespace(), $name, $help, $labelNames);

        return new CounterBuilder($counter, $this->adapter);
    }

    /**
     * Create a gauge metric builder.
     *
     * Gauges represent a single numerical value that can go up and down.
     * Ideal for current counts, temperatures, memory usage, etc.
     *
     * @param  string  $name  The metric name (e.g., 'active_users')
     * @param  string  $help  Description of the metric
     * @param  array  $labelNames  Label names for the metric
     */
    public function gauge(string $name, string $help, array $labelNames = []): GaugeBuilder
    {
        $gauge = $this->registry->getOrRegisterGauge($this->getNamespace(), $name, $help, $labelNames);

        return new GaugeBuilder($gauge, $this->adapter, $this);
    }

    /**
     * Create a histogram metric builder.
     *
     * Histograms observe values and count them in configurable buckets.
     * Ideal for measuring request durations, response sizes, etc.
     *
     * @param  string  $name  The metric name (e.g., 'request_duration_seconds')
     * @param  string  $help  Description of the metric
     * @param  array  $labelNames  Label names for the metric
     * @param  array|null  $buckets  Custom bucket boundaries (defaults to Prometheus defaults)
     */
    public function histogram(
        string $name,
        string $help,
        array $labelNames = [],
        ?array $buckets = null
    ): HistogramBuilder {
        $resolvedBuckets = $buckets ?? Histogram::getDefaultBuckets();

        $histogram = $this->registry->getOrRegisterHistogram(
            $this->getNamespace(),
            $name,
            $help,
            $labelNames,
            $resolvedBuckets
        );

        return new HistogramBuilder($histogram, $this->adapter, $resolvedBuckets);
    }

    /**
     * Register a gauge with a callback that's evaluated at render time.
     *
     * @param  string  $name  The metric name
     * @param  string  $help  Description of the metric
     * @param  callable  $callback  Callback that returns the gauge value
     * @param  array  $labelNames  Label names for the metric
     * @param  array  $labelValues  Label values for the metric
     */
    public function registerGaugeCallback(
        string $name,
        string $help,
        callable $callback,
        array $labelNames = [],
        array $labelValues = []
    ): void {
        $this->gaugeCallbacks[] = [
            'namespace' => $this->getNamespace(),
            'name' => $name,
            'help' => $help,
            'callback' => $callback,
            'labelNames' => $labelNames,
            'labelValues' => $labelValues,
        ];
    }

    /**
     * Render all metrics in Prometheus text format.
     */
    public function render(): string
    {
        // Evaluate all gauge callbacks before rendering
        foreach ($this->gaugeCallbacks as $gaugeConfig) {
            try {
                $value = call_user_func($gaugeConfig['callback']);
                $gauge = $this->registry->getOrRegisterGauge(
                    $gaugeConfig['namespace'],
                    $gaugeConfig['name'],
                    $gaugeConfig['help'],
                    $gaugeConfig['labelNames']
                );
                $gauge->set($value, $gaugeConfig['labelValues']);
            } catch (\Throwable $e) {
                // Log the error but continue rendering other metrics
                report($e);
            }
        }

        $renderer = new RenderTextFormat();

        // Use our adapter's collect method which returns our MetricFamilySamples with timestamp support
        if ($this->adapter instanceof Adapters\LaravelRedisAdapter) {
            $samples = $this->adapter->collect();
        } else {
            // For InMemory adapter, convert promphp samples to our format
            $samples = $this->convertSamples($this->registry->getMetricFamilySamples());
        }

        return $renderer->render($samples);
    }

    /**
     * Convert promphp MetricFamilySamples to our custom format.
     *
     * @param  \Prometheus\MetricFamilySamples[]  $promphpSamples
     * @return MetricFamilySamples[]
     */
    private function convertSamples(array $promphpSamples): array
    {
        $result = [];

        foreach ($promphpSamples as $mfs) {
            $samples = [];

            foreach ($mfs->getSamples() as $sample) {
                $samples[] = [
                    'name' => $sample->getName(),
                    'labelNames' => $sample->getLabelNames(),
                    'labelValues' => $sample->getLabelValues(),
                    'value' => (float) $sample->getValue(),
                ];
            }

            $result[] = new MetricFamilySamples([
                'name' => $mfs->getName(),
                'type' => $mfs->getType(),
                'help' => $mfs->getHelp(),
                'labelNames' => $mfs->getLabelNames(),
                'samples' => $samples,
            ]);
        }

        return $result;
    }

    /**
     * Get the content type for Prometheus metrics response.
     */
    public function getContentType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }

    /**
     * Wipe all metrics from storage.
     */
    public function wipe(): void
    {
        $this->adapter->wipeStorage();
    }
}

