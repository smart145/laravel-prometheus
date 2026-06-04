<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Feature;

use Smart145\Prometheus\PrometheusService;
use Smart145\Prometheus\Tests\TestCase;

class CleanupCommandTest extends TestCase
{
    private function prometheus(): PrometheusService
    {
        return $this->app->make(PrometheusService::class);
    }

    public function test_initializes_idle_clock_when_never_scraped(): void
    {
        $prometheus = $this->prometheus();
        $prometheus->counter('cleanup_counter', 'Test counter')->inc();

        $this->assertNull($prometheus->lastScrapeTime());

        $this->artisan('prometheus:cleanup')
            ->expectsOutputToContain('initialized idle clock')
            ->assertSuccessful();

        // Idle clock is now set and metrics remain untouched.
        $this->assertNotNull($prometheus->lastScrapeTime());
        $this->assertStringContainsString('test_cleanup_counter', $prometheus->render());
    }

    public function test_wipes_when_idle_threshold_exceeded(): void
    {
        config(['prometheus.cleanup.idle_threshold' => 3600]);

        $prometheus = $this->prometheus();
        $prometheus->counter('cleanup_counter', 'Test counter')->inc();
        $prometheus->recordScrape(time() - 7200);

        $this->artisan('prometheus:cleanup')
            ->expectsOutputToContain('wiped')
            ->assertSuccessful();

        $this->assertStringNotContainsString('test_cleanup_counter', $prometheus->render());
    }

    public function test_does_not_wipe_when_within_threshold(): void
    {
        config(['prometheus.cleanup.idle_threshold' => 3600]);

        $prometheus = $this->prometheus();
        $prometheus->counter('cleanup_counter', 'Test counter')->inc();
        $prometheus->recordScrape(time());

        $this->artisan('prometheus:cleanup')
            ->expectsOutputToContain('still fresh')
            ->assertSuccessful();

        $this->assertStringContainsString('test_cleanup_counter', $prometheus->render());
    }

    public function test_force_wipes_regardless_of_idle_time(): void
    {
        $prometheus = $this->prometheus();
        $prometheus->counter('cleanup_counter', 'Test counter')->inc();
        $prometheus->recordScrape(time());

        $this->artisan('prometheus:cleanup', ['--force' => true])
            ->expectsOutputToContain('forced')
            ->assertSuccessful();

        $this->assertStringNotContainsString('test_cleanup_counter', $prometheus->render());
    }
}
