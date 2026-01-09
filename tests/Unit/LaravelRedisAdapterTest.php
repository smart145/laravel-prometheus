<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Smart145\Prometheus\Adapters\LaravelRedisAdapter;
use Smart145\Prometheus\Tests\TestCase;

class LaravelRedisAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_corrupted_samples_are_deleted_when_auto_clean_enabled(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => true]);

        $connection = Mockery::mock(Connection::class);

        // Mock finding one metric with metadata for counter type
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:*:meta'])
            ->andReturn(['PROMETHEUS_counter:test_metric:meta']);

        // Mock empty results for other metric types
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_gauge:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_histogram:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_summary:*:meta'])
            ->andReturn([]);

        // Mock getting the metadata (expects 2 labels)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:meta'])
            ->andReturn([
                'name' => 'test_metric',
                'help' => 'Test metric',
                'labelNames' => json_encode(['label1', 'label2']),
            ]);

        // Mock finding sample keys
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:test_metric:*'])
            ->andReturn(['PROMETHEUS_counter:test_metric:abc123']);

        // Mock getting sample data (has 0 label values - corrupted!)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:abc123'])
            ->andReturn([
                'value' => '5',
                'labelValues' => json_encode([]), // Mismatch: expected 2, got 0
            ]);

        // Expect the corrupted sample to be DELETED
        $connection->shouldReceive('command')
            ->with('del', ['PROMETHEUS_counter:test_metric:abc123'])
            ->once();

        Redis::shouldReceive('connection')
            ->andReturn($connection);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'Corrupted sample') &&
                $context['action'] === 'deleted'
            );

        $adapter = new LaravelRedisAdapter();
        $metrics = $adapter->collect();

        // The corrupted sample should not appear in results
        $this->assertEmpty($metrics);
    }

    public function test_corrupted_samples_are_skipped_but_not_deleted_when_auto_clean_disabled(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => false]);

        $connection = Mockery::mock(Connection::class);

        // Mock finding one metric with metadata for counter type
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:*:meta'])
            ->andReturn(['PROMETHEUS_counter:test_metric:meta']);

        // Mock empty results for other metric types
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_gauge:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_histogram:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_summary:*:meta'])
            ->andReturn([]);

        // Mock getting the metadata (expects 2 labels)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:meta'])
            ->andReturn([
                'name' => 'test_metric',
                'help' => 'Test metric',
                'labelNames' => json_encode(['label1', 'label2']),
            ]);

        // Mock finding sample keys
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:test_metric:*'])
            ->andReturn(['PROMETHEUS_counter:test_metric:abc123']);

        // Mock getting sample data (has 0 label values - corrupted!)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:abc123'])
            ->andReturn([
                'value' => '5',
                'labelValues' => json_encode([]), // Mismatch: expected 2, got 0
            ]);

        Redis::shouldReceive('connection')
            ->andReturn($connection);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'Corrupted sample') &&
                $context['action'] === 'skipped'
            );

        $adapter = new LaravelRedisAdapter();
        $metrics = $adapter->collect();

        // The corrupted sample should not appear in results
        $this->assertEmpty($metrics);
    }

    public function test_valid_samples_are_collected_normally(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => true]);

        $connection = Mockery::mock(Connection::class);

        // Mock finding one metric with metadata for counter type
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:*:meta'])
            ->andReturn(['PROMETHEUS_counter:test_metric:meta']);

        // Mock empty results for other metric types
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_gauge:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_histogram:*:meta'])
            ->andReturn([]);

        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_summary:*:meta'])
            ->andReturn([]);

        // Mock getting the metadata (expects 2 labels)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:meta'])
            ->andReturn([
                'name' => 'test_metric',
                'help' => 'Test metric',
                'labelNames' => json_encode(['method', 'status']),
            ]);

        // Mock finding sample keys
        $connection->shouldReceive('command')
            ->with('keys', ['PROMETHEUS_counter:test_metric:*'])
            ->andReturn(['PROMETHEUS_counter:test_metric:abc123']);

        // Mock getting sample data (correct number of label values)
        $connection->shouldReceive('command')
            ->with('hGetAll', ['PROMETHEUS_counter:test_metric:abc123'])
            ->andReturn([
                'value' => '5',
                'labelValues' => json_encode(['GET', '200']), // Correct: 2 values for 2 labels
            ]);

        Redis::shouldReceive('connection')
            ->andReturn($connection);

        $adapter = new LaravelRedisAdapter();
        $metrics = $adapter->collect();

        // The valid sample should appear in results
        $this->assertCount(1, $metrics);
        $this->assertEquals('test_metric', $metrics[0]->getName());
    }
}
