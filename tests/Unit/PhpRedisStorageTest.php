<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Tests\Unit;

use Smart145\Prometheus\Tests\RedisStorageTestCase;

class PhpRedisStorageTest extends RedisStorageTestCase
{
    protected function redisClient(): string
    {
        return 'phpredis';
    }
}
