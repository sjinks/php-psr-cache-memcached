<?php

namespace WildWolf\Tests;

/**
 * @requires extension memcached
 */
class MemcachedCacheTest extends \Cache\IntegrationTests\SimpleCacheTest
{
    public static function setUpBeforeClass()
    {
        $f = @fsockopen('127.0.0.1', 11211, $errno, $errstr, 3);
        if (!is_resource($f)) {
            throw new \PHPUnit\Framework\SkippedTestError('no memcached server is running');
        }
    }

    public function createSimpleCache()
    {
        static $config = [
            'prefix'  => 'test.',
            'servers' => [['127.0.0.1', 11211, 1]],
        ];

        return new \WildWolf\Cache\Memcached($config);
    }
}
