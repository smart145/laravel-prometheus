<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use RuntimeException;
use Smart145\Prometheus\MetricFamilySamples;
use Smart145\Prometheus\RenderTextFormat;
use Smart145\Prometheus\Tests\TestCase;

class RenderTextFormatTest extends TestCase
{
    public function test_render_simple_metric(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'test_counter',
                'type' => 'counter',
                'help' => 'A test counter',
                'labelNames' => [],
                'samples' => [
                    [
                        'name' => 'test_counter',
                        'labelNames' => [],
                        'labelValues' => [],
                        'value' => 42,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        $this->assertStringContainsString('# HELP test_counter A test counter', $output);
        $this->assertStringContainsString('# TYPE test_counter counter', $output);
        $this->assertStringContainsString('test_counter 42', $output);
    }

    public function test_render_metric_with_labels(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'http_requests',
                'type' => 'counter',
                'help' => 'HTTP requests',
                'labelNames' => ['method', 'status'],
                'samples' => [
                    [
                        'name' => 'http_requests',
                        'labelNames' => [],
                        'labelValues' => ['GET', '200'],
                        'value' => 100,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        $this->assertStringContainsString('http_requests{method="GET",status="200"} 100', $output);
    }

    public function test_render_metric_with_timestamp(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'test_gauge',
                'type' => 'gauge',
                'help' => 'A test gauge',
                'labelNames' => [],
                'samples' => [
                    [
                        'name' => 'test_gauge',
                        'labelNames' => [],
                        'labelValues' => [],
                        'value' => 123,
                        'timestamp' => 1703203200000,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        $this->assertStringContainsString('test_gauge 123 1703203200000', $output);
    }

    public function test_render_histogram_with_le_label(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'request_duration',
                'type' => 'histogram',
                'help' => 'Request duration',
                'labelNames' => ['endpoint'],
                'samples' => [
                    [
                        'name' => 'request_duration_bucket',
                        'labelNames' => ['le'],
                        'labelValues' => ['/api', '0.1'],
                        'value' => 5,
                    ],
                    [
                        'name' => 'request_duration_bucket',
                        'labelNames' => ['le'],
                        'labelValues' => ['/api', '+Inf'],
                        'value' => 10,
                    ],
                    [
                        'name' => 'request_duration_sum',
                        'labelNames' => [],
                        'labelValues' => ['/api'],
                        'value' => 1.5,
                    ],
                    [
                        'name' => 'request_duration_count',
                        'labelNames' => [],
                        'labelValues' => ['/api'],
                        'value' => 10,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        $this->assertStringContainsString('request_duration_bucket{endpoint="/api",le="0.1"} 5', $output);
        $this->assertStringContainsString('request_duration_bucket{endpoint="/api",le="+Inf"} 10', $output);
        $this->assertStringContainsString('request_duration_sum{endpoint="/api"} 1.5', $output);
        $this->assertStringContainsString('request_duration_count{endpoint="/api"} 10', $output);
    }

    public function test_label_mismatch_throws_descriptive_error(): void
    {
        $renderer = new RenderTextFormat();

        // Create a metric where label names don't match label values
        // This simulates what happens when labels are changed but old data exists
        $metrics = [
            new MetricFamilySamples([
                'name' => 'cypress_tests_total',
                'type' => 'counter',
                'help' => 'Total Cypress tests',
                'labelNames' => ['state', 'user', 'test_title', 'pr_number'],  // 4 labels
                'samples' => [
                    [
                        'name' => 'cypress_tests_total',
                        'labelNames' => [],
                        'labelValues' => ['passed', 'testuser', 'some-test', 'spec.js', '123'],  // 5 values (old structure)
                        'value' => 1,
                    ],
                ],
            ]),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Label mismatch for metric "cypress_tests_total"');
        $this->expectExceptionMessage('expected 4 labels');
        $this->expectExceptionMessage('but got 5 values');
        $this->expectExceptionMessage('Try wiping Prometheus data with ?wipe=true');

        $renderer->render($metrics);
    }

    public function test_label_mismatch_silent_mode_renders_error_as_comment(): void
    {
        $renderer = new RenderTextFormat();

        // Create a metric with mismatched labels
        $metrics = [
            new MetricFamilySamples([
                'name' => 'bad_metric',
                'type' => 'counter',
                'help' => 'A bad metric',
                'labelNames' => ['a', 'b'],  // 2 labels
                'samples' => [
                    [
                        'name' => 'bad_metric',
                        'labelNames' => [],
                        'labelValues' => ['x', 'y', 'z'],  // 3 values
                        'value' => 1,
                    ],
                ],
            ]),
        ];

        // With silent=true, errors are rendered as comments instead of throwing
        $output = $renderer->render($metrics, silent: true);

        $this->assertStringContainsString('# HELP bad_metric A bad metric', $output);
        $this->assertStringContainsString('# TYPE bad_metric counter', $output);
        $this->assertStringContainsString('# Error:', $output);
        $this->assertStringContainsString('Label mismatch', $output);
    }

    public function test_escapes_special_characters_in_label_values(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'test_metric',
                'type' => 'gauge',
                'help' => 'Test metric',
                'labelNames' => ['path'],
                'samples' => [
                    [
                        'name' => 'test_metric',
                        'labelNames' => [],
                        'labelValues' => ['/path/with"quotes'],
                        'value' => 1,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        // Quotes should be escaped
        $this->assertStringContainsString('path="/path/with\\"quotes"', $output);
    }

    public function test_escapes_newlines_in_label_values(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'test_metric',
                'type' => 'gauge',
                'help' => 'Test metric',
                'labelNames' => ['message'],
                'samples' => [
                    [
                        'name' => 'test_metric',
                        'labelNames' => [],
                        'labelValues' => ["line1\nline2"],
                        'value' => 1,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        // Newlines should be escaped as \n
        $this->assertStringContainsString('message="line1\\nline2"', $output);
    }

    public function test_escapes_backslashes_in_label_values(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'test_metric',
                'type' => 'gauge',
                'help' => 'Test metric',
                'labelNames' => ['path'],
                'samples' => [
                    [
                        'name' => 'test_metric',
                        'labelNames' => [],
                        'labelValues' => ['C:\\path\\to\\file'],
                        'value' => 1,
                    ],
                ],
            ]),
        ];

        $output = $renderer->render($metrics);

        // Backslashes should be escaped
        $this->assertStringContainsString('path="C:\\\\path\\\\to\\\\file"', $output);
    }

    public function test_sorts_metrics_by_name(): void
    {
        $renderer = new RenderTextFormat();

        $metrics = [
            new MetricFamilySamples([
                'name' => 'z_metric',
                'type' => 'counter',
                'help' => 'Z metric',
                'labelNames' => [],
                'samples' => [['name' => 'z_metric', 'labelNames' => [], 'labelValues' => [], 'value' => 1]],
            ]),
            new MetricFamilySamples([
                'name' => 'a_metric',
                'type' => 'counter',
                'help' => 'A metric',
                'labelNames' => [],
                'samples' => [['name' => 'a_metric', 'labelNames' => [], 'labelValues' => [], 'value' => 2]],
            ]),
        ];

        $output = $renderer->render($metrics);

        // a_metric should appear before z_metric
        $aPos = strpos($output, 'a_metric');
        $zPos = strpos($output, 'z_metric');

        $this->assertLessThan($zPos, $aPos);
    }
}

