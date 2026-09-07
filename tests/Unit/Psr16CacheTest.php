<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use DateInterval;
use IndexNowKit\Yii2\Cache\InvalidKey;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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
        $inner = new ArrayCache();
        $cache = new Psr16Cache($inner);
        $cache->set('a', 1, 1);
        self::assertSame(1, $cache->get('a'));
        // ArrayCache stores [value, expiry as microtime(true)]: move the expiry into the past instead of sleeping through it.
        $items = (new ReflectionProperty(ArrayCache::class, '_cache'))->getValue($inner);
        self::assertIsArray($items);
        foreach ($items as $key => $item) {
            $items[$key] = [$item[0], 1.0];
        }
        (new ReflectionProperty(ArrayCache::class, '_cache'))->setValue($inner, $items);
        self::assertNull($cache->get('a'));
    }

    public function testFalseIsAValueAndKeysAreValidated(): void
    {
        $cache = new Psr16Cache(new ArrayCache());
        $cache->set('f', false);
        self::assertFalse($cache->get('f', 'default'), 'a stored false is not a miss');
        self::assertSame(['f' => false, 'missing' => 'd'], iterator_to_array($cache->getMultiple(['f', 'missing'], 'd')));
        $this->expectException(InvalidKey::class); // the PSR-16 interface is not Throwable in psr/simple-cache 1.0 (lowest)
        $cache->get('a:b');
    }
}
