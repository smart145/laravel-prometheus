<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Smart145\Prometheus\PrometheusService;

/**
 * Controller for the Prometheus metrics endpoint.
 *
 * Wipe behavior is controlled by config:
 *   - auto_wipe: Always wipe after each scrape
 *   - wipe_param.enabled: Allow wipe via query parameter
 *   - wipe_param.param: The parameter name (default: "wipe")
 *   - wipe_param.value: The value that triggers wipe (default: "true")
 *
 * Examples:
 *   GET /prometheus              - Returns metrics (no wipe unless auto_wipe=true)
 *   GET /prometheus?wipe=true    - Returns metrics and wipes (default param/value)
 *   GET /prometheus?clear=1      - Custom param/value via config
 */
class MetricsController
{
    public function __construct(
        private readonly PrometheusService $prometheus,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! config('prometheus.enabled', true)) {
            abort(403, 'Prometheus metrics are disabled');
        }

        // Render all metrics first
        $content = $this->prometheus->render();

        // Determine if we should wipe
        $shouldWipe = $this->shouldWipe($request);

        if ($shouldWipe) {
            $this->prometheus->wipe();
        }

        return response($content, 200, [
            'Content-Type' => $this->prometheus->getContentType(),
        ]);
    }

    /**
     * Determine if metrics should be wiped after this request.
     */
    private function shouldWipe(Request $request): bool
    {
        // Auto wipe takes precedence
        if (config('prometheus.auto_wipe', false)) {
            return true;
        }

        // Check if wipe via param is enabled
        if (! config('prometheus.wipe_param.enabled', true)) {
            return false;
        }

        // Get the configured param name and expected value
        $paramName = config('prometheus.wipe_param.param', 'wipe');
        $expectedValue = config('prometheus.wipe_param.value', '1');

        // Check if the request has the wipe param with the expected value
        $actualValue = $request->query($paramName);

        if ($actualValue === null) {
            return false;
        }

        // Normalize both values for comparison
        // This handles Laravel's env() converting "true" to boolean true
        return $this->normalizeWipeValue($actualValue) === $this->normalizeWipeValue($expectedValue);
    }

    /**
     * Normalize a wipe parameter value for comparison.
     *
     * Handles the case where Laravel's env() converts "true"/"false" to boolean.
     */
    private function normalizeWipeValue(mixed $value): string
    {
        // Handle boolean true (from env() conversion)
        if ($value === true) {
            return 'true';
        }

        // Handle boolean false
        if ($value === false) {
            return 'false';
        }

        return (string) $value;
    }
}

