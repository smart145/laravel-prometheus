<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Applies `prometheus.label_mismatch_behavior` to a recording that cannot be stored.
 *
 * - throw: Throw an exception (default, best for development)
 * - log: Log a warning and skip (best for production resilience)
 * - ignore: Silently skip (use with caution)
 */
trait RejectsInvalidRecordings
{
    /**
     * @return false Always false, so callers can `return $this->rejectRecording(...)`.
     *
     * @throws InvalidArgumentException If the behavior is 'throw'
     */
    private function rejectRecording(string $message): bool
    {
        $behavior = config('prometheus.label_mismatch_behavior', 'throw');

        if ($behavior === 'throw') {
            throw new InvalidArgumentException($message);
        }

        if ($behavior === 'log') {
            Log::warning('Prometheus: ' . $message);
        }

        return false;
    }
}
