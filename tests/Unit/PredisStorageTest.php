<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Smart145\Prometheus\Tests\RedisStorageTestCase;

class PredisStorageTest extends RedisStorageTestCase
{
    protected function redisClient(): string
    {
        return 'predis';
    }
}
