<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Adapters;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Storage\Adapter;
use RuntimeException;
use Smart145\Prometheus\MetricFamilySamples;
use Smart145\Prometheus\RejectsInvalidRecordings;
use Smart145\Prometheus\Support\Number;

/**
 * Redis storage adapter for Prometheus metrics using Laravel's Redis connection.
 *
 * This adapter bridges the promphp/prometheus_client_php library with Laravel's
 * Redis facade, allowing metrics to persist across requests.
 *
 * Keys (after Laravel's Redis prefix):
 *   PROMETHEUS_<type>:<name>:meta    hash: name, help, labelNames, buckets
 *   PROMETHEUS_<type>:<name>:<hash>  hash: one sample (value or buckets, labels, timestamp)
 *   PROMETHEUS_<type>:<name>:index   set: the <hash> of every sample of the metric
 *   PROMETHEUS_index                 hash: metric name => type
 *   PROMETHEUS_schema                marker: samples of version 1.0 were added to the indexes
 *   PROMETHEUS_last_scrape           time of the most recent scrape
 *
 * Every write, and every read of all samples, is one Lua script. Redis runs a
 * script as one step, so a scrape never sees a half-written sample and a
 * wiping scrape cannot lose a write that arrives while it runs.
 *
 * The scripts read keys that they build from the index, so this adapter does
 * not support Redis Cluster.
 */
class LaravelRedisAdapter implements Adapter
{
    use RejectsInvalidRecordings;

    private const PREFIX = 'PROMETHEUS_';

    private const INDEX_KEY = self::PREFIX . 'index';

    private const SCHEMA_KEY = self::PREFIX . 'schema';

    /**
     * Store one recording.
     *
     * KEYS: index, meta, sample index, sample.
     * ARGV: type, name, help, labelNames, labelValues, sample hash, command
     *       (set|inc|observe), value, timestamp, buckets, bucket field.
     * Returns "OK", or the stored type if the name belongs to another type.
     */
    private const WRITE_SCRIPT = <<<'LUA'
local existing = redis.call('hget', KEYS[1], ARGV[2])
if existing and existing ~= ARGV[1] then
    return existing
end
if not existing then
    redis.call('hset', KEYS[1], ARGV[2], ARGV[1])
end
redis.call('hmset', KEYS[2], 'name', ARGV[2], 'help', ARGV[3], 'labelNames', ARGV[4])
if ARGV[10] ~= '' then
    redis.call('hset', KEYS[2], 'buckets', ARGV[10])
end
redis.call('sadd', KEYS[3], ARGV[6])
redis.call('hmset', KEYS[4], 'labelNames', ARGV[4], 'labelValues', ARGV[5])
if ARGV[7] == 'set' then
    redis.call('hset', KEYS[4], 'value', ARGV[8])
elseif ARGV[7] == 'inc' then
    redis.pcall('hincrbyfloat', KEYS[4], 'value', ARGV[8])
else
    redis.call('hincrby', KEYS[4], ARGV[11], 1)
    redis.call('hincrbyfloat', KEYS[4], 'sum', ARGV[8])
end
if ARGV[9] ~= '' then
    redis.call('hset', KEYS[4], 'timestamp', ARGV[9])
else
    redis.call('hdel', KEYS[4], 'timestamp')
end
return 'OK'
LUA;

