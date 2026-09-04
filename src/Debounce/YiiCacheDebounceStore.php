<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Debounce;

use IndexNowKit\Debounce\DebounceStoreInterface;
use yii\caching\CacheInterface;

/**
 * Debounce window over a Yii cache component (`debounce.store`): one key per normalized URL, kept for the window.
 */
final class YiiCacheDebounceStore implements DebounceStoreInterface
{
    public function __construct(private readonly CacheInterface $cache, private readonly string $prefix = 'indexnow_') {}

    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        if ($urls === [] || $ttlSeconds <= 0) {
            return [];
        }
        $keys = [];
        foreach ($urls as $url) {
            $keys[$this->key($url)] = $url;
        }
        $hits = $this->cache->multiGet(array_keys($keys));
        $recent = [];
        foreach ($hits as $key => $value) {
            if ($value !== false && $value !== null && isset($keys[$key])) {
                $recent[] = $keys[$key];
            }
        }

        return $recent;
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void
    {
        if ($urls === [] || $ttlSeconds <= 0) {
            return;
        }
        $items = [];
        foreach ($urls as $url) {
            $items[$this->key($url)] = 1;
        }
        $this->cache->multiSet($items, $ttlSeconds);
    }

    private function key(string $url): string
    {
        return $this->prefix . sha1($url);
    }
}
