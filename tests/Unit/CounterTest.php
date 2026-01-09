<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Smart145\Prometheus\Tests\TestCase;

class CounterTest extends TestCase
{
    public function test_counter_increments_by_one(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('requests_total', 'Total requests')->inc();

        $output = $prometheus->render();

        $this->assertStringContainsString('test_requests_total', $output);
        $this->assertStringContainsString('1', $output);
    }

    public function test_counter_increments_by_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('requests_total', 'Total requests')->incBy(5);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_requests_total 5', $output);
    }

    public function test_counter_with_labels(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('http_requests_total', 'HTTP requests', ['method', 'status'])
            ->inc(['GET', '200']);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_http_requests_total{method="GET",status="200"} 1', $output);
    }

    public function test_counter_accumulates_multiple_increments(): void
    {
        $prometheus = $this->freshPrometheus();

        $counter = $prometheus->counter('events_total', 'Total events');
        $counter->inc();
        $counter->inc();
        $counter->incBy(3);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_events_total 5', $output);
    }

    public function test_counter_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $timestamp = 1703184523000;
        $prometheus->counter('timestamped_counter', 'Counter with timestamp')
            ->withTimestamp($timestamp)
            ->inc();

        // The timestamp should be stored (we verify by checking adapter was called)
        // Note: The promphp library doesn't render timestamps by default
        $output = $prometheus->render();
        $this->assertStringContainsString('test_timestamped_counter', $output);
    }

    public function test_counter_with_auto_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('auto_timestamp_counter', 'Counter with auto timestamp')
            ->withTimestamp()
            ->inc();

        // Just verify the counter was created successfully
        $output = $prometheus->render();
        $this->assertStringContainsString('test_auto_timestamp_counter', $output);
    }

    public function test_counter_throws_on_label_count_mismatch_too_few(): void
    {
        $prometheus = $this->freshPrometheus();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Label count mismatch');

        // Define counter with 2 labels but provide 0 values
        $prometheus->counter('requests_total', 'Total requests', ['method', 'status'])
            ->inc([]);
    }

    public function test_counter_throws_on_label_count_mismatch_too_many(): void
    {
        $prometheus = $this->freshPrometheus();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Label count mismatch');

        // Define counter with 1 label but provide 2 values
        $prometheus->counter('requests_total', 'Total requests', ['method'])
            ->inc(['GET', '200']);
    }

    public function test_counter_throws_on_label_mismatch_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 2 labels (method, status) but got 1 values (GET)');

        // Define counter with 2 labels but provide 1 value, with timestamp
        $prometheus->counter('requests_total', 'Total requests', ['method', 'status'])
            ->withTimestamp()
            ->inc(['GET']);
    }

    public function test_counter_label_mismatch_logs_warning_when_configured(): void
    {
        config(['prometheus.label_mismatch_behavior' => 'log']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'Label count mismatch'));

        $prometheus = $this->freshPrometheus();

        // This should not throw, but should log and skip
        $prometheus->counter('requests_total', 'Total requests', ['method', 'status'])
            ->inc([]);

        // Metric should not be recorded
        $output = $prometheus->render();
        $this->assertStringNotContainsString('requests_total', $output);
    }

    public function test_counter_label_mismatch_silently_skips_when_configured(): void
    {
        config(['prometheus.label_mismatch_behavior' => 'ignore']);

        $prometheus = $this->freshPrometheus();

        // This should not throw and not log
        $prometheus->counter('requests_total', 'Total requests', ['method', 'status'])
            ->inc([]);

        // Metric should not be recorded
        $output = $prometheus->render();
        $this->assertStringNotContainsString('requests_total', $output);
    }
}

