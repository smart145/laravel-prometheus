<?php

declare(strict_types=1);

namespace Smart145\Prometheus\MetricBuilders;

use InvalidArgumentException;
use Smart145\Prometheus\RejectsInvalidRecordings;
use Smart145\Prometheus\Support\Number;
use Stringable;

/**
 * Trait for validating what a builder records: label values and increments.
 *
 * An invalid recording follows `prometheus.label_mismatch_behavior`:
 * - throw: Throw an exception (default, best for development)
 * - log: Log a warning and skip (best for production resilience)
 * - ignore: Silently skip (use with caution)
 */
trait ValidatesLabels
{
    use RejectsInvalidRecordings;

    /**
     * Validate that the number of label values matches the defined label names.
     *
     * @param  string  $metricName  The metric name for error messages
     * @param  array  $labelNames  The defined label names
     * @param  array  $labelValues  The provided label values
     * @return bool  Returns true if valid, false if invalid (when not throwing)
     *
     * @throws InvalidArgumentException If behavior is 'throw' and labels don't match
     */
    private function validateLabels(string $metricName, array $labelNames, array $labelValues): bool
    {
        $expectedCount = count($labelNames);
        $actualCount = count($labelValues);

        foreach ($labelValues as $value) {
            if ($this->labelValueToString($value) === null) {
                return $this->rejectRecording(sprintf(
                    'Metric "%s" has a label value of type %s that cannot be written as text',
                    $metricName,
                    get_debug_type($value)
                ));
            }
        }

        if ($expectedCount === $actualCount) {
            return true;
        }

        return $this->rejectRecording(sprintf(
            'Label count mismatch for metric "%s": expected %d labels (%s) but got %d values (%s)',
            $metricName,
            $expectedCount,
            implode(', ', $labelNames),
            $actualCount,
            implode(', ', $this->normalizeLabelValues($labelValues))
        ));
    }

    /**
     * Turn label values into a list of strings.
     *
     * Prometheus label values are text. Without this, `[200]`, `['200']` and
     * `['status' => '200']` would be stored as three series with the same labels.
     *
     * @return string[]
     */
    private function normalizeLabelValues(array $labelValues): array
    {
        return array_map(
            fn (mixed $value): string => $this->labelValueToString($value) ?? '',
            array_values($labelValues)
        );
    }

    /**
     * @return string|null  Null if the value has no text form
     */
    private function labelValueToString(mixed $value): ?string
    {
        if (is_float($value)) {
            return Number::format($value);
        }

        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $json = json_encode($value);

        return $json === false ? null : $json;
    }

    /**
     * Validate a value that is added to a stored value.
     *
     * NaN and infinite increments cannot be added. A counter must not decrease:
     * Prometheus reads a lower value as a counter reset.
     *
     * @return bool  Returns true if valid, false if invalid (when not throwing)
     *
     * @throws InvalidArgumentException If behavior is 'throw' and the value is invalid
     */
    private function validateIncrement(string $metricName, int|float $value, bool $allowNegative): bool
    {
        if (is_float($value) && ! is_finite($value)) {
            return $this->rejectRecording(sprintf('Metric "%s" cannot record a NaN or infinite value', $metricName));
        }

        if (! $allowNegative && $value < 0) {
            return $this->rejectRecording(sprintf(
                'Counter "%s" cannot be incremented by a negative value (%s)',
                $metricName,
                $value
            ));
        }

        return true;
    }
}
