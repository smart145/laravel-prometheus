<?php

declare(strict_types=1);

namespace Smart145\Prometheus\MetricBuilders;

use Prometheus\Gauge;
use Prometheus\Storage\Adapter;
use Smart145\Prometheus\PrometheusService;

/**
 * Fluent builder for Gauge metrics with timestamp support.
 */
class GaugeBuilder
{
    use ValidatesLabels;

    private ?int $timestamp = null;

    public function __construct(
        private readonly Gauge $gauge,
        private readonly Adapter $adapter,
        private readonly PrometheusService $service,
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
     * Set the gauge to a specific value.
     *
     * @param  float  $value  The value to set
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function set(float $value, array $labelValues = []): void
    {
        if (! $this->validateLabels($this->gauge->getName(), $this->gauge->getLabelNames(), $labelValues)) {
            return;
        }

        if ($this->timestamp !== null) {
            // Use adapter directly for timestamp support
            $this->adapter->updateGauge([
                'type' => Gauge::TYPE,
                'name' => $this->gauge->getName(),
                'help' => $this->gauge->getHelp(),
                'labelNames' => $this->gauge->getLabelNames(),
                'labelValues' => $labelValues,
                'value' => $value,
                'command' => Adapter::COMMAND_SET,
                'timestamp' => $this->timestamp,
            ]);
        } else {
            $this->gauge->set($value, $labelValues);
        }
    }

    /**
     * Increment the gauge by 1.
     *
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function inc(array $labelValues = []): void
    {
        $this->incBy(1, $labelValues);
    }

    /**
     * Increment the gauge by a specific value.
     *
     * @param  int|float  $value  Value to increment by
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function incBy(int|float $value, array $labelValues = []): void
    {
        if (! $this->validateLabels($this->gauge->getName(), $this->gauge->getLabelNames(), $labelValues)) {
            return;
        }

        if ($this->timestamp !== null) {
            $this->adapter->updateGauge([
                'type' => Gauge::TYPE,
                'name' => $this->gauge->getName(),
                'help' => $this->gauge->getHelp(),
                'labelNames' => $this->gauge->getLabelNames(),
                'labelValues' => $labelValues,
                'value' => $value,
                'command' => Adapter::COMMAND_INCREMENT_FLOAT,
                'timestamp' => $this->timestamp,
            ]);
        } else {
            $this->gauge->incBy($value, $labelValues);
        }
    }

    /**
     * Decrement the gauge by 1.
     *
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function dec(array $labelValues = []): void
    {
        $this->decBy(1, $labelValues);
    }

    /**
     * Decrement the gauge by a specific value.
     *
     * @param  int|float  $value  Value to decrement by
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function decBy(int|float $value, array $labelValues = []): void
    {
        $this->incBy(-$value, $labelValues);
    }
}