    /**
     * Read every sample and, if asked, delete them in the same step.
     *
     * KEYS: index, schema marker.
     * ARGV: index key without Laravel's prefix, key prefix, wipe (1|0).
     * Returns a list of {type, name, meta, {{sample hash, sample}, ...}}.
     *
     * The first run after an upgrade from version 1.0 finds the samples that
     * have no index entry (one KEYS scan) and adds them to the indexes.
     */
    private const COLLECT_SCRIPT = <<<'LUA'
local root = string.sub(KEYS[1], 1, #KEYS[1] - #ARGV[1]) .. ARGV[2]
local wipe = ARGV[3] == '1'

if redis.call('exists', KEYS[2]) == 0 then
    local pattern = string.gsub(root, '[%*%?%[%]\\]', '\\%0')
    local keys = redis.call('keys', pattern .. '*')
    local types = {counter = true, gauge = true, histogram = true}
    for _, key in ipairs(keys) do
        local metricType, name = string.match(string.sub(key, #root + 1), '^(%a+):(.+):meta$')
        if metricType and types[metricType] then
            redis.call('hsetnx', KEYS[1], name, metricType)
        end
    end
    for _, key in ipairs(keys) do
        local metricType, name, hash = string.match(string.sub(key, #root + 1), '^(%a+):(.+):([^:]+)$')
        if metricType and types[metricType] and hash ~= 'meta' and hash ~= 'index'
            and redis.call('hget', KEYS[1], name) == metricType then
            redis.call('sadd', root .. metricType .. ':' .. name .. ':index', hash)
        end
    end
    redis.call('set', KEYS[2], '2')
end

local metrics = {}
local index = redis.call('hgetall', KEYS[1])
for i = 1, #index, 2 do
    local name, metricType = index[i], index[i + 1]
    local base = root .. metricType .. ':' .. name .. ':'
    local samples = {}
    for _, hash in ipairs(redis.call('smembers', base .. 'index')) do
        local sample = redis.call('hgetall', base .. hash)
        if #sample > 0 then
            samples[#samples + 1] = {hash, sample}
        end
        if wipe then
            redis.call('del', base .. hash)
        end
    end
    metrics[#metrics + 1] = {metricType, name, redis.call('hgetall', base .. 'meta'), samples}
    if wipe then
        redis.call('del', base .. 'meta', base .. 'index')
    end
end
if wipe then
    redis.call('del', KEYS[1])
end
return metrics
LUA;

    /**
     * Delete every key of this adapter, with or without an index entry.
     *
     * KEYS: index, schema marker. ARGV: index key without Laravel's prefix, key prefix.
     */
    private const WIPE_SCRIPT = <<<'LUA'
local root = string.sub(KEYS[1], 1, #KEYS[1] - #ARGV[1]) .. ARGV[2]
local pattern = string.gsub(root, '[%*%?%[%]\\]', '\\%0')
for _, key in ipairs(redis.call('keys', pattern .. '*')) do
    redis.call('del', key)
end
redis.call('set', KEYS[2], '2')
return 'OK'
LUA;

    private ?Connection $connection = null;

    public function __construct(
        private readonly ?string $connectionName = null,
    ) {}

    /**
     * Get the Redis connection.
     */
    protected function redis(): Connection
    {
        if ($this->connection === null) {
            $this->connection = Redis::connection($this->connectionName);
        }

        return $this->connection;
    }

    /**
     * Collect all metrics from storage.
     *
     * @return MetricFamilySamples[]
     */
    public function collect(bool $sortMetrics = true): array
    {
        return $this->collectFromRedis($sortMetrics, false);
    }

    /**
     * Collect all metrics and wipe them in the same Redis step.
     *
     * A recording that arrives during the scrape is either in the returned
     * samples or stays in storage for the next scrape. It is never lost.
     *
     * @return MetricFamilySamples[]
     */
    public function collectAndWipe(bool $sortMetrics = true): array
    {
        return $this->collectFromRedis($sortMetrics, true);
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectFromRedis(bool $sortMetrics, bool $wipe): array
    {
        $result = $this->redis()->eval(
            self::COLLECT_SCRIPT,
            2,
            self::INDEX_KEY,
            self::SCHEMA_KEY,
            self::INDEX_KEY,
            self::PREFIX,
            $wipe ? '1' : '0'
        );

        if (! is_array($result)) {
            throw new RuntimeException('Prometheus: the Redis script that collects the metrics failed');
        }

        $metrics = [];

        foreach ($result as [$type, $name, $rawMeta, $rawSamples]) {
            $meta = $this->toAssociative($rawMeta);
            $samples = [];

            foreach ($rawSamples as [$hash, $rawSample]) {
                $samples = array_merge($samples, $this->buildSamples(
                    (string) $type,
                    (string) $name,
                    $meta,
                    (string) $hash,
                    $this->toAssociative($rawSample),
                    $wipe
                ));
            }

            if ($samples === []) {
                continue;
            }

            usort($samples, fn (array $a, array $b): int => [$a['sortKey'], $a['order']] <=> [$b['sortKey'], $b['order']]);

            $metrics[] = new MetricFamilySamples([
                'name' => (string) $name,
                'type' => (string) $type,
                'help' => $meta['help'] ?? '',
                // Each sample carries its own label names, see buildSamples().
                'labelNames' => [],
                'samples' => $samples,
            ]);
        }

        if ($sortMetrics) {
            usort($metrics, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
        }

        return $metrics;
    }

    /**
     * Build the rendered samples of one stored sample.
     *
     * A sample uses the label names that it was written with. Samples of
     * version 1.0 have none and use the label names of the metric. So a
     * metric whose label names changed keeps correct labels on old samples.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSamples(string $type, string $name, array $meta, string $hash, array $sample, bool $wiped): array
    {
        $labelNames = json_decode($sample['labelNames'] ?? $meta['labelNames'] ?? '[]', true);
        $labelValues = json_decode($sample['labelValues'] ?? '[]', true);
        $timestamp = isset($sample['timestamp']) ? (int) $sample['timestamp'] : null;

        // Skip (and optionally delete) corrupted samples
        if (! is_array($labelNames) || ! is_array($labelValues) || count($labelNames) !== count($labelValues)) {
            $clean = (bool) config('prometheus.auto_clean_corrupted_samples', true);
            $base = self::PREFIX . $type . ':' . $name . ':';

            Log::warning('Prometheus: Corrupted sample with label mismatch', [
                'metric' => $name,
                'expected_labels' => $labelNames,
                'actual_values' => $labelValues,
                'sample_key' => $base . $hash,
                'action' => $clean || $wiped ? 'deleted' : 'skipped',
            ]);

            if ($clean && ! $wiped) {
                $this->redis()->command('del', [$base . $hash]);
                $this->redis()->command('srem', [$base . 'index', $hash]);
            }

            return [];
        }

        $labelValues = array_map('strval', $labelValues);

        if ($type === Histogram::TYPE) {
            return $this->buildHistogramSamples($name, $meta, $sample, $labelNames, $labelValues, $timestamp);
        }

        return [[
            'name' => $name,
            'labelNames' => $labelNames,
            'labelValues' => $labelValues,
            'value' => Number::parse($sample['value'] ?? 0),
            'timestamp' => $timestamp,
            'sortKey' => $labelValues,
            'order' => 0,
        ]];
    }

    /**
     * Build histogram samples.
     *
     * Histogram samples include:
     * - Bucket samples with 'le' label (cumulative counts)
     * - Sum sample
     * - Count sample
     *
     * The buckets are those of the metric plus every bucket that the sample
     * has a count for. So observations stay in `_count` and in the buckets
     * after the buckets of the metric change.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildHistogramSamples(string $name, array $meta, array $sample, array $labelNames, array $labelValues, ?int $timestamp): array
    {
        $counts = [];

        foreach (json_decode($meta['buckets'] ?? '[]', true) ?: [] as $bucket) {
            $counts[Number::format((float) $bucket)] = 0;
        }

        foreach ($sample as $field => $count) {
            if (str_starts_with((string) $field, 'bucket_') && $field !== 'bucket_inf') {
                $bound = Number::format((float) substr((string) $field, 7));
                $counts[$bound] = ($counts[$bound] ?? 0) + (int) $count;
            }
        }

        uksort($counts, fn ($a, $b): int => (float) $a <=> (float) $b);
        $counts['+Inf'] = (int) ($sample['bucket_inf'] ?? 0);

        $samples = [];
        $cumulativeCount = 0;
        $order = 0;

        // Bucket samples - add 'le' as additional label
        foreach ($counts as $bound => $count) {
            $cumulativeCount += $count;

            $samples[] = [
                'name' => $name . '_bucket',
                'labelNames' => array_merge($labelNames, ['le']),
                'labelValues' => array_merge($labelValues, [(string) $bound]),
                'value' => $cumulativeCount,
                'timestamp' => $timestamp,
                'order' => $order++,
            ];
        }

        $samples[] = [
            'name' => $name . '_sum',
            'labelNames' => $labelNames,
            'labelValues' => $labelValues,
            'value' => Number::parse($sample['sum'] ?? 0),
            'timestamp' => $timestamp,
            'order' => $order++,
        ];

        $samples[] = [
            'name' => $name . '_count',
            'labelNames' => $labelNames,
            'labelValues' => $labelValues,
            'value' => $cumulativeCount,
            'timestamp' => $timestamp,
            'order' => $order,
        ];

        // Keep the samples of one series together when the family is sorted.
        return array_map(fn (array $s): array => $s + ['sortKey' => $labelValues], $samples);
    }

    /**
     * Update a histogram metric.
     */
    public function updateHistogram(array $data): void
    {
        // Find the bucket for this value
        $bucketField = 'bucket_inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketField = 'bucket_' . $bucket;
                break;
            }
        }

        $this->write(Histogram::TYPE, $data, 'observe', (string) json_encode($data['buckets']), $bucketField);
    }

    /**
     * Update a gauge metric.
     */
    public function updateGauge(array $data): void
    {
        $command = ($data['command'] ?? Adapter::COMMAND_SET) === Adapter::COMMAND_SET ? 'set' : 'inc';

        $this->write(Gauge::TYPE, $data, $command);
    }

    /**
     * Update a counter metric.
     */
    public function updateCounter(array $data): void
    {
        $this->write(Counter::TYPE, $data, 'inc');
    }

    /**
     * Update a summary metric.
     */
    public function updateSummary(array $data): void
    {
        throw new RuntimeException('Summary metrics are not yet implemented');
    }

    /**
     * Store one recording in one Redis step.
     */
    private function write(string $type, array $data, string $command, string $buckets = '', string $bucketField = ''): void
    {
        $name = (string) $data['name'];
        $value = $data['value'];
        $value = is_int($value) ? $value : (float) $value;

        if ($command !== 'set' && is_float($value) && ! is_finite($value)) {
            $this->rejectRecording(sprintf('Metric "%s" cannot record a NaN or infinite value', $name));

            return;
        }

        $labelNames = (string) json_encode(array_values($data['labelNames']));
        $labelValues = array_map(
            fn (mixed $v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v),
            array_values($data['labelValues'])
        );
        $hash = md5((string) json_encode($labelValues));
        $base = self::PREFIX . $type . ':' . $name . ':';

        $result = $this->redis()->eval(
            self::WRITE_SCRIPT,
            4,
            self::INDEX_KEY,
            $base . 'meta',
            $base . 'index',
            $base . $hash,
            $type,
            $name,
            (string) $data['help'],
            $labelNames,
            (string) json_encode($labelValues),
            $hash,
            $command,
            Number::format($value),
            isset($data['timestamp']) ? (string) (int) $data['timestamp'] : '',
            $buckets,
            $bucketField
        );

        $result = is_object($result) && method_exists($result, '__toString') ? (string) $result : $result;

        if ($result === 'OK') {
            return;
        }

        if (is_string($result)) {
            $this->rejectRecording(sprintf(
                'Metric "%s" is already stored as a %s and cannot also be a %s',
                $name,
                $result,
                $type
            ));

            return;
        }

        throw new RuntimeException(sprintf('Prometheus: the Redis script that stores metric "%s" failed', $name));
    }

    /**
     * Wipe all metrics from storage.
     *
     * This also removes keys that have no index entry, for example samples
     * that an application instance with version 1.0 wrote during a deploy.
     * It scans the Redis database once, so a scrape uses collectAndWipe().
     */
    public function wipeStorage(): void
    {
        $this->redis()->eval(self::WIPE_SCRIPT, 2, self::INDEX_KEY, self::SCHEMA_KEY, self::INDEX_KEY, self::PREFIX);
    }

    /**
     * Record the time of the most recent metrics scrape.
     *
     * This is the "consumed" signal used by the cleanup command to detect
     * when metrics have gone unscraped for too long.
     */
    public function recordScrape(?int $time = null): void
    {
        $this->redis()->command('set', [$this->lastScrapeKey(), $time ?? time()]);
    }

    /**
     * Get the timestamp of the most recent metrics scrape, or null if unknown.
     */
    public function getLastScrapeTime(): ?int
    {
        $value = $this->redis()->command('get', [$this->lastScrapeKey()]);

        return $value === null || $value === false ? null : (int) $value;
    }

    /**
     * Generate the key used to store the last scrape timestamp.
     */
    private function lastScrapeKey(): string
    {
        return self::PREFIX . 'last_scrape';
    }

    /**
     * Turn a flat Redis reply (field, value, field, value) into an array.
     *
     * @return array<string, string>
     */
    private function toAssociative(mixed $flat): array
    {
        $result = [];
        $flat = is_array($flat) ? array_values($flat) : [];

        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $result[(string) $flat[$i]] = (string) $flat[$i + 1];
        }

        return $result;
    }
}
