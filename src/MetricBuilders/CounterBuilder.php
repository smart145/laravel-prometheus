<?php

declare(strict_types=1);

namespace Smart145\Prometheus\MetricBuilders;

use Prometheus\Counter;
use Prometheus\Storage\Adapter;

/**
 * Fluent builder for Counter metrics with timestamp support.
 */
class CounterBuilder
{
    use ValidatesLabels;

    private ?int $timestamp = null;

    public function __construct(
        private readonly Counter $counter,
        private readonly Adapter $adapter,
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
     * Increment the counter by 1.
     *
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function inc(array $labelValues = []): void
    {
        $this->incBy(1, $labelValues);
    }

    /**
     * Increment the counter by a specific value.
     *
     * @param  int|float  $value  Value to increment by
     * @param  array  $labelValues  Label values corresponding to label names
     */
    public function incBy(int|float $value, array $labelValues = []): void
    {
        if (! $this->validateLabels($this->counter->getName(), $this->counter->getLabelNames(), $labelValues)) {
            return;
        }

        if ($this->timestamp !== null) {
            // Use adapter directly for timestamp support
            $this->adapter->updateCounter([
                'type' => Counter::TYPE,
                'name' => $this->counter->getName(),
                'help' => $this->counter->getHelp(),
                'labelNames' => $this->counter->getLabelNames(),
                'labelValues' => $labelValues,
                'value' => $value,
                'command' => is_float($value) ? Adapter::COMMAND_INCREMENT_FLOAT : Adapter::COMMAND_INCREMENT_INTEGER,
                'timestamp' => $this->timestamp,
            ]);
        } else {
            $this->counter->incBy($value, $labelValues);
        }
    }
}

