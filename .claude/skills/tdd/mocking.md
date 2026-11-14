# When to mock

Mock only at **system boundaries**:

- The Redis connection (`Illuminate\Support\Facades\Redis`,
  `Illuminate\Redis\Connections\Connection`), as in
  `tests/Unit/LaravelRedisAdapterTest.php`.
- `Log` (`Log::spy()` / `Log::shouldReceive()`) when the behaviour *is* a log
  line, for example `label_mismatch_behavior = log`.
- Time, when a behaviour depends on it (idle cleanup, scrape time). Prefer
  passing the time in (`recordScrape($time)`) over freezing the clock.

Do not mock:

- `PrometheusService`, the builders, `RenderTextFormat`, `Sample`,
  `MetricFamilySamples`.
- promphp classes (`Counter`, `Gauge`, `Histogram`, `CollectorRegistry`).
  Use the real `InMemory` storage instead (`$this->freshPrometheus()`).
- Laravel config. Set it with `config([...])` in the test.

## Prefer a real adapter over a mock

For storage, a real `InMemory` adapter beats a mocked one. For Redis, a
mocked connection is fine for key parsing and error handling. When a bug
depends on real Redis behaviour, a test against a local `redis-server` is
the honest seam; mark it so it can be skipped when Redis is not available:

```php
if (! extension_loaded('redis')) {
    $this->markTestSkipped('phpredis is not installed');
}
```

## Designing for testability

- Accept dependencies, do not create them: `new PrometheusService($adapter)`
  takes the adapter, so tests pass `InMemory`.
- Pass values in instead of reading globals: `recordScrape(?int $time)`
  is easy to test; a hidden `time()` call is not.
