# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Laravel 13 support
- `PrometheusService::renderAndWipe()` and `LaravelRedisAdapter::collectAndWipe()`: read and wipe in one Redis step
- `wipe_gauges` option (`PROMETHEUS_WIPE_GAUGES`, default `true`): set it to `false` so a scrape that wipes keeps the gauges (Redis storage)
- `gauge_ttl` option (`PROMETHEUS_GAUGE_TTL`, default `0` = off): remove a gauge sample that was not written for this many seconds (Redis storage)
- Storage tests that run against a real Redis server with the `phpredis` and `predis` clients

### Changed
- Redis storage writes each recording with one Lua script and reads all samples with one script call. A scrape no longer uses `KEYS`. The adapter now needs Redis scripting (`EVAL`) and does not support Redis Cluster
- Redis storage has new keys beside the existing ones: `PROMETHEUS_index`, `PROMETHEUS_schema` and `PROMETHEUS_<type>:<name>:index`. Existing key formats did not change. The first scrape after the upgrade adds the samples of version 1.0 to the indexes
- **Behaviour change:** a counter rejects a negative, NaN or infinite increment, and a histogram rejects a NaN or infinite observation. The rejection follows `label_mismatch_behavior` (default: `throw`)
- **Behaviour change:** a name that is stored as one metric type cannot be recorded as another type. The rejection follows `label_mismatch_behavior`
- **Behaviour change:** gauge callback values are not stored. A callback that fails, or returns no number, has no sample in that scrape
- **Behaviour change:** a recording without a timestamp removes the timestamp that an earlier `withTimestamp()` recording stored on the sample
- Label values are stored as text, so `[200]` and `['200']` are the same series
- Samples in a metric are rendered in a stable order
- Numbers are rendered with full precision (for example `1234567890123456`, not `1.2345678901235E+15`)
- The metrics endpoint renders a sample that cannot be rendered as a comment and continues

### Fixed
- A scrape during a write could read a half-written sample, delete it as a corrupted sample, and lose the recording
- A scrape that wipes lost the recordings that arrived between the read and the wipe
- A wipe during a write could leave a sample with value 0
- A counter that received float and integer increments lost the integer increments with the `phpredis` client
- Label values of different PHP types created duplicate series with the same labels
- A sample kept the timestamp of an old `withTimestamp()` recording after later recordings
- Changed label names put old samples under the wrong labels (same count) or deleted them (different count). A sample now keeps the label names that it was written with
- Changed histogram buckets hid old observations from `_count` and the buckets but kept them in `_sum`
- A second declaration of a histogram with other buckets used those buckets for `withTimestamp()` recordings only
- A gauge callback that failed kept its last value in Redis and in the output
- `NAN` and `INF` gauge values were stored as `0` in Redis
- A newline or a backslash in the help text produced an invalid `# HELP` line
- README showed `?wipe=true` as the default wipe parameter. The default is `?wipe=1`

## [1.0.0] - 2025-12-23

### Added
- Initial release
- Prometheus metrics endpoint for Laravel applications
- Support for Counter, Gauge, and Histogram metrics
- Redis and InMemory storage adapters
- IP whitelist middleware for metrics endpoint
- Timestamp support for metrics
- Gauge callbacks for dynamic metrics
- Wipe functionality for push-gateway style setups
- Auto-discovery via Laravel package auto-discovery

