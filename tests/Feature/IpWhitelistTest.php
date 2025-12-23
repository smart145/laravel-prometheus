<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Feature;

use Smart145\Prometheus\Tests\TestCase;

class IpWhitelistTest extends TestCase
{
    public function test_empty_whitelist_allows_all(): void
    {
        config(['prometheus.allowed_ips' => []]);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
    }

    public function test_allowed_ip_can_access(): void
    {
        config(['prometheus.allowed_ips' => ['127.0.0.1']]);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
    }

    public function test_blocked_ip_returns_403(): void
    {
        config(['prometheus.allowed_ips' => ['10.0.0.1', '10.0.0.2']]);

        $response = $this->get('/prometheus');

        $response->assertStatus(403);
    }

    public function test_multiple_allowed_ips(): void
    {
        config(['prometheus.allowed_ips' => ['10.0.0.1', '127.0.0.1', '192.168.1.1']]);

        $response = $this->get('/prometheus');

        $response->assertStatus(200);
    }
}

