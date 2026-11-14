# Good and bad tests

## Good tests

Drive the public API and assert on the rendered text.

```php
public function test_counter_with_labels(): void
{
    $prometheus = $this->freshPrometheus();

    $prometheus->counter('http_requests_total', 'HTTP requests', ['method', 'status'])
        ->inc(['GET', '200']);

    $this->assertStringContainsString(
        'test_http_requests_total{method="GET",status="200"} 1',
        $prometheus->render(),
    );
}
```

Endpoint behaviour goes through HTTP, like a scraper:

```php
public function test_wipe_param_clears_metrics_after_scrape(): void
{
    Prometheus::counter('events_total', 'Events')->inc();

    $this->get('/prometheus?wipe=1')
        ->assertOk()
        ->assertSee('test_events_total 1', false);

    $this->get('/prometheus')
        ->assertOk()
        ->assertDontSee('test_events_total', false);
}
```

Characteristics:

- Uses the facade, the service, the route or the command only.
- Expected values are literal sample lines.
- Survives internal refactors.
- The name says what happens, not how.

## Bad tests

Coupled to internals:

```php
// BAD: mocks the package's own builder
public function test_counter_calls_inc_on_promphp_counter(): void
{
    $counter = Mockery::mock(\Prometheus\Counter::class);
    $counter->shouldReceive('inc')->once();
    (new CounterBuilder($counter, new InMemory()))->inc();
}
```

Bypasses the interface:

```php
// BAD: reads the adapter instead of the output
$prometheus->counter('events_total', 'Events')->inc();
$this->assertCount(1, $prometheus->getAdapter()->collect());

// GOOD: reads what a scraper reads
$this->assertStringContainsString('test_events_total 1', $prometheus->render());
```

Tautological:

```php
// BAD: rebuilds the expected name the way the code does
$expected = config('prometheus.namespace') . '_events_total 1';

// GOOD: a known literal
$expected = 'test_events_total 1';
```

Weak:

```php
// BAD: "1" appears in almost any output
$this->assertStringContainsString('1', $output);
```

Red flags:

- Mocking `CounterBuilder`, `GaugeBuilder`, `HistogramBuilder`,
  `PrometheusService` or `RenderTextFormat`.
- Asserting on call counts or order of the package's own methods.
- The test breaks on a refactor that keeps the output the same.
