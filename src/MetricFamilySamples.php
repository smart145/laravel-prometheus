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

