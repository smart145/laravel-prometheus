<?php

declare(strict_types=1);

namespace Smart145\Prometheus\MetricBuilders;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Trait for validating metric label counts.
 *
 * Provides configurable behavior for handling label mismatches:
 * - throw: Throw an exception (default, best for development)
 * - log: Log a warning and skip (best for production resilience)
 * - ignore: Silently skip (use with caution)
 */
trait ValidatesLabels
{
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

        if ($expectedCount === $actualCount) {
            return true;
        }

        $behavior = config('prometheus.label_mismatch_behavior', 'throw');
        $message = sprintf(
            'Label count mismatch for metric "%s": expected %d labels (%s) but got %d values (%s)',
            $metricName,
            $expectedCount,
            implode(', ', $labelNames),
            $actualCount,
            implode(', ', array_map('strval', $labelValues))
        );

        if ($behavior === 'throw') {
            throw new InvalidArgumentException($message);
        }

        if ($behavior === 'log') {
            Log::warning('Prometheus: ' . $message);
        }

        // For 'log' and 'ignore', return false to indicate the metric should be skipped
        return false;
    }
}
