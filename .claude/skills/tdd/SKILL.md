---
name: tdd
description: Test-driven development for this package with PHPUnit and Orchestra Testbench. Use when building a feature or fixing a bug test-first, when the user mentions "red-green-refactor" or "TDD", or asks for tests for a metric, builder, storage adapter, the metrics endpoint or the cleanup command.
---

# Test-Driven Development

TDD is the red → green loop. This skill says what a good test is in this
package, where tests go, the anti-patterns, and the rules of the loop. Apply
every section on every cycle.

Read `CLAUDE.md` first. Use its vocabulary (metric, builder, labels, storage
adapter, scrape, wipe, gauge callback) in test names.

## What a good test is

A good test checks behaviour through a public seam and survives refactors.
In this package, the observable behaviour is almost always **the rendered
Prometheus text**: what a scraper would read. Assert on that, with literal
expected lines.

See [tests.md](tests.md) for PHP examples and [mocking.md](mocking.md) for
when to mock.

## Seams: where tests go

A **seam** is the public boundary you test at. The seams in this package:

| Seam | How to drive it | Catches | Misses |
| --- | --- | --- | --- |
| `PrometheusService` (via `$this->freshPrometheus()`) | call `counter()/gauge()/histogram()`, then `render()` | builder logic, label validation, rendering, gauge callbacks | routing, middleware, Redis |
| Metrics endpoint | `$this->get('/prometheus?...')` in `tests/Feature` | wipe rules, `enabled`, IP allowlist, content type, scrape time | Redis-only paths |
| Artisan command | `$this->artisan('prometheus:cleanup')` | idle cleanup, `--force` | real Redis keys |
| `RenderTextFormat` | `render([...MetricFamilySamples])` | text format edge cases (escaping, timestamps) | how samples are collected |
| `LaravelRedisAdapter` | adapter methods, Redis connection mocked today | key parsing, corrupted sample cleanup | real Redis behaviour |

**Test only at agreed seams.** Before the first test, write down the seams
you plan to test, with one line each on what they catch and miss, and
confirm them with the user.

**Storage is a real seam with two adapters.** `memory` and `redis` take
different code paths in `PrometheusService::render()`, `recordScrape()` and
`lastScrapeTime()`. A change in any of those needs a test for each adapter,
or an explicit note that the Redis path is not covered. The Redis adapter
tests mock the connection; if behaviour depends on real Redis semantics
(key prefixes, `KEYS` results, `hGetAll` shapes), say so and offer an
integration test against a local `redis-server`.

## Anti-patterns

- **Implementation-coupled**: mocks the package's own classes, asserts on
  private state, or checks storage directly instead of the rendered output.
  The tell: the test breaks on a refactor that keeps the output the same.
- **Tautological**: the expected value is built the same way the code builds
  it (for example, `sprintf` of the metric name with the same namespace
  logic). Expected values are literal strings like
  `'test_events_total 5'`.
- **Weak assertions**: `assertStringContainsString('1', $output)` passes
  for almost any output. Assert on the full sample line, including labels.
- **Horizontal slicing**: writing all tests first, then all code. Work in
  vertical slices: one test → one minimal implementation → repeat.

## Rules of the loop

- **Red before green.** Run the new test with
  `vendor/bin/phpunit --filter <test_name>` and see it fail for the right
  reason before writing code.
- **One slice at a time.** One seam, one test, one minimal change.
- **Green means the whole suite.** Finish each slice with
  `vendor/bin/phpunit`.
- **Refactoring is not part of the loop.** It belongs to review (the
  `code-review` skill).
- A new config option needs a test for its default and for each
  non-default value.
