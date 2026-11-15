<?php

declare(strict_types=1);

namespace Smart145\Prometheus;

/**
 * Extended MetricFamilySamples that uses our custom Sample class with timestamp support.
 */
class MetricFamilySamples
{
    private string $name;

    private string $type;

    private string $help;

    /** @var string[] */
    private array $labelNames;

    /** @var Sample[] */
    private array $samples = [];

    /**
     * @param  mixed[]  $data
     */
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->help = $data['help'];
        $this->labelNames = $data['labelNames'];

        if (isset($data['samples'])) {
            foreach ($data['samples'] as $sampleData) {
                $this->samples[] = new Sample($sampleData);
            }
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    /**
     * @return Sample[]
     */
    public function getSamples(): array
    {
        return $this->samples;
    }

    public function addSample(Sample $sample): void
    {
        $this->samples[] = $sample;
    }

    /**
     * Remove the sample of one series, if the family has it.
     *
     * @param  string[]  $labelNames  All label names of the series
     * @param  string[]  $labelValues
     */
    public function removeSample(string $name, array $labelNames, array $labelValues): void
    {
        $this->samples = array_values(array_filter(
            $this->samples,
            fn (Sample $sample): bool => $sample->getName() !== $name
                || array_merge($this->labelNames, $sample->getLabelNames()) !== $labelNames
                || array_map('strval', $sample->getLabelValues()) !== $labelValues
        ));
    }

    /**
     * @return string[]
     */
    public function getLabelNames(): array
    {
        return $this->labelNames;
    }

    public function hasLabelNames(): bool
    {
        return $this->labelNames !== [];
    }
}

