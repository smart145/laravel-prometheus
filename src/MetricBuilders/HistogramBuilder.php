<?php

declare(strict_types=1);

namespace Smart145\Prometheus\MetricBuilders;

use Prometheus\Histogram;
use Prometheus\Storage\Adapter;

/**
 * Fluent builder for Histogram metrics with timestamp support.
 */
class HistogramBuilder
{
    use ValidatesLabels;

    private ?int $timestamp = null;

    public function __construct(
        private readonly Histogram $histogram,
        private readonly Adapter $adapter,
        private readonly array $buckets = [],
    ) {}

    /**
     * Add a timestamp to this metric.
     *
     * @param  int|null  $timestampMs  Unix timestamp in milliseconds (defaults to current time)
     */
    public function withTimestamp(?int $timestampMs = null): self
    {
        $this->timestamp = $timestampMs ?? (int) (microtime(true) * 1000);

        return $this;
    }

    /**
     * Observe a value and record it in the histogram.
     *
     * @param  float  $value  The value to observe
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function observe(float $value, array $labelValues = []): void
    {
        if (! $this->validateLabels($this->histogram->getName(), $this->histogram->getLabelNames(), $labelValues)) {
            return;
        }

        if ($this->timestamp !== null) {
            // Use adapter directly for timestamp support
            $this->adapter->updateHistogram([
                'type' => Histogram::TYPE,
                'name' => $this->histogram->getName(),
                'help' => $this->histogram->getHelp(),
                'labelNames' => $this->histogram->getLabelNames(),
                'labelValues' => $labelValues,
                'value' => $value,
                'buckets' => $this->buckets,
                'timestamp' => $this->timestamp,
            ]);
        } else {
            $this->histogram->observe($value, $labelValues);
        }
    }
}

