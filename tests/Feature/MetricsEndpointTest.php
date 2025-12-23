<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Feature;

use Smart145\Prometheus\Facades\Prometheus;
use Smart145\Prometheus\Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    public function test_endpoint_returns_200(): void
    {
        $response = $this->get('/prometheus');

        $response->assertStatus(200);
    }

    public function test_endpoint_returns_correct_content_type(): void
    {
        $response = $this->get('/prometheus');

        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    public function test_endpoint_returns_metrics_in_prometheus_format(): void
    {
        Prometheus::counter('endpoint_counter', 'Test counter')->inc();

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
        $response->assertSee('# HELP test_endpoint_counter Test counter');
        $response->assertSee('# TYPE test_endpoint_counter counter');
    }

    public function test_endpoint_disabled_returns_403(): void
    {
        config(['prometheus.enabled' => false]);

        $response = $this->get('/prometheus');

        $response->assertStatus(403);
    }

    public function test_endpoint_renders_gauge_with_callback(): void
    {
        Prometheus::registerGaugeCallback('callback_gauge', 'Gauge with callback', fn () => 42);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
        $response->assertSee('test_callback_gauge 42');
    }

    public function test_endpoint_renders_multiple_metrics(): void
    {
        Prometheus::counter('requests_total', 'Total requests')->incBy(100);
        Prometheus::gauge('active_users', 'Active users')->set(50);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
        $response->assertSee('test_requests_total 100');
        $response->assertSee('test_active_users 50');
    }

    public function test_endpoint_renders_histogram_buckets(): void
    {
        Prometheus::histogram('latency_seconds', 'Latency', [], [0.1, 0.5, 1.0])
            ->observe(0.3);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
        $response->assertSee('test_latency_seconds_bucket');
        $response->assertSee('test_latency_seconds_sum');
        $response->assertSee('test_latency_seconds_count');
    }
}

