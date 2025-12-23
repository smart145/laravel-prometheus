<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use function is_infinite;

/**
 * Extended Sample class that supports timestamps.
 *
 * Prometheus text format supports optional timestamps after the value:
 * metric_name{labels} value [timestamp]
 *
 * The timestamp is in milliseconds since epoch.
 */
class Sample
{
    private string $name;

    /** @var string[] */
    private array $labelNames;

    /** @var mixed[] */
    private array $labelValues;

    private int|float $value;

    private ?int $timestamp;

    /**
     * @param  mixed[]  $data
     */
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->labelNames = (array) ($data['labelNames'] ?? []);
        $this->labelValues = (array) ($data['labelValues'] ?? []);
        $this->value = $data['value'];
        $this->timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function getLabelNames(): array
    {
        return $this->labelNames;
    }

    /**
     * @return mixed[]
     */
    public function getLabelValues(): array
    {
        return $this->labelValues;
    }

    public function getValue(): string
    {
        if (is_float($this->value) && is_infinite($this->value)) {
            return $this->value > 0 ? '+Inf' : '-Inf';
        }

        return (string) $this->value;
    }

    public function hasLabelNames(): bool
    {
        return $this->labelNames !== [];
    }

    /**
     * Get the timestamp in milliseconds since epoch.
     */
    public function getTimestamp(): ?int
    {
        return $this->timestamp;
    }

    /**
     * Check if this sample has a timestamp.
     */
    public function hasTimestamp(): bool
    {
        return $this->timestamp !== null;
    }
}

