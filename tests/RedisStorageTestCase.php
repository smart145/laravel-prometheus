<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Smart145\Prometheus\Adapters\LaravelRedisAdapter;
use Smart145\Prometheus\PrometheusService;
use Throwable;

/**
 * Storage tests that run against a real Redis server.
 *
 * The mocked connection cannot show races, key prefixes or Lua behaviour,
 * so these tests use `REDIS_HOST` / `REDIS_PORT` (default 127.0.0.1:6379)
 * and are skipped when no server answers.
 */
abstract class RedisStorageTestCase extends TestCase
{
    /**
     * The Laravel Redis client under test: "phpredis" or "predis".
     */
    abstract protected function redisClient(): string;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', $this->redisClient());
        $app['config']->set('database.redis.options.prefix', 'promtest_');
        $app['config']->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 15,
            'timeout' => 1,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->redisClient() === 'phpredis' && ! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis is not installed');
        }

        try {
            $this->redis()->command('flushdb');
        } catch (Throwable $e) {
            $this->markTestSkipped('No Redis server available: ' . $e->getMessage());
        }

        config(['prometheus.storage' => 'redis']);
    }

    protected function redis(): Connection
    {
        return Redis::connection();
    }

    /**
     * A new service stands for a new PHP process: fresh registry, same Redis.
     */
    protected function process(): PrometheusService
    {
        return new PrometheusService(new LaravelRedisAdapter());
    }

    public function test_all_metric_types_render_from_redis(): void
    {
        $prometheus = $this->process();

        $prometheus->counter('requests_total', 'Requests', ['method', 'status'])->inc(['GET', '200']);
        $prometheus->counter('requests_total', 'Requests', ['method', 'status'])->incBy(4, ['GET', '200']);
        $prometheus->gauge('queue_size', 'Queue')->set(7);
        $prometheus->gauge('queue_size', 'Queue')->dec();

        $histogram = $prometheus->histogram('duration_seconds', 'Duration', ['method'], [0.1, 0.5, 1, 2.5]);
        foreach ([0.05, 0.1, 0.3, 1.0, 2.0, 9.0] as $value) {
            $histogram->observe($value, ['GET']);
        }

        $output = $this->process()->render();

        $this->assertStringContainsString('test_requests_total{method="GET",status="200"} 5', $output);
        $this->assertStringContainsString("test_queue_size 6\n", $output);
        $this->assertStringContainsString('test_duration_seconds_bucket{method="GET",le="0.1"} 2', $output);
        $this->assertStringContainsString('test_duration_seconds_bucket{method="GET",le="0.5"} 3', $output);
        $this->assertStringContainsString('test_duration_seconds_bucket{method="GET",le="1"} 4', $output);
        $this->assertStringContainsString('test_duration_seconds_bucket{method="GET",le="2.5"} 5', $output);
        $this->assertStringContainsString('test_duration_seconds_bucket{method="GET",le="+Inf"} 6', $output);
        $this->assertStringContainsString('test_duration_seconds_sum{method="GET"} 12.45', $output);
        $this->assertStringContainsString('test_duration_seconds_count{method="GET"} 6', $output);
    }

    public function test_label_values_of_different_php_types_share_one_series(): void
    {
        $counter = $this->process()->counter('http_total', 'Requests', ['status']);

        $counter->inc(['200']);
        $counter->inc([200]);
        $counter->inc(['status' => '200']);

        $output = $this->process()->render();

        $this->assertSame(1, substr_count($output, 'test_http_total{status="200"}'));
        $this->assertStringContainsString('test_http_total{status="200"} 3', $output);
    }

    public function test_counter_adds_float_and_integer_increments(): void
    {
        $counter = $this->process()->counter('bytes_total', 'Bytes');

        $counter->incBy(1.5);
        $counter->inc();
        $counter->incBy(2);

        $this->assertStringContainsString("test_bytes_total 4.5\n", $this->process()->render());
    }

    public function test_counter_rejects_negative_increment(): void
    {
        $counter = $this->process()->counter('orders_total', 'Orders');
        $counter->incBy(10);

        try {
            $counter->incBy(-3);
            $this->fail('A negative counter increment must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('test_orders_total', $e->getMessage());
        }

        config(['prometheus.label_mismatch_behavior' => 'ignore']);
        $counter->withTimestamp(1700000000000)->incBy(-3);

        $this->assertStringContainsString("test_orders_total 10\n", $this->process()->render());
    }

    public function test_write_without_timestamp_clears_an_earlier_timestamp(): void
    {
        $prometheus = $this->process();

        $prometheus->counter('events_total', 'Events')->withTimestamp(1700000000000)->inc();
        $this->assertStringContainsString("test_events_total 1 1700000000000\n", $prometheus->render());

        $prometheus->counter('events_total', 'Events')->inc();

        $this->assertStringContainsString("test_events_total 2\n", $prometheus->render());
    }

    public function test_timestamps_render_for_gauge_and_histogram(): void
    {
        $prometheus = $this->process();

        $prometheus->gauge('backup', 'Backup')->withTimestamp(1700000000000)->set(3);
        $prometheus->histogram('job_seconds', 'Job', [], [1, 5])->withTimestamp(1700000000001)->observe(2);

        $output = $prometheus->render();

        $this->assertStringContainsString("test_backup 3 1700000000000\n", $output);
        $this->assertStringContainsString('test_job_seconds_bucket{le="5"} 1 1700000000001', $output);
        $this->assertStringContainsString("test_job_seconds_count 1 1700000000001\n", $output);
    }

    public function test_render_and_wipe_returns_the_samples_and_clears_storage(): void
    {
        $prometheus = $this->process();
        $prometheus->counter('jobs_total', 'Jobs')->incBy(10);
        $prometheus->histogram('job_seconds', 'Job', ['queue'], [1])->observe(0.5, ['default']);

        $first = $prometheus->renderAndWipe();
        $second = $prometheus->render();

        $this->assertStringContainsString("test_jobs_total 10\n", $first);
        $this->assertStringContainsString('test_job_seconds_count{queue="default"} 1', $first);
        $this->assertStringNotContainsString('test_jobs_total', $second);
        $this->assertStringNotContainsString('test_job_seconds', $second);
        $this->assertSame([], $this->metricKeys());
    }

    public function test_increment_after_a_wiping_scrape_is_kept_for_the_next_scrape(): void
    {
        $prometheus = $this->process();
        $counter = $prometheus->counter('jobs_total', 'Jobs');

        $counter->incBy(10);
        $this->get('/prometheus?wipe=1')->assertOk()->assertSee('test_jobs_total 10');
        $counter->incBy(3);

        $this->get('/prometheus?wipe=1')->assertOk()->assertSee('test_jobs_total 3');
    }

    public function test_scrape_reads_all_samples_in_one_redis_call_without_keys(): void
    {
        $prometheus = $this->process();
        for ($i = 0; $i < 20; $i++) {
            $prometheus->counter('routes_total', 'Routes', ['route'])->inc(["/r/{$i}"]);
        }
        $prometheus->render(); // first render may adopt samples of an earlier version

        $before = $this->commandCalls();
        $output = $prometheus->render();
        $after = $this->commandCalls();

        $this->assertSame(20, substr_count($output, 'test_routes_total{'));
        $this->assertSame(0, ($after['keys'] ?? 0) - ($before['keys'] ?? 0), 'render() must not run KEYS');
        $this->assertSame(1, ($after['eval'] ?? 0) - ($before['eval'] ?? 0), 'render() must read in one script call');
    }

    public function test_samples_keep_their_own_label_names_when_the_metric_is_redefined(): void
    {
        $this->process()->counter('api_total', 'API', ['method', 'status'])->incBy(100, ['GET', '200']);
        $this->process()->counter('api_total', 'API', ['status', 'method'])->inc(['500', 'POST']);
        $this->process()->counter('api_total', 'API', ['method'])->inc(['PUT']);

        Log::spy();
        $output = $this->process()->render();

        $this->assertStringContainsString('test_api_total{method="GET",status="200"} 100', $output);
        $this->assertStringContainsString('test_api_total{status="500",method="POST"} 1', $output);
        $this->assertStringContainsString('test_api_total{method="PUT"} 1', $output);
        $this->assertSame(1, substr_count($output, '# TYPE test_api_total counter'));
    }

    public function test_histogram_keeps_observations_when_buckets_change(): void
    {
        $old = $this->process();
        $old->histogram('latency_seconds', 'Latency', [], [0.1, 1])->observe(0.5);
        $old->histogram('latency_seconds', 'Latency', [], [0.1, 1])->observe(0.5);

        $this->process()->histogram('latency_seconds', 'Latency', [], [0.25, 2])->observe(0.2);

        $output = $this->process()->render();

        $this->assertStringContainsString('test_latency_seconds_bucket{le="0.25"} 1', $output);
        $this->assertStringContainsString('test_latency_seconds_bucket{le="1"} 3', $output);
        $this->assertStringContainsString('test_latency_seconds_bucket{le="+Inf"} 3', $output);
        $this->assertStringContainsString("test_latency_seconds_count 3\n", $output);
        $this->assertStringContainsString("test_latency_seconds_sum 1.2\n", $output);
    }

    public function test_histogram_uses_the_first_buckets_declared_in_a_process(): void
    {
        $prometheus = $this->process();

        $prometheus->histogram('x_seconds', 'X', [], [1, 2])->observe(1.5);
        $prometheus->histogram('x_seconds', 'X', [], [5, 10])->observe(1.5);
        $prometheus->histogram('x_seconds', 'X', [], [5, 10])->withTimestamp(1700000000000)->observe(1.5);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_x_seconds_bucket{le="2"} 3', $output);
        $this->assertStringNotContainsString('le="5"', $output);
    }

    public function test_same_name_cannot_be_stored_as_two_metric_types(): void
    {
        $prometheus = $this->process();
        $prometheus->counter('thing', 'Thing')->inc();

        try {
            $prometheus->gauge('thing', 'Thing')->set(5);
            $this->fail('A second metric type for the same name must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('test_thing', $e->getMessage());
        }

        $output = $prometheus->render();

        $this->assertSame(1, substr_count($output, '# TYPE test_thing'));
        $this->assertStringContainsString("test_thing 1\n", $output);
    }

    public function test_gauge_stores_large_and_non_finite_values(): void
    {
        $prometheus = $this->process();
        $prometheus->gauge('big', 'Big')->set(1234567890123456.0);
        $prometheus->gauge('precise', 'Precise')->set(1700000000.123456);
        $prometheus->gauge('nan', 'NaN')->set(NAN);
        $prometheus->gauge('inf', 'Inf')->set(INF);
        $prometheus->gauge('ninf', 'Inf')->set(-INF);

        $output = $prometheus->render();

        $this->assertStringContainsString("test_big 1234567890123456\n", $output);
        $this->assertStringContainsString("test_precise 1700000000.123456\n", $output);
        $this->assertStringContainsString("test_nan NaN\n", $output);
        $this->assertStringContainsString("test_inf +Inf\n", $output);
        $this->assertStringContainsString("test_ninf -Inf\n", $output);
    }

    public function test_failing_gauge_callback_does_not_expose_a_stale_value(): void
    {
        $prometheus = $this->process();
        $calls = 0;
        $prometheus->registerGaugeCallback('users', 'Users', function () use (&$calls) {
            if (++$calls > 1) {
                throw new \RuntimeException('database is down');
            }

            return 42;
        });
        $prometheus->counter('other_total', 'Other')->inc();

        $this->assertStringContainsString("test_users 42\n", $prometheus->render());

        $second = $prometheus->render();
        $this->assertStringNotContainsString('test_users', $second);
        $this->assertStringContainsString("test_other_total 1\n", $second);
    }

    public function test_samples_written_by_an_earlier_version_are_still_rendered(): void
    {
        // Key layout of version 1.0: no index, integer counter value, no per-sample label names.
        $redis = $this->redis();
        $redis->command('hMSet', ['PROMETHEUS_counter:test_legacy_total:meta', [
            'name' => 'test_legacy_total', 'help' => 'Legacy', 'labelNames' => json_encode(['state']),
        ]]);
        $sampleKey = 'PROMETHEUS_counter:test_legacy_total:' . md5(json_encode(['paid']));
        $redis->command('hMSet', [$sampleKey, ['value' => '7', 'labelValues' => json_encode(['paid'])]]);

        $this->assertStringContainsString('test_legacy_total{state="paid"} 7', $this->process()->render());

        $this->process()->counter('legacy_total', 'Legacy', ['state'])->inc(['paid']);

        $this->assertStringContainsString('test_legacy_total{state="paid"} 8', $this->process()->render());
    }

    public function test_sample_of_an_earlier_version_with_an_integer_label_is_not_a_second_series(): void
    {
        // Version 1.0 built the key from the raw label values: [200], not ["200"].
        $redis = $this->redis();
        $redis->command('hMSet', ['PROMETHEUS_counter:test_http_total:meta', [
            'name' => 'test_http_total', 'help' => 'HTTP', 'labelNames' => json_encode(['status']),
        ]]);
        $redis->command('hMSet', ['PROMETHEUS_counter:test_http_total:' . md5(json_encode([200])), [
            'value' => '7', 'labelValues' => json_encode([200]),
        ]]);

        $this->process()->counter('http_total', 'HTTP', ['status'])->inc(['200']);

        $output = $this->process()->render();

        $this->assertSame(1, substr_count($output, 'test_http_total{status="200"}'));
        $this->assertStringContainsString('test_http_total{status="200"} 8', $output);
    }

    public function test_corrupted_sample_cleanup_keeps_a_sample_that_a_writer_repaired(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => true]);
        Log::spy();

        $prometheus = $this->process();
        $prometheus->counter('pay_total', 'Pay', ['currency'])->inc(['eur']);
        $key = 'PROMETHEUS_counter:test_pay_total:' . md5(json_encode(['eur']));

        // The collect script saw a corrupted sample; a writer repaired it before the cleanup.
        $clean = new \ReflectionClassConstant(LaravelRedisAdapter::class, 'CLEAN_SCRIPT');
        $deleted = $this->redis()->eval(
            $clean->getValue(),
            2,
            $key,
            'PROMETHEUS_counter:test_pay_total:index',
            md5(json_encode(['eur'])),
            json_encode([]),
            ''
        );

        $this->assertSame(0, (int) $deleted);
        $this->assertStringContainsString('test_pay_total{currency="eur"} 1', $prometheus->render());
    }

    public function test_gauge_callback_replaces_a_stored_sample_with_the_same_labels_in_another_order(): void
    {
        $prometheus = $this->process();
        $prometheus->gauge('workers', 'Workers', ['tenant', 'region'])->set(1, ['acme', 'us']);
        $prometheus->registerGaugeCallback('workers', 'Workers', fn () => 5, ['region', 'tenant'], ['us', 'acme']);

        $output = $prometheus->render();

        $this->assertSame(1, substr_count($output, 'test_workers{'));
        $this->assertStringContainsString('test_workers{region="us",tenant="acme"} 5', $output);
    }

    public function test_gauge_callback_with_the_name_of_a_stored_counter_keeps_the_counter(): void
    {
        $prometheus = $this->process();
        $prometheus->counter('jobs', 'Jobs')->incBy(4);
        $prometheus->registerGaugeCallback('jobs', 'Jobs', fn () => 9);

        $output = $prometheus->render();

        $this->assertStringContainsString("# TYPE test_jobs counter\ntest_jobs 4\n", $output);
        $this->assertStringNotContainsString('test_jobs 9', $output);
    }

    public function test_corrupted_sample_is_removed_and_other_samples_render(): void
    {
        config(['prometheus.auto_clean_corrupted_samples' => true]);
        Log::spy();

        $prometheus = $this->process();
        $prometheus->counter('pay_total', 'Pay', ['currency'])->inc(['eur']);

        $badKey = 'PROMETHEUS_counter:test_pay_total:' . md5('bad');
        $this->redis()->command('hMSet', [$badKey, ['value' => '5', 'labelValues' => json_encode([])]]);
        $this->redis()->command('sadd', ['PROMETHEUS_counter:test_pay_total:index', md5('bad')]);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_pay_total{currency="eur"} 1', $output);
        $this->assertSame(1, substr_count($output, 'test_pay_total{'));
        $this->assertSame(0, (int) $this->redis()->command('exists', [$badKey]));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_wipe_removes_every_prometheus_key_but_no_other_key(): void
    {
        $prometheus = $this->process();
        $prometheus->counter('a_total', 'A')->inc();
        $prometheus->recordScrape(123);
        $this->redis()->command('set', ['PROMETHEUS_stray', '1']);
        $this->redis()->command('set', ['cache:unrelated', '1']);

        $prometheus->wipe();

        $this->assertSame([], $this->metricKeys());
        $this->assertNull($prometheus->lastScrapeTime());
        $this->assertSame(1, (int) $this->redis()->command('exists', ['cache:unrelated']));
    }

    public function test_wiping_scrape_keeps_gauges_when_wipe_gauges_is_off(): void
    {
        config(['prometheus.wipe_gauges' => false]);

        $prometheus = $this->process();
        $gauge = $prometheus->gauge('jobs_in_progress', 'Jobs', ['queue']);
        $gauge->inc(['default']);
        $gauge->inc(['default']);
        $gauge->inc(['default']);
        $prometheus->counter('jobs_total', 'Jobs')->incBy(3);
        $prometheus->histogram('job_seconds', 'Job', [], [1])->observe(0.5);

        $first = $this->get('/prometheus?wipe=1')->assertOk()->getContent();
        $gauge->dec(['default']);
        $gauge->dec(['default']);
        $second = $this->get('/prometheus?wipe=1')->assertOk()->getContent();

        $this->assertStringContainsString('test_jobs_in_progress{queue="default"} 3', $first);
        $this->assertStringContainsString("test_jobs_total 3\n", $first);
        $this->assertStringContainsString('test_jobs_in_progress{queue="default"} 1', $second);
        $this->assertStringNotContainsString('test_jobs_total', $second);
        $this->assertStringNotContainsString('test_job_seconds', $second);
    }

    public function test_wiping_scrape_wipes_gauges_by_default(): void
    {
        $prometheus = $this->process();
        $prometheus->gauge('jobs_in_progress', 'Jobs')->set(3);

        $this->get('/prometheus?wipe=1')->assertOk()->assertSee('test_jobs_in_progress 3');
        $this->get('/prometheus?wipe=1')->assertOk()->assertDontSee('test_jobs_in_progress');
    }

    public function test_full_wipe_removes_gauges_when_wipe_gauges_is_off(): void
    {
        config(['prometheus.wipe_gauges' => false]);

        $prometheus = $this->process();
        $prometheus->gauge('jobs_in_progress', 'Jobs')->set(3);

        $this->artisan('prometheus:cleanup', ['--force' => true])->assertSuccessful();

        $this->assertStringNotContainsString('test_jobs_in_progress', $prometheus->render());
        $this->assertSame([], $this->metricKeys());
    }

    public function test_gauge_sample_that_is_not_written_for_the_gauge_ttl_is_removed(): void
    {
        config(['prometheus.wipe_gauges' => false, 'prometheus.gauge_ttl' => 600]);

        $prometheus = $this->process();
        $gauge = $prometheus->gauge('workers', 'Workers', ['host']);
        $gauge->set(4, ['old']);
        $gauge->set(2, ['new']);
        $prometheus->gauge('retired', 'Retired')->set(1);
        $prometheus->counter('old_total', 'Old')->inc();

        // The "old" host and the "retired" gauge were last written 601 seconds ago.
        $past = (string) (time() - 601);
        $this->redis()->command('hset', ['PROMETHEUS_gauge:test_workers:' . md5(json_encode(['old'])), 'updated', $past]);
        $this->redis()->command('hset', ['PROMETHEUS_gauge:test_retired:' . md5(json_encode([])), 'updated', $past]);
        $this->redis()->command('hset', ['PROMETHEUS_counter:test_old_total:' . md5(json_encode([])), 'updated', $past]);

        $output = $prometheus->render();

        $this->assertStringContainsString('test_workers{host="new"} 2', $output);
        $this->assertStringNotContainsString('host="old"', $output);
        $this->assertStringNotContainsString('test_retired', $output);
        $this->assertStringContainsString("test_old_total 1\n", $output, 'The TTL applies to gauges only');
        $this->assertSame([], array_values(array_filter(
            $this->metricKeys(),
            fn (string $key): bool => str_contains($key, 'test_retired') || str_ends_with($key, md5(json_encode(['old'])))
        )));
    }

    public function test_gauge_ttl_is_off_by_default(): void
    {
        $prometheus = $this->process();
        $prometheus->gauge('workers', 'Workers')->set(4);
        $this->redis()->command('hset', ['PROMETHEUS_gauge:test_workers:' . md5(json_encode([])), 'updated', '1']);

        $this->assertStringContainsString("test_workers 4\n", $prometheus->render());
    }

    /**
     * Keys that hold metric data (the schema marker is bookkeeping, not data).
     *
     * @return string[]
     */
    private function metricKeys(): array
    {
        $keys = array_map(
            fn (string $key): string => substr($key, strlen('promtest_')),
            $this->redis()->command('keys', ['PROMETHEUS_*'])
        );

        return array_values(array_diff($keys, ['PROMETHEUS_schema']));
    }

    /**
     * @return array<string, int> Redis command name => number of calls so far
     */
    private function commandCalls(): array
    {
        $calls = [];
        $stats = json_encode($this->redis()->command('info', ['commandstats']));
        preg_match_all('/cmdstat_(\w+)"?[:,"\s{a-z]*calls["=:]+(\d+)/i', (string) $stats, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $calls[strtolower($match[1])] = (int) $match[2];
        }

        return $calls;
    }
}
