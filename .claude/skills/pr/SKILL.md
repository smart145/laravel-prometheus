---
name: pr
description: "Use when writing a pull request body for this package."
---

Use this template for the PR body:

```markdown
## Summary

<one or two sentences, then the smallest visual that makes the change clear>

## Evidence

- **Before:** <failing test run or scrape output>
  **After:** <passing test run or scrape output>

## Merge Danger

**Door:** <one-way or two-way>

**Blast Radius:** <one word>

<what can break for users of the package>

## Changelog

<the lines added under [Unreleased] in CHANGELOG.md, or "none">
```

Skip preambles. Keep prose short. Use the vocabulary from `CLAUDE.md`.

## Summary

Pick the smallest view that makes the point:

- **Scrape output diff** for any change to what Prometheus reads:

  ```diff
   # TYPE test_events_total counter
  -test_events_total 1
  +test_events_total{type="signup"} 1 1703184523000
  ```

- **Call tree** for a control-flow change, for example a scrape:

  ```text
  MetricsController::__invoke
    PrometheusService::render
      evaluate gauge callbacks
      collect samples (memory | redis)
      RenderTextFormat::render
    wipe (auto_wipe | wipe_param)
    recordScrape
  ```

- **Config diff** for a new or changed option:

  ```diff
  +'label_mismatch_behavior' => env('PROMETHEUS_LABEL_MISMATCH_BEHAVIOR', 'throw'),
  ```

- **File tree** for a move or refactor across `src/`.

Use one, maybe two. Do not show all of them.

## Evidence

Show a before and after:

- The test that now passes (name and the `vendor/bin/phpunit --filter`
  output), and that it failed before the fix.
- For output changes, a real scrape:
  `curl -s localhost:8000/prometheus` from a test app, or the rendered
  string from the test. Redact hosts and IPs.
- The full suite result: `OK (N tests, M assertions)`.

## Merge danger

**One-way doors** in this package (users cannot simply roll back the data
or dashboards they built):

- Renamed or removed metric names or label names: dashboards and alerts
  break silently.
- A changed Redis key format (`PROMETHEUS_*`): existing samples become
  unreadable or orphaned.
- A wipe or cleanup change that can delete data users expect to keep.

**Two-way doors**: new opt-in config, new metric types, refactors with the
same output, docs.

**Blast radius** examples: every app that scrapes (output format), Redis
users only, users with a Laravel key prefix, users on one Laravel version,
endpoint security (`enabled`, `allowed_ips`).
