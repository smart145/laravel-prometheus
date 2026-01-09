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
use Prometheus\Summary;
use RuntimeException;
use Smart145\Prometheus\MetricFamilySamples;

/**
 * Redis storage adapter for Prometheus metrics using Laravel's Redis connection.
 *
 * This adapter bridges the promphp/prometheus_client_php library with Laravel's
 * Redis facade, allowing metrics to persist across requests.
 */
class LaravelRedisAdapter implements Adapter
{
    private const PREFIX = 'PROMETHEUS_';

    private ?Connection $connection = null;

    private ?string $laravelPrefix = null;

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
     * Get the Laravel Redis prefix.
     *
     * Laravel prefixes all Redis keys with a configured prefix. When we use
     * the KEYS command, the returned keys include this prefix. We need to
     * strip it before using those keys in subsequent commands.
     */
    protected function getLaravelPrefix(): string
    {
        if ($this->laravelPrefix === null) {
            $this->laravelPrefix = config('database.redis.options.prefix', '');
        }

        return $this->laravelPrefix;
    }

    /**
     * Strip the Laravel prefix from a key returned by KEYS command.
     */
    protected function stripLaravelPrefix(string $key): string
    {
        $prefix = $this->getLaravelPrefix();

        if ($prefix !== '' && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    /**
     * Collect all metrics from storage.
     *
     * @return MetricFamilySamples[]
     */
    public function collect(bool $sortMetrics = true): array
    {
        $metrics = [];

        // Collect all metric types
        foreach ([Gauge::TYPE, Counter::TYPE, Histogram::TYPE, Summary::TYPE] as $type) {
            $metrics = array_merge($metrics, $this->collectByType($type));
        }

        if ($sortMetrics) {
            usort($metrics, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
        }

        return $metrics;
    }

    /**
     * Collect metrics by type.
     *
     * @return MetricFamilySamples[]
     */
    private function collectByType(string $type): array
    {
        $rawKeys = $this->redis()->command('keys', [self::PREFIX . $type . ':*:meta']);

        $metrics = [];

        foreach ($rawKeys as $rawMetaKey) {
            // Strip Laravel prefix from keys returned by KEYS command
            $metaKey = $this->stripLaravelPrefix($rawMetaKey);

            $meta = $this->redis()->command('hGetAll', [$metaKey]);

            if (empty($meta)) {
                continue;
            }

            $baseKey = str_replace(':meta', '', $metaKey);
            $samples = [];
            $labelNames = json_decode($meta['labelNames'] ?? '[]', true);

            // Get all sample keys for this metric
            $rawSampleKeys = $this->redis()->command('keys', [$baseKey . ':*']);

            foreach ($rawSampleKeys as $rawSampleKey) {
                // Strip Laravel prefix from keys returned by KEYS command
                $sampleKey = $this->stripLaravelPrefix($rawSampleKey);

                if (str_ends_with($sampleKey, ':meta')) {
                    continue;
                }

                $value = $this->redis()->command('hGetAll', [$sampleKey]);

                if (empty($value)) {
                    continue;
                }

                $labelValues = json_decode($value['labelValues'] ?? '[]', true);
                $sampleValue = (float) ($value['value'] ?? 0);
                $timestamp = isset($value['timestamp']) ? (int) $value['timestamp'] : null;

                // Validate label count matches - skip corrupted samples
                if (count($labelNames) !== count($labelValues)) {
                    Log::warning('Prometheus: Skipping corrupted sample with label mismatch', [
                        'metric' => $meta['name'],
                        'expected_labels' => $labelNames,
                        'actual_values' => $labelValues,
                        'sample_key' => $sampleKey,
                    ]);

                    continue;
                }

                if ($type === Histogram::TYPE) {
                    $samples = array_merge($samples, $this->collectHistogramSamples($sampleKey, $labelValues, $meta, $timestamp));
                } else {
                    $samples[] = [
                        'name' => $meta['name'],
                        'labelNames' => [],  // Empty - labelNames come from parent MetricFamilySamples
                        'labelValues' => $labelValues,
                        'value' => $sampleValue,
                        'timestamp' => $timestamp,
                    ];
                }
            }

            if (! empty($samples)) {
                $metrics[] = new MetricFamilySamples([
                    'name' => $meta['name'],
                    'type' => $type,
                    'help' => $meta['help'] ?? '',
                    'labelNames' => $labelNames,
                    'samples' => $samples,
                ]);
            }
        }

        return $metrics;
    }

    /**
     * Collect histogram samples.
     *
     * Histogram samples include:
     * - Bucket samples with 'le' label (cumulative counts)
     * - Sum sample
     * - Count sample
     */
    private function collectHistogramSamples(string $sampleKey, array $labelValues, array $meta, ?int $timestamp = null): array
    {
        $data = $this->redis()->command('hGetAll', [$sampleKey]);
        $buckets = json_decode($meta['buckets'] ?? '[]', true) ?? [];

        $samples = [];
        $cumulativeCount = 0;

        // Bucket samples - add 'le' as additional label
        foreach ($buckets as $bucket) {
            $bucketKey = 'bucket_' . $bucket;
            $count = (int) ($data[$bucketKey] ?? 0);
            $cumulativeCount += $count;

            $samples[] = [
                'name' => $meta['name'] . '_bucket',
                'labelNames' => ['le'],  // Additional label for buckets
                'labelValues' => array_merge($labelValues, [(string) $bucket]),
                'value' => $cumulativeCount,
                'timestamp' => $timestamp,
            ];
        }

        // +Inf bucket
        $infCount = (int) ($data['bucket_inf'] ?? 0);
        $cumulativeCount += $infCount;
        $samples[] = [
            'name' => $meta['name'] . '_bucket',
            'labelNames' => ['le'],  // Additional label for buckets
            'labelValues' => array_merge($labelValues, ['+Inf']),
            'value' => $cumulativeCount,
            'timestamp' => $timestamp,
        ];

        // Sum - no additional labels
        $samples[] = [
            'name' => $meta['name'] . '_sum',
            'labelNames' => [],
            'labelValues' => $labelValues,
            'value' => (float) ($data['sum'] ?? 0),
            'timestamp' => $timestamp,
        ];

        // Count - no additional labels
        $samples[] = [
            'name' => $meta['name'] . '_count',
            'labelNames' => [],
            'labelValues' => $labelValues,
            'value' => $cumulativeCount,
            'timestamp' => $timestamp,
        ];

        return $samples;
    }

    /**
     * Update a histogram metric.
     */
    public function updateHistogram(array $data): void
    {
        $metaKey = $this->metaKey(Histogram::TYPE, $data);
        $sampleKey = $this->sampleKey(Histogram::TYPE, $data);

        // Store metadata
        $this->redis()->command('hMSet', [$metaKey, [
            'name' => $data['name'],
            'help' => $data['help'],
            'labelNames' => json_encode($data['labelNames']),
            'buckets' => json_encode($data['buckets']),
        ]]);

        // Find the bucket for this value
        $bucketToIncrement = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrement = $bucket;
                break;
            }
        }

        // Increment the appropriate bucket
        $bucketKey = $bucketToIncrement === '+Inf' ? 'bucket_inf' : 'bucket_' . $bucketToIncrement;
        $this->redis()->command('hIncrBy', [$sampleKey, $bucketKey, 1]);

        // Update sum
        $this->redis()->command('hIncrByFloat', [$sampleKey, 'sum', $data['value']]);

        // Store label values
        $this->redis()->command('hSet', [$sampleKey, 'labelValues', json_encode($data['labelValues'])]);

        // Store timestamp if provided
        if (isset($data['timestamp'])) {
            $this->redis()->command('hSet', [$sampleKey, 'timestamp', $data['timestamp']]);
        }
    }

    /**
     * Update a gauge metric.
     */
    public function updateGauge(array $data): void
    {
        $metaKey = $this->metaKey(Gauge::TYPE, $data);
        $sampleKey = $this->sampleKey(Gauge::TYPE, $data);

        // Store metadata
        $this->redis()->command('hMSet', [$metaKey, [
            'name' => $data['name'],
            'help' => $data['help'],
            'labelNames' => json_encode($data['labelNames']),
        ]]);

        // Store value
        $command = $data['command'] ?? Adapter::COMMAND_SET;

        if ($command === Adapter::COMMAND_SET) {
            $this->redis()->command('hSet', [$sampleKey, 'value', $data['value']]);
        } elseif ($command === Adapter::COMMAND_INCREMENT_FLOAT) {
            $this->redis()->command('hIncrByFloat', [$sampleKey, 'value', $data['value']]);
        } elseif ($command === Adapter::COMMAND_INCREMENT_INTEGER) {
            $this->redis()->command('hIncrBy', [$sampleKey, 'value', (int) $data['value']]);
        }

        // Store label values
        $this->redis()->command('hSet', [$sampleKey, 'labelValues', json_encode($data['labelValues'])]);

        // Store timestamp if provided
        if (isset($data['timestamp'])) {
            $this->redis()->command('hSet', [$sampleKey, 'timestamp', $data['timestamp']]);
        }
    }

    /**
     * Update a counter metric.
     */
    public function updateCounter(array $data): void
    {
        $metaKey = $this->metaKey(Counter::TYPE, $data);
        $sampleKey = $this->sampleKey(Counter::TYPE, $data);

        // Store metadata
        $this->redis()->command('hMSet', [$metaKey, [
            'name' => $data['name'],
            'help' => $data['help'],
            'labelNames' => json_encode($data['labelNames']),
        ]]);

        // Increment counter
        $command = $data['command'] ?? Adapter::COMMAND_INCREMENT_INTEGER;

        if ($command === Adapter::COMMAND_INCREMENT_FLOAT) {
            $this->redis()->command('hIncrByFloat', [$sampleKey, 'value', $data['value']]);
        } else {
            $this->redis()->command('hIncrBy', [$sampleKey, 'value', (int) $data['value']]);
        }

        // Store label values
        $this->redis()->command('hSet', [$sampleKey, 'labelValues', json_encode($data['labelValues'])]);

        // Store timestamp if provided
        if (isset($data['timestamp'])) {
            $this->redis()->command('hSet', [$sampleKey, 'timestamp', $data['timestamp']]);
        }
    }

    /**
     * Update a summary metric.
     */
    public function updateSummary(array $data): void
    {
        throw new RuntimeException('Summary metrics are not yet implemented');
    }

    /**
     * Wipe all metrics from storage.
     */
    public function wipeStorage(): void
    {
        $rawKeys = $this->redis()->command('keys', [self::PREFIX . '*']);

        if (! empty($rawKeys)) {
            // Strip Laravel prefix from keys returned by KEYS command
            $keys = array_map(fn ($key) => $this->stripLaravelPrefix($key), $rawKeys);
            $this->redis()->command('del', $keys);
        }
    }

    /**
     * Generate the meta key for a metric.
     */
    private function metaKey(string $type, array $data): string
    {
        return self::PREFIX . $type . ':' . $data['name'] . ':meta';
    }

    /**
     * Generate the sample key for a metric.
     */
    private function sampleKey(string $type, array $data): string
    {
        $labelHash = md5(json_encode($data['labelValues']));

        return self::PREFIX . $type . ':' . $data['name'] . ':' . $labelHash;
    }
}

