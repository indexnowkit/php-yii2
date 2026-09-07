<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;

/**
 * The debounce store over a Yii cache component is the core's `Psr16DebounceStore` over the package's PSR-16 view
 * (wave M, spec 19 §2.9: `Debounce\YiiCacheDebounceStore` was the same class over the Yii API). The scenario of
 * its test; the zero window is the submitter's business (`debounce.per_url = 0` never asks the store), so the
 * core's store only refuses to record it.
 */
final class Psr16DebounceStoreOverYiiCacheTest extends TestCase
{
    #[TestDox('marked URLs are recent within the window; unknown ones are not; a zero window disables both sides')]
    public function testWindow(): void
    {
        $store = new Psr16DebounceStore(new Psr16Cache(new ArrayCache()), 'p_');

        self::assertSame([], $store->filterRecent(['https://a/1'], 60));
        $store->markSubmitted(['https://a/1', 'https://a/2'], 60);
        self::assertSame(['https://a/1', 'https://a/2'], $store->filterRecent(['https://a/1', 'https://a/2', 'https://a/3'], 60));
        $store->markSubmitted(['https://a/9'], 0);
        self::assertSame([], $store->filterRecent(['https://a/9'], 60), 'a zero window records nothing');
    }
}
