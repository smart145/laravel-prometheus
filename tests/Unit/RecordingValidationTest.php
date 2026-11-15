<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Smart145\Prometheus\Support\Number;
use Smart145\Prometheus\Tests\TestCase;

class RecordingValidationTest extends TestCase
{
    public function test_label_values_of_different_php_types_share_one_series(): void
    {
        $prometheus = $this->freshPrometheus();
        $counter = $prometheus->counter('http_total', 'Requests', ['status']);

        $counter->inc(['200']);
        $counter->inc([200]);
        $counter->inc(['status' => '200']);

        $output = $prometheus->render();

        $this->assertSame(1, substr_count($output, 'test_http_total{status="200"}'));
        $this->assertStringContainsString('test_http_total{status="200"} 3', $output);
    }

    public function test_null_label_value_is_an_empty_string(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->counter('models_total', 'Models', ['uri'])->inc([null]);

        $this->assertStringContainsString('test_models_total{uri=""} 1', $prometheus->render());
    }

    public function test_counter_throws_on_negative_increment(): void
    {
        $prometheus = $this->freshPrometheus();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Counter "test_orders_total" cannot be incremented by a negative value');

        $prometheus->counter('orders_total', 'Orders')->incBy(-1);
    }

    public function test_counter_logs_and_skips_negative_increment_when_behavior_is_log(): void
    {
        config(['prometheus.label_mismatch_behavior' => 'log']);
        Log::spy();

        $prometheus = $this->freshPrometheus();
        $counter = $prometheus->counter('orders_total', 'Orders');
        $counter->incBy(5);
        $counter->incBy(-2);

        $this->assertStringContainsString("test_orders_total 5\n", $prometheus->render());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_counter_and_histogram_reject_non_finite_values(): void
    {
        config(['prometheus.label_mismatch_behavior' => 'ignore']);

        $prometheus = $this->freshPrometheus();
        $prometheus->counter('bytes_total', 'Bytes')->incBy(2);
        $prometheus->counter('bytes_total', 'Bytes')->incBy(INF);
        $prometheus->histogram('size', 'Size', [], [1])->observe(0.5);
        $prometheus->histogram('size', 'Size', [], [1])->observe(NAN);

        $output = $prometheus->render();

        $this->assertStringContainsString("test_bytes_total 2\n", $output);
        $this->assertStringContainsString("test_size_count 1\n", $output);
        $this->assertStringContainsString("test_size_sum 0.5\n", $output);
    }

    public function test_gauge_accepts_a_negative_change(): void
    {
        $prometheus = $this->freshPrometheus();

        $prometheus->gauge('balance', 'Balance')->decBy(2.5);

        $this->assertStringContainsString("test_balance -2.5\n", $prometheus->render());
    }

    public function test_number_format_keeps_precision(): void
    {
        $this->assertSame('5', Number::format(5));
        $this->assertSame('5', Number::format(5.0));
        $this->assertSame('0.3', Number::format(0.3));
        $this->assertSame('12.45', Number::format(12.45));
        $this->assertSame('1234567890123456', Number::format(1234567890123456.0));
        $this->assertSame('1700000000.123456', Number::format(1700000000.123456));
        $this->assertSame('NaN', Number::format(NAN));
        $this->assertSame('+Inf', Number::format(INF));
        $this->assertSame('-Inf', Number::format(-INF));
        $this->assertSame(1.0E+25, (float) Number::format(1.0E+25));
    }

    public function test_number_parse_reads_what_format_wrote(): void
    {
        $this->assertSame(4.5, Number::parse('4.5'));
        $this->assertSame(7.0, Number::parse('7'));
        $this->assertTrue(is_nan(Number::parse('NaN')));
        $this->assertSame(INF, Number::parse('+Inf'));
        $this->assertSame(-INF, Number::parse('-Inf'));
    }
}
