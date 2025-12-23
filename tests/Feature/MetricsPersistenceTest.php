<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Feature;

use Smart145\Prometheus\Facades\Prometheus;
use Smart145\Prometheus\Tests\TestCase;

class MetricsPersistenceTest extends TestCase
{
    public function test_counter_persists_across_requests(): void
    {
        // First request: increment counter
        Prometheus::counter('persistent_counter', 'Persistent counter')->inc();

        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_counter 1');

        // Increment again
        Prometheus::counter('persistent_counter', 'Persistent counter')->inc();

        // Second request: should show accumulated value
        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_counter 2');
    }

    public function test_gauge_persists_across_requests(): void
    {
        Prometheus::gauge('persistent_gauge', 'Persistent gauge')->set(100);

        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_gauge 100');

        // Update the gauge
        Prometheus::gauge('persistent_gauge', 'Persistent gauge')->set(200);

        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_gauge 200');
    }

    public function test_histogram_persists_across_requests(): void
    {
        $histogram = Prometheus::histogram('persistent_histogram', 'Persistent histogram', [], [0.1, 0.5, 1.0]);
        $histogram->observe(0.2);

        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_histogram_count 1');

        // Add more observations
        $histogram->observe(0.3);
        $histogram->observe(0.7);

        $response = $this->get('/prometheus');
        $response->assertSee('test_persistent_histogram_count 3');
    }

    public function test_metrics_accumulate_correctly(): void
    {
        // Multiple increments in sequence
        Prometheus::counter('accumulator', 'Accumulating counter')->incBy(10);
        Prometheus::counter('accumulator', 'Accumulating counter')->incBy(20);
        Prometheus::counter('accumulator', 'Accumulating counter')->incBy(30);

        $response = $this->get('/prometheus');
        $response->assertSee('test_accumulator 60');
    }

    public function test_different_labels_tracked_separately(): void
    {
        Prometheus::counter('labeled_counter', 'Counter with labels', ['env'])
            ->inc(['production']);
        Prometheus::counter('labeled_counter', 'Counter with labels', ['env'])
            ->inc(['staging']);
        Prometheus::counter('labeled_counter', 'Counter with labels', ['env'])
            ->inc(['production']);

        $response = $this->get('/prometheus');
        $response->assertSee('test_labeled_counter{env="production"} 2', escape: false);
        $response->assertSee('test_labeled_counter{env="staging"} 1', escape: false);
    }
}

