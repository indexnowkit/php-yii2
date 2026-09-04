<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;

final class YiiCacheDebounceStoreTest extends TestCase
{
    #[TestDox('marked URLs are recent within the window; unknown ones are not; a zero window disables both sides')]
    public function testWindow(): void
    {
        $store = new YiiCacheDebounceStore(new ArrayCache(), 'p_');

        self::assertSame([], $store->filterRecent(['https://a/1'], 60));
        $store->markSubmitted(['https://a/1', 'https://a/2'], 60);
        self::assertSame(['https://a/1', 'https://a/2'], $store->filterRecent(['https://a/1', 'https://a/2', 'https://a/3'], 60));
        self::assertSame([], $store->filterRecent(['https://a/1'], 0));
        $store->markSubmitted(['https://a/9'], 0);
        self::assertSame([], $store->filterRecent(['https://a/9'], 60));
    }
}
