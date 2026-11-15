<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Smart145\Prometheus\Adapters\LaravelRedisAdapter;
use Smart145\Prometheus\RenderTextFormat;
use Smart145\Prometheus\Tests\TestCase;

/**
 * Tests of how the adapter reads the reply of its collect script.
 *
 * The connection is mocked, so these tests run without a Redis server.
 * Behaviour that needs real Redis is in RedisStorageTestCase.
 */
class LaravelRedisAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A connection whose collect script returns one counter with the given samples.
     *
     * @param  array<int, array{0: string, 1: array<int, string>}>  $samples
     */
    private function connectionWithCounter(array $samples): MockInterface
    {
        $connection = Mockery::mock(Connection::class);

        $connection->shouldReceive('eval')->once()->andReturn([[
            'counter',
            'test_metric',
            ['name', 'test_metric', 'help', 'Test metric', 'labelNames', json_encode(['label1', 'label2'])],
            $samples,
        ]]);

        Redis::shouldReceive('connection')->andReturn($connection);

        return $connection;
    }

    public function test_corrupted_samples_are_deleted_when_auto_clean_enabled(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => true]);

        // The sample has 0 label values, the metric has 2 label names: corrupted.
        $connection = $this->connectionWithCounter([
            ['abc123', ['value', '5', 'labelValues', json_encode([])]],
        ]);

        $connection->shouldReceive('command')
            ->with('del', ['PROMETHEUS_counter:test_metric:abc123'])
            ->once();
        $connection->shouldReceive('command')
            ->with('srem', ['PROMETHEUS_counter:test_metric:index', 'abc123'])
            ->once();

        Log::shouldReceive('warning')
            ->once()
            ->with('Prometheus: Corrupted sample with label mismatch', Mockery::on(function ($context) {
                return $context['metric'] === 'test_metric'
                    && $context['expected_labels'] === ['label1', 'label2']
                    && $context['actual_values'] === []
                    && $context['sample_key'] === 'PROMETHEUS_counter:test_metric:abc123'
                    && $context['action'] === 'deleted';
            }));

        $this->assertSame([], (new LaravelRedisAdapter())->collect());
    }

    public function test_corrupted_samples_are_skipped_but_not_deleted_when_auto_clean_disabled(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => false]);

        $connection = $this->connectionWithCounter([
            ['abc123', ['value', '5', 'labelValues', json_encode([])]],
        ]);

        $connection->shouldNotReceive('command');

        Log::shouldReceive('warning')
            ->once()
            ->with('Prometheus: Corrupted sample with label mismatch', Mockery::on(function ($context) {
                return $context['action'] === 'skipped';
            }));

        $this->assertSame([], (new LaravelRedisAdapter())->collect());
    }

    public function test_valid_samples_are_collected_normally(): void
    {
        $connection = $this->connectionWithCounter([
            ['bbb', ['value', '2', 'labelValues', json_encode(['b', 'y']), 'labelNames', json_encode(['label1', 'label2'])]],
            ['aaa', ['value', '5', 'labelValues', json_encode(['a', 'x'])]],
        ]);

        $connection->shouldNotReceive('command');
        Log::shouldReceive('warning')->never();

        $metrics = (new LaravelRedisAdapter())->collect();

        $this->assertCount(1, $metrics);
        $this->assertSame('test_metric', $metrics[0]->getName());
        $this->assertSame(
            "# HELP test_metric Test metric\n"
            . "# TYPE test_metric counter\n"
            . "test_metric{label1=\"a\",label2=\"x\"} 5\n"
            . "test_metric{label1=\"b\",label2=\"y\"} 2\n",
            (new RenderTextFormat())->render($metrics)
        );
    }

    public function test_collect_throws_when_the_redis_script_fails(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('eval')->once()->andReturn(false);
        Redis::shouldReceive('connection')->andReturn($connection);

        $this->expectException(RuntimeException::class);

        (new LaravelRedisAdapter())->collect();
    }
}
