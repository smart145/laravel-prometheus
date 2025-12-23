<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Smart145\Prometheus\Tests\TestCase;

class HistogramTest extends TestCase
{
    public function test_histogram_observes_value(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->histogram('request_duration_seconds', 'Request duration')
            ->observe(0.25);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_request_duration_seconds_bucket', $output);
        $this->assertStringContainsString('test_request_duration_seconds_sum', $output);
        $this->assertStringContainsString('test_request_duration_seconds_count', $output);
    }

    public function test_histogram_with_labels(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->histogram('http_duration_seconds', 'HTTP duration', ['method', 'status'])
            ->observe(0.5, ['GET', '200']);

        $output = $prometheus->render();

        $this->assertStringContainsString('method="GET"', $output);
        $this->assertStringContainsString('status="200"', $output);
    }

    public function test_histogram_with_custom_buckets(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->histogram('custom_duration', 'Custom duration', [], [0.1, 0.5, 1.0, 5.0])
            ->observe(0.3);

        $output = $prometheus->render();

        // Should have bucket for 0.5 (0.3 falls into this bucket)
        $this->assertStringContainsString('le="0.5"', $output);
        $this->assertStringContainsString('le="1"', $output);
        $this->assertStringContainsString('le="+Inf"', $output);
    }

    public function test_histogram_accumulates_observations(): void
    {
        $prometheus = $this->freshPrometheus();

        $histogram = $prometheus->histogram('latency_seconds', 'Latency');
        $histogram->observe(0.1);
        $histogram->observe(0.2);
        $histogram->observe(0.3);

        $output = $prometheus->render();

        // Count should be 3
        $this->assertStringContainsString('test_latency_seconds_count 3', $output);
        // Sum should be 0.6
        $this->assertStringContainsString('test_latency_seconds_sum 0.6', $output);
    }

    public function test_histogram_with_timestamp(): void
    {
        $prometheus = $this->freshPrometheus();

        $timestamp = 1703184523000;
        $prometheus->histogram('timed_histogram', 'Histogram with timestamp')
            ->withTimestamp($timestamp)
            ->observe(0.5);

        $output = $prometheus->render();
        $this->assertStringContainsString('test_timed_histogram', $output);
    }

    public function test_histogram_buckets_are_cumulative(): void
    {
        $prometheus = $this->freshPrometheus();

        $histogram = $prometheus->histogram('size_bytes', 'Size in bytes', [], [10, 50, 100]);
        $histogram->observe(5);   // Goes in 10 bucket
        $histogram->observe(25);  // Goes in 50 bucket
        $histogram->observe(75);  // Goes in 100 bucket

        $output = $prometheus->render();

        // The buckets should be cumulative
        $this->assertStringContainsString('test_size_bytes_bucket{le="10"} 1', $output);
        $this->assertStringContainsString('test_size_bytes_bucket{le="50"} 2', $output);
        $this->assertStringContainsString('test_size_bytes_bucket{le="100"} 3', $output);
        $this->assertStringContainsString('test_size_bytes_bucket{le="+Inf"} 3', $output);
    }
}

