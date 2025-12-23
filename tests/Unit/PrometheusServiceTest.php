<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Prometheus\Storage\InMemory;
use Smart145\Prometheus\MetricBuilders\CounterBuilder;
use Smart145\Prometheus\MetricBuilders\GaugeBuilder;
use Smart145\Prometheus\MetricBuilders\HistogramBuilder;
use Smart145\Prometheus\PrometheusService;
use Smart145\Prometheus\Tests\TestCase;

class PrometheusServiceTest extends TestCase
{
    public function test_service_creates_counter(): void
    {
        $prometheus = $this->freshPrometheus();

        $builder = $prometheus->counter('my_counter', 'A test counter');

        $this->assertInstanceOf(CounterBuilder::class, $builder);
    }

    public function test_service_creates_gauge(): void
    {
        $prometheus = $this->freshPrometheus();

        $builder = $prometheus->gauge('my_gauge', 'A test gauge');

        $this->assertInstanceOf(GaugeBuilder::class, $builder);
    }

    public function test_service_creates_histogram(): void
    {
        $prometheus = $this->freshPrometheus();

        $builder = $prometheus->histogram('my_histogram', 'A test histogram');

        $this->assertInstanceOf(HistogramBuilder::class, $builder);
    }

    public function test_service_renders_metrics(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('counter_one', 'Counter one')->inc();
        $prometheus->gauge('gauge_one', 'Gauge one')->set(42);

        $output = $prometheus->render();

        $this->assertStringContainsString('# HELP test_counter_one Counter one', $output);
        $this->assertStringContainsString('# TYPE test_counter_one counter', $output);
        $this->assertStringContainsString('test_counter_one 1', $output);

        $this->assertStringContainsString('# HELP test_gauge_one Gauge one', $output);
        $this->assertStringContainsString('# TYPE test_gauge_one gauge', $output);
        $this->assertStringContainsString('test_gauge_one 42', $output);
    }

    public function test_service_wipes_storage(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('to_be_wiped', 'Will be wiped')->inc();

        $outputBefore = $prometheus->render();
        $this->assertStringContainsString('test_to_be_wiped', $outputBefore);

        $prometheus->wipe();

        $outputAfter = $prometheus->render();
        $this->assertStringNotContainsString('test_to_be_wiped', $outputAfter);
    }

    public function test_service_returns_correct_content_type(): void
    {
        $prometheus = $this->freshPrometheus();

        $contentType = $prometheus->getContentType();

        $this->assertStringContainsString('text/plain; version=0.0.4', $contentType);
    }

    public function test_service_uses_provided_adapter(): void
    {
        $adapter = new InMemory();
        $prometheus = new PrometheusService($adapter);

        $this->assertSame($adapter, $prometheus->getAdapter());
    }

    public function test_service_multiple_metrics_render_together(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('requests', 'Requests')->incBy(10);
        $prometheus->gauge('temperature', 'Temperature')->set(23.5);
        $prometheus->histogram('latency', 'Latency')->observe(0.1);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_requests', $output);
        $this->assertStringContainsString('test_temperature', $output);
        $this->assertStringContainsString('test_latency', $output);
    }
}

