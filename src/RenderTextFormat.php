<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

use RuntimeException;
use Throwable;

/**
 * Extended text format renderer that supports timestamps.
 *
 * Prometheus text format supports optional timestamps after the value:
 * metric_name{labels} value [timestamp]
 *
 * The timestamp is in milliseconds since epoch.
 */
class RenderTextFormat
{
    public const MIME_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @param  MetricFamilySamples[]  $metrics
     * @param  bool  $silent  If true, render value errors as comments instead of throwing them.
     */
    public function render(array $metrics, bool $silent = false): string
    {
        usort($metrics, fn (MetricFamilySamples $a, MetricFamilySamples $b): int => strcmp($a->getName(), $b->getName()));

        $lines = [];

        foreach ($metrics as $metric) {
            $lines[] = '# HELP ' . $metric->getName() . ' ' . $metric->getHelp();
            $lines[] = '# TYPE ' . $metric->getName() . ' ' . $metric->getType();

            foreach ($metric->getSamples() as $sample) {
                try {
                    $lines[] = $this->renderSample($metric, $sample);
                } catch (Throwable $e) {
                    if (! $silent) {
                        throw $e;
                    }

                    $lines[] = '# Error: ' . $e->getMessage();
                    $lines[] = '#   Labels: ' . json_encode(array_merge($metric->getLabelNames(), $sample->getLabelNames()));
                    $lines[] = '#   Values: ' . json_encode($sample->getLabelValues());
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function renderSample(MetricFamilySamples $metric, Sample $sample): string
    {
        $labelNames = $metric->getLabelNames();

        if ($metric->hasLabelNames() || $sample->hasLabelNames()) {
            $escapedLabels = $this->escapeAllLabels($metric, $labelNames, $sample);
            $output = $sample->getName() . '{' . implode(',', $escapedLabels) . '} ' . $sample->getValue();
        } else {
            $output = $sample->getName() . ' ' . $sample->getValue();
        }

        // Append timestamp if present (in milliseconds)
        if ($sample->hasTimestamp()) {
            $output .= ' ' . $sample->getTimestamp();
        }

        return $output;
    }

    private function escapeLabelValue(string $v): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $v);
    }

    /**
     * @param  string[]  $labelNames
     * @return string[]
     */
    private function escapeAllLabels(MetricFamilySamples $metric, array $labelNames, Sample $sample): array
    {
        $escapedLabels = [];

        $allLabelNames = array_merge($labelNames, $sample->getLabelNames());
        $labelValues = $sample->getLabelValues();

        if (count($allLabelNames) !== count($labelValues)) {
            throw new RuntimeException(sprintf(
                'Label mismatch for metric "%s": expected %d labels (%s) but got %d values (%s). ' .
                'This usually means the metric was created with different labels than when it was last stored. ' .
                'Try wiping Prometheus data with ?wipe=true.',
                $metric->getName(),
                count($allLabelNames),
                implode(', ', $allLabelNames),
                count($labelValues),
                implode(', ', array_map(fn ($v) => (string) $v, $labelValues))
            ));
        }

        $labels = array_combine($allLabelNames, $labelValues);

        foreach ($labels as $labelName => $labelValue) {
            $escapedLabels[] = $labelName . '="' . $this->escapeLabelValue((string) $labelValue) . '"';
        }

        return $escapedLabels;
    }
}

