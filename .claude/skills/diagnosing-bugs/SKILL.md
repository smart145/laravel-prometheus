---
name: diagnosing-bugs
description: Diagnosis loop for hard bugs in this package. Use when the user says "diagnose" or "debug this", or reports wrong or missing metrics, a broken /prometheus endpoint, label mismatch errors, corrupted Redis samples, wipe or cleanup not working, or a slow scrape.
---

# Diagnosing bugs

A discipline for hard bugs. Skip a phase only with a stated reason.

Read `CLAUDE.md` first for the vocabulary and the module map.

## Redact

Never show secrets: Redis passwords, hostnames from a user's `.env`, IPs
from `PROMETHEUS_ALLOWED_IPS` in production. Write `<REDACTED>` instead.
Quote only the lines of captured output that carry the signal.

## Phase 1: Build a feedback loop

**This is the skill.** With a fast pass/fail signal that goes red on *this*
bug, the rest is mechanical. Without one, reading code will not save you.

Ways to build one, in this order:

1. **Failing PHPUnit test** at the seam that reaches the bug (see the
   `tdd` skill for the seam table). Run it with
   `vendor/bin/phpunit --filter <test_name>`.
2. **Endpoint test** in `tests/Feature` that scrapes `/prometheus` with the
   user's config (`config([...])`) and asserts on the body or status.
3. **Throwaway script** for output-format bugs: build samples, call
   `(new RenderTextFormat())->render($samples)`, and diff against the
   expected Prometheus text. Keep it in a scratch directory, not in `src/`.
4. **Real Redis loop** when the bug only shows with `storage = redis`
   (key prefixes, Lua scripts, the index, corrupted samples, scrape time).
   Start `redis-server --port 6390 --daemonize yes`, add a test to
   `tests/RedisStorageTestCase.php`, and run it with
   `REDIS_PORT=6390 vendor/bin/phpunit --filter StorageTest`. Seed the exact
   keys the user reported. `LaravelRedisAdapterTest` mocks Redis, so it
   cannot catch this class of bug.
5. **Version matrix loop** when the bug appears on one Laravel or PHP
   version only: reproduce the CI step locally,
   `composer require "illuminate/support:^12.0" "illuminate/routing:^12.0" "illuminate/http:^12.0" --no-update && composer update`,
   then run the failing test.
6. **`git bisect run vendor/bin/phpunit --filter <test>`** when the bug
   appeared between two known commits or tags.
7. **Ask the user** for a redacted scrape output, the config values and
   the Redis keys (`redis-cli --scan --pattern 'PROMETHEUS_*'`) when
   nothing above reproduces it.

Tighten the loop: narrow to one test, assert on the exact sample line or
exception message, and remove anything random or time-based (pass times in,
for example `recordScrape($time)`).

### Done when

You can name **one command** you have already run that:

- [ ] drives the real bug path and asserts the **user's exact symptom**;
- [ ] gives the same result every run;
- [ ] runs in seconds;
- [ ] runs without a human.

If you are reading code to build a theory before this command exists,
stop. No red command, no Phase 2.

## Phase 2: Reproduce and minimise

Run the loop and watch it go red with the symptom the user described, not
a nearby one. Then cut the repro down (fewer metrics, fewer labels, fewer
config overrides) one step at a time, re-running after each cut, until every
remaining part is needed for the failure.

## Phase 3: Hypothesise

Write **3–5 ranked hypotheses**, each with a prediction:

> If <X> is the cause, then <changing Y> makes the bug go away.

Common suspects in this package:

- The two storage adapters take different paths in `render()`,
  `recordScrape()` and `lastScrapeTime()`.
- A sample is in Redis but not in the index (`PROMETHEUS_index`,
  `PROMETHEUS_<type>:<name>:index`), so a scrape does not read it.
- The Lua scripts build key names from Laravel's Redis key prefix
  (`database.redis.options.prefix`).
- `env()` turns `"true"`/`"false"` into booleans (see the `wipe_param`
  comment in `config/prometheus.php`).
- `PROMETHEUS_ALLOWED_IPS` is a string, not an array.
- A metric was registered twice with different label names, or label
  values do not match label names.
- A gauge callback throws and is only reported.

Show the list to the user before testing. Do not block if they are away.

## Phase 4: Instrument

One probe per prediction. Change one variable at a time. Tag every debug
line, for example `logger('[DEBUG-a4f2] ...')`, so cleanup is one `grep`.
For a slow scrape, measure first (time `render()` with N metrics, count
Redis commands), then change code.

## Phase 5: Fix and regression test

Write the regression test **before** the fix, at a seam that reproduces the
real bug pattern. If the bug needs real Redis and the only test available
mocks Redis, say that a mocked test gives false confidence, and add a real
Redis test or document the gap.

1. Turn the minimised repro into a failing test.
2. Watch it fail.
3. Fix.
4. Watch it pass, then run `vendor/bin/phpunit`.
5. Re-run the Phase 1 loop on the original scenario.

## Phase 6: Cleanup

- [ ] Original repro no longer reproduces.
- [ ] Regression test passes, or the missing seam is documented.
- [ ] `grep -rn "DEBUG-" src tests` returns nothing.
- [ ] Scratch scripts and any `redis-server` you started are gone.
- [ ] `CHANGELOG.md` has a `### Fixed` line under `[Unreleased]`.
- [ ] The commit or PR message states the cause that turned out correct.
