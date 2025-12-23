<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Feature;

use Smart145\Prometheus\Facades\Prometheus;
use Smart145\Prometheus\Tests\TestCase;

class WipeParameterTest extends TestCase
{
    public function test_wipe_clears_storage(): void
    {
        Prometheus::counter('wipe_test_counter', 'Counter to wipe')->inc();

        // First request with wipe=1 (default value)
        $response = $this->get('/prometheus?wipe=1');
        $response->assertStatus(200);
        $response->assertSee('test_wipe_test_counter');

        // Second request should not have the counter anymore
        $response = $this->get('/prometheus');
        $response->assertStatus(200);
        $response->assertDontSee('test_wipe_test_counter');
    }

    public function test_wrong_wipe_value_preserves_storage(): void
    {
        Prometheus::counter('preserve_counter', 'Counter to preserve')->inc();

        // Request with wrong wipe value (0 instead of 1)
        $response = $this->get('/prometheus?wipe=0');
        $response->assertStatus(200);
        $response->assertSee('test_preserve_counter');

        // Second request should still have the counter
        $response = $this->get('/prometheus');
        $response->assertStatus(200);
        $response->assertSee('test_preserve_counter');
    }

    public function test_metrics_returned_before_wipe(): void
    {
        Prometheus::counter('before_wipe', 'Visible before wipe')->incBy(5);

        // The counter should be visible in the response even with wipe=1
        $response = $this->get('/prometheus?wipe=1');
        $response->assertStatus(200);
        $response->assertSee('test_before_wipe 5');
    }

    public function test_wipe_without_value_does_not_wipe(): void
    {
        Prometheus::counter('no_wipe', 'Should not be wiped')->inc();

        // Request without wipe parameter
        $response = $this->get('/prometheus');
        $response->assertSee('test_no_wipe');

        // Request again should still have it
        $response = $this->get('/prometheus');
        $response->assertSee('test_no_wipe');
    }

    public function test_auto_wipe_clears_on_every_request(): void
    {
        config(['prometheus.auto_wipe' => true]);

        Prometheus::counter('auto_wipe_counter', 'Auto wiped')->inc();

        // First request - should see counter, then auto-wipe
        $response = $this->get('/prometheus');
        $response->assertStatus(200);
        $response->assertSee('test_auto_wipe_counter');

        // Second request - counter should be gone
        $response = $this->get('/prometheus');
        $response->assertDontSee('test_auto_wipe_counter');
    }

    public function test_auto_wipe_takes_precedence_over_param(): void
    {
        config(['prometheus.auto_wipe' => true]);

        Prometheus::counter('precedence_counter', 'Precedence test')->inc();

        // Even without wipe param, auto_wipe should clear
        $response = $this->get('/prometheus');
        $response->assertSee('test_precedence_counter');

        $response = $this->get('/prometheus');
        $response->assertDontSee('test_precedence_counter');
    }

    public function test_custom_wipe_param_name(): void
    {
        config(['prometheus.wipe_param.param' => 'clear']);
        config(['prometheus.wipe_param.value' => 'yes']);

        Prometheus::counter('custom_param_counter', 'Custom param')->inc();

        // Old param should not work
        $response = $this->get('/prometheus?wipe=1');
        $response->assertSee('test_custom_param_counter');

        $response = $this->get('/prometheus');
        $response->assertSee('test_custom_param_counter');

        // New param should work
        $response = $this->get('/prometheus?clear=yes');
        $response->assertSee('test_custom_param_counter');

        $response = $this->get('/prometheus');
        $response->assertDontSee('test_custom_param_counter');
    }

    public function test_custom_wipe_param_value(): void
    {
        config(['prometheus.wipe_param.param' => 'reset']);
        config(['prometheus.wipe_param.value' => 'now']);

        Prometheus::counter('custom_value_counter', 'Custom value')->inc();

        // Wrong value should not wipe
        $response = $this->get('/prometheus?reset=later');
        $response->assertSee('test_custom_value_counter');

        $response = $this->get('/prometheus');
        $response->assertSee('test_custom_value_counter');

        // Correct value should wipe
        $response = $this->get('/prometheus?reset=now');
        $response->assertSee('test_custom_value_counter');

        $response = $this->get('/prometheus');
        $response->assertDontSee('test_custom_value_counter');
    }

    public function test_wipe_param_disabled(): void
    {
        config(['prometheus.wipe_param.enabled' => false]);

        Prometheus::counter('disabled_wipe_counter', 'Wipe disabled')->inc();

        // Wipe param should be ignored
        $response = $this->get('/prometheus?wipe=1');
        $response->assertSee('test_disabled_wipe_counter');

        // Counter should still exist
        $response = $this->get('/prometheus');
        $response->assertSee('test_disabled_wipe_counter');
    }
}

