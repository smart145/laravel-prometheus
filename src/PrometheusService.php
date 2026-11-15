<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use Prometheus\CollectorRegistry;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Smart145\Prometheus\Adapters\LaravelRedisAdapter;
use Smart145\Prometheus\MetricBuilders\CounterBuilder;
use Smart145\Prometheus\MetricBuilders\GaugeBuilder;
use Smart145\Prometheus\MetricBuilders\HistogramBuilder;
use Throwable;
use UnexpectedValueException;

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

    /**
     * Buckets of each histogram, as first declared in this process.
     *
     * @var array<string, array<int, int|float>>
     */
    private array $histogramBuckets = [];

    /**
     * In-memory fallback for the last scrape timestamp.
     *
     * Used when the storage adapter doesn't persist scrape times (e.g. the
     * InMemory adapter). Not shared across requests.
     */
    private ?int $lastScrapeTime = null;

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

        // promphp keeps the first declaration of a histogram. Use the same
        // buckets for recordings with a timestamp, which go to the adapter.
        $this->histogramBuckets[$histogram->getName()] ??= $resolvedBuckets;

        return new HistogramBuilder($histogram, $this->adapter, $this->histogramBuckets[$histogram->getName()]);
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
        return $this->renderSamples(false);
    }

    /**
     * Render all metrics, then wipe them from storage.
     *
     * With Redis storage the read and the wipe are one step, so a recording
     * that arrives during the scrape is not lost.
     */
    public function renderAndWipe(): string
    {
        return $this->renderSamples(true);
    }

    private function renderSamples(bool $wipe): string
    {
        // Use our adapter's collect method which returns our MetricFamilySamples with timestamp support
        if ($this->adapter instanceof LaravelRedisAdapter) {
            $samples = $wipe ? $this->adapter->collectAndWipe() : $this->adapter->collect();
        } else {
            // For InMemory adapter, convert promphp samples to our format
            $samples = $this->convertSamples($this->registry->getMetricFamilySamples());

            if ($wipe) {
                $this->adapter->wipeStorage();
            }
        }

        $samples = $this->addGaugeCallbackSamples($samples);

        // Silent: one bad sample becomes a comment and the scrape continues.
        return (new RenderTextFormat())->render($samples, true);
    }

    /**
     * Evaluate the gauge callbacks and add their values to the collected samples.
     *
     * A callback value is not stored. A callback that fails has no sample in
     * this scrape, so Prometheus sees a missing value and not an old one.
     *
     * @param  MetricFamilySamples[]  $families
     * @return MetricFamilySamples[]
     */
    private function addGaugeCallbackSamples(array $families): array
    {
        $byName = [];
        foreach ($families as $family) {
            $byName[$family->getName()] = $family;
        }

        foreach ($this->gaugeCallbacks as $gaugeConfig) {
            $name = $gaugeConfig['namespace'] . '_' . $gaugeConfig['name'];
            $labelNames = array_values($gaugeConfig['labelNames']);
            $labelValues = array_map('strval', array_values($gaugeConfig['labelValues']));
            $family = $byName[$name] ?? null;

            // An earlier version stored callback values. Drop that sample
            // first, so a failing callback does not leave it in the output.
            $family?->removeSample($name, $labelNames, $labelValues);

            try {
                $value = call_user_func($gaugeConfig['callback']);

                if (is_string($value) && is_numeric($value)) {
                    $value = (float) $value;
                }

                if (! is_int($value) && ! is_float($value)) {
                    throw new UnexpectedValueException(sprintf(
                        'Gauge callback "%s" must return a number, got %s',
                        $name,
                        get_debug_type($value)
                    ));
                }

                if (count($labelNames) !== count($labelValues)) {
                    throw new UnexpectedValueException(sprintf(
                        'Gauge callback "%s" has %d label names but %d label values',
                        $name,
                        count($labelNames),
                        count($labelValues)
                    ));
                }

                if ($family !== null && $family->getType() !== Gauge::TYPE) {
                    throw new UnexpectedValueException(sprintf(
                        'Gauge callback "%s" has the name of a stored %s',
                        $name,
                        $family->getType()
                    ));
                }

                $family = $byName[$name] ??= new MetricFamilySamples([
                    'name' => $name,
                    'type' => Gauge::TYPE,
                    'help' => $gaugeConfig['help'],
                    'labelNames' => [],
                ]);

                // The family may already hold these label names (InMemory storage).
                $family->addSample(new Sample([
                    'name' => $name,
                    'labelNames' => $family->getLabelNames() === $labelNames ? [] : $labelNames,
                    'labelValues' => $labelValues,
                    'value' => $value,
                ]));
            } catch (Throwable $e) {
                // Report the error but continue rendering other metrics
                report($e);
            }
        }

        return array_values(array_filter(
            $byName,
            fn (MetricFamilySamples $family): bool => $family->getSamples() !== []
        ));
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

    /**
     * Record the time of the most recent metrics scrape.
     *
     * This is the "consumed" signal used by the cleanup command to detect
     * when metrics have gone unscraped for too long.
     */
    public function recordScrape(?int $time = null): void
    {
        $time ??= time();

        if ($this->adapter instanceof LaravelRedisAdapter) {
            $this->adapter->recordScrape($time);

            return;
        }

        $this->lastScrapeTime = $time;
    }

    /**
     * Get the timestamp of the most recent metrics scrape, or null if unknown.
     */
    public function lastScrapeTime(): ?int
    {
        if ($this->adapter instanceof LaravelRedisAdapter) {
            return $this->adapter->getLastScrapeTime();
        }

        return $this->lastScrapeTime;
    }
}

