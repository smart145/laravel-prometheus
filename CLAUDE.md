# laravel-prometheus

Laravel package (`smart145/laravel-prometheus`) that exposes Prometheus metrics
through a facade and an HTTP endpoint. Built on `promphp/prometheus_client_php`.

## Commands

- Install: `composer install`
- All tests: `vendor/bin/phpunit`
- One test: `vendor/bin/phpunit --filter test_counter_with_labels`
- One suite: `vendor/bin/phpunit --testsuite Unit` (or `Feature`)

CI (`.github/workflows/ci.yml`) runs PHP 8.2–8.4 against Laravel 11, 12 and 13
(no PHP 8.2 + Laravel 13). Code must work on the whole matrix.

## Vocabulary

Use these words in code, tests, commit messages and PRs.

- **Metric**: a counter, gauge or histogram, named `<namespace>_<name>`.
  The namespace comes from `prometheus.namespace` (`test` in the test suite).
- **Builder**: the object `Prometheus::counter()/gauge()/histogram()` returns
  (`src/MetricBuilders`). It records values and validates labels.
- **Labels**: label *names* are fixed when the metric is created; label
  *values* are passed on each record. A count mismatch follows
  `prometheus.label_mismatch_behavior` (`throw`, `log`, `ignore`).
- **Storage adapter**: where samples live. `memory` (promphp `InMemory`) or
  `redis` (`LaravelRedisAdapter`). The two adapters take different paths in
  `PrometheusService::render()`, `recordScrape()` and `lastScrapeTime()`.
- **Scrape**: a `GET` on the metrics route (`prometheus.route`, default
  `/prometheus`). It renders, maybe wipes, then records the scrape time.
- **Wipe**: clear all stored samples. Triggered by `auto_wipe` or by the
  wipe query parameter (`wipe_param`).
- **Gauge callback**: a gauge evaluated at render time
  (`registerGaugeCallback`). A failing callback is reported, not thrown.
- **Corrupted sample**: a Redis sample whose label value count does not match
  its metric's label names. Cleaned by `auto_clean_corrupted_samples`.
- **Idle cleanup**: `php artisan prometheus:cleanup` (`CleanupMetricsCommand`)
  wipes metrics that nobody scraped for too long, using the last scrape time.
  Configured under `prometheus.cleanup`.

## Coding standards

- Every PHP file starts with `declare(strict_types=1);`.
- Namespace `Smart145\Prometheus\` maps to `src/`; tests to
  `Smart145\Prometheus\Tests\`.
- Every config key reads an `env()` value with a safe default and has a
  comment block in `config/prometheus.php`. Read config with a default:
  `config('prometheus.x', <default>)`.
- A new user-facing option needs: the config key, the env var in
  `README.md`, and a line in `CHANGELOG.md` under `[Unreleased]`
  (Keep a Changelog format).
- The metrics endpoint must never fail a scrape because of one bad metric:
  report or log, then keep rendering.
- Do not add a hard dependency beyond `illuminate/*` and promphp.
- Do not change existing metric names, label names, Redis key formats
  (`PROMETHEUS_*`) or env var names without calling it out as a breaking
  change: users' dashboards, alerts and stored data depend on them.

## Tests

- Extend `Smart145\Prometheus\Tests\TestCase` (Orchestra Testbench).
  It sets `memory` storage, namespace `test` and no IP allowlist.
- Use `$this->freshPrometheus()` for a service with clean `InMemory` storage.
- Assert on the rendered text (`$prometheus->render()` or the HTTP
  response body), for example
  `assertStringContainsString('test_http_requests_total{method="GET",status="200"} 1', $output)`.
- Test names are `test_<behaviour_in_snake_case>`.
- `Unit/` tests the service, builders, renderer and adapter.
  `Feature/` tests the HTTP endpoint, middleware and artisan command.

## Skills

Project skills live in `.claude/skills/` (adapted from
[mattpocock/skills](https://github.com/mattpocock/skills)): `tdd`,
`diagnosing-bugs`, `code-review`, `pr`. See `.claude/skills/README.md`.
