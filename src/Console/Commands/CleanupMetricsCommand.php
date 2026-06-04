<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Smart145\Prometheus\PrometheusService;

/**
 * Wipe stored metrics when they haven't been scraped for too long.
 *
 * Metrics are normally cleared when a Prometheus scrape hits the endpoint.
 * If nothing scrapes, nothing wipes and the Redis storage grows unbounded.
 * This command detects an idle period (no scrape within the configured
 * threshold) and wipes everything to prevent the storage from filling up.
 */
class CleanupMetricsCommand extends Command
{
    protected $signature = 'prometheus:cleanup {--force : Wipe regardless of idle time}';

    protected $description = 'Wipe stored Prometheus metrics when they have not been scraped within the configured idle threshold';

    public function handle(PrometheusService $prometheus): int
    {
        if ($this->option('force')) {
            $prometheus->wipe();
            $this->info('Prometheus metrics wiped (forced).');

            return self::SUCCESS;
        }

        $threshold = (int) config('prometheus.cleanup.idle_threshold', 3600);
        $last = $prometheus->lastScrapeTime();
        $now = time();

        if ($last === null) {
            // Never scraped yet: start the idle clock now so freshly deployed
            // metrics get a full window before they can be wiped.
            $prometheus->recordScrape($now);
            $this->info('No previous scrape recorded; initialized idle clock. Nothing wiped.');

            return self::SUCCESS;
        }

        $idle = $now - $last;

        if ($idle >= $threshold) {
            $prometheus->wipe();

            $message = sprintf(
                'Prometheus metrics wiped after %d seconds idle (threshold: %d seconds).',
                $idle,
                $threshold
            );

            Log::warning('Prometheus: ' . $message);
            $this->warn($message);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Metrics still fresh: idle for %d seconds, wipes after %d seconds (%d seconds remaining).',
            $idle,
            $threshold,
            $threshold - $idle
        ));

        return self::SUCCESS;
    }
}
