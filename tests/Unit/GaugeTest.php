<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Smart145\Prometheus\Tests\TestCase;

class GaugeTest extends TestCase
{
    public function test_gauge_sets_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->gauge('temperature', 'Current temperature')->set(42.5);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_temperature', $output);
        $this->assertStringContainsString('42.5', $output);
    }

    public function test_gauge_increments(): void
    {
        $prometheus = $this->freshPrometheus();

        $gauge = $prometheus->gauge('connections', 'Active connections');
        $gauge->set(10);
        $gauge->inc();

        $output = $prometheus->render();

        $this->assertStringContainsString('test_connections 11', $output);
    }

    public function test_gauge_decrements(): void
    {
        $prometheus = $this->freshPrometheus();

        $gauge = $prometheus->gauge('connections', 'Active connections');
        $gauge->set(10);
        $gauge->dec();

        $output = $prometheus->render();

        $this->assertStringContainsString('test_connections 9', $output);
    }

    public function test_gauge_with_labels(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->gauge('queue_size', 'Queue size', ['queue'])
            ->set(15, ['emails']);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_queue_size{queue="emails"} 15', $output);
    }

    public function test_gauge_increment_by_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $gauge = $prometheus->gauge('score', 'Player score');
        $gauge->set(100);
        $gauge->incBy(50);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_score 150', $output);
    }

    public function test_gauge_decrement_by_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $gauge = $prometheus->gauge('health', 'Player health');
        $gauge->set(100);
        $gauge->decBy(25);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_health 75', $output);
    }

    public function test_gauge_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $timestamp = 1703184523000;
        $prometheus->gauge('last_updated', 'Last updated timestamp')
            ->withTimestamp($timestamp)
            ->set(1);

        $output = $prometheus->render();
        $this->assertStringContainsString('test_last_updated', $output);
    }

    public function test_gauge_callback_is_registered(): void
    {
        $prometheus = $this->freshPrometheus();

        $callCount = 0;
        $prometheus->registerGaugeCallback(
            'dynamic_value',
            'A dynamic value',
            function () use (&$callCount) {
                $callCount++;

                return 42;
            }
        );

        // Callback should be called during render
        $output = $prometheus->render();

        $this->assertEquals(1, $callCount);
        $this->assertStringContainsString('test_dynamic_value', $output);
        $this->assertStringContainsString('42', $output);
    }
}

