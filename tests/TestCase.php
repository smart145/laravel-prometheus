<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Smart145\Prometheus\PrometheusService;
use Smart145\Prometheus\PrometheusServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use memory storage for tests
        config(['prometheus.storage' => 'memory']);
        config(['prometheus.enabled' => true]);
        config(['prometheus.namespace' => 'test']);
        config(['prometheus.allowed_ips' => []]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PrometheusServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Prometheus' => \Smart145\Prometheus\Facades\Prometheus::class,
        ];
    }

    /**
     * Get a fresh PrometheusService instance with memory storage.
     */
    protected function freshPrometheus(): PrometheusService
    {
        return new PrometheusService(new \Prometheus\Storage\InMemory());
    }
}

