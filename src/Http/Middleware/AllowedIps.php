<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to restrict access to the Prometheus metrics endpoint by IP address.
 *
 * Configure allowed IPs in config/prometheus.php or via PROMETHEUS_ALLOWED_IPS env var.
 * An empty list allows all IPs (useful for development).
 */
class AllowedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('prometheus.allowed_ips', []);

        // Empty list means allow all
        if (empty($allowedIps)) {
            return $next($request);
        }

        $clientIp = $request->ip();

        if (! in_array($clientIp, $allowedIps, true)) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}

