<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Yii2\App;
use RuntimeException;
use yii\caching\CacheInterface;

/**
 * The probe of the core's `Check\DebounceStoreCheck` for Yii2: the component `debounce.store` names must exist, be
 * a `yii\caching\CacheInterface` and answer a write.
 */
final class CacheProbe
{
    public function __invoke(string $store): string
    {
        $cache = App::component($store);
        if (!$cache instanceof CacheInterface) {
            throw new RuntimeException($cache === null
                ? \sprintf('component "%s" does not exist', $store)
                : \sprintf('component "%s" is a %s, not a yii\caching\CacheInterface', $store, get_debug_type($cache)));
        }
        $cache->set('indexnowkit:check', 1, 5);

        return \sprintf('cache component "%s" (%s)', $store, get_debug_type($cache));
    }
}
