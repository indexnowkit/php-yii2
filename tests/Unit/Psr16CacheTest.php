<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use DateInterval;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;

final class Psr16CacheTest extends TestCase
{
    #[TestDox('wraps a yii\caching cache as PSR-16: get with default, set with TTL, delete, multi operations, has')]
    public function testRoundTrip(): void
    {
        $yii = new ArrayCache();
        $cache = new Psr16Cache($yii);

        self::assertNull($cache->get('missing'));
        self::assertSame(0, $cache->get('missing', 0));
        self::assertTrue($cache->set('a', 1, 60));
        self::assertSame(1, $cache->get('a'));
        self::assertTrue($cache->has('a'));
        self::assertTrue($cache->set('b', ['x'], new DateInterval('PT1M')));
        self::assertSame(['a' => 1, 'b' => ['x'], 'c' => 'd'], $cache->getMultiple(['a', 'b', 'c'], 'd'));
        self::assertTrue($cache->setMultiple(['c' => 3]));
        self::assertTrue($cache->deleteMultiple(['a', 'c']));
        self::assertFalse($cache->has('a'));
        self::assertTrue($cache->delete('b'));
        self::assertNull($cache->get('b'));
        self::assertTrue($cache->set('e', 5));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('e'));
    }

    public function testExpiredEntriesAreGone(): void
    {
        $cache = new Psr16Cache(new ArrayCache());
        $cache->set('a', 1, 1);
        self::assertSame(1, $cache->get('a'));
        sleep(2);
        self::assertNull($cache->get('a'));
    }
}
