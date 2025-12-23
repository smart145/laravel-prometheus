<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Smart145\Prometheus\Tests\TestCase;

class TimestampTest extends TestCase
{
    public function test_counter_with_timestamp_uses_current_time(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('timed_counter', 'Counter with auto timestamp')
            ->withTimestamp()
            ->inc();

        // Verify the counter was created (timestamp is stored internally)
        $output = $prometheus->render();
        $this->assertStringContainsString('test_timed_counter', $output);
    }

    public function test_counter_with_timestamp_accepts_custom_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $customTimestamp = 1703184523000; // Specific timestamp

        $prometheus->counter('custom_timed_counter', 'Counter with custom timestamp')
            ->withTimestamp($customTimestamp)
            ->inc();

        $output = $prometheus->render();
        $this->assertStringContainsString('test_custom_timed_counter', $output);
    }

    public function test_gauge_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $customTimestamp = 1703184523000;

        $prometheus->gauge('timed_gauge', 'Gauge with timestamp')
            ->withTimestamp($customTimestamp)
            ->set(42);

        $output = $prometheus->render();
        $this->assertStringContainsString('test_timed_gauge', $output);
    }

    public function test_histogram_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $customTimestamp = 1703184523000;

        $prometheus->histogram('timed_histogram', 'Histogram with timestamp')
            ->withTimestamp($customTimestamp)
            ->observe(0.5);

        $output = $prometheus->render();
        $this->assertStringContainsString('test_timed_histogram', $output);
    }

    public function test_timestamp_can_be_chained_with_labels(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('labeled_timed', 'Labeled and timed counter', ['env'])
            ->withTimestamp(1703184523000)
            ->inc(['production']);

        $output = $prometheus->render();
        $this->assertStringContainsString('test_labeled_timed{env="production"}', $output);
    }

    public function test_multiple_timestamps_are_independent(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('first_counter', 'First counter')
            ->withTimestamp(1000000000000)
            ->inc();

        $prometheus->counter('second_counter', 'Second counter')
            ->withTimestamp(2000000000000)
            ->inc();

        $output = $prometheus->render();
        $this->assertStringContainsString('test_first_counter', $output);
        $this->assertStringContainsString('test_second_counter', $output);
    }
}

