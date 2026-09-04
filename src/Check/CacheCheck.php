<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Yii2\App;
use Throwable;
use yii\caching\CacheInterface;

/**
 * The debounce window lives in a cache component: it must exist and answer.
 */
final class CacheCheck implements CheckInterface
{
    /**
     * @param array<string, mixed> $options the component's options
     */
    public function __construct(private readonly array $options) {}

    public function check(CheckReport $report): void
    {
        $debounce = \is_array($this->options['debounce'] ?? null) ? $this->options['debounce'] : [];
        $store = \is_string($debounce['store'] ?? null) && $debounce['store'] !== '' ? $debounce['store'] : 'cache';
        $window = is_numeric($debounce['per_url'] ?? null) ? (int) $debounce['per_url'] : 600;
        if ($window <= 0) {
            $report->ok('debounce: off (debounce.per_url: 0)');

            return;
        }
        if ($store === 'memory' || $store === 'none') {
            $report->warning(\sprintf('debounce: store "%s" does not survive the process; a URL saved twice in two requests is sent twice. Use a cache component in production.', $store));

            return;
        }
        try {
            $cache = App::component($store);
            if (!$cache instanceof CacheInterface) {
                $report->error(\sprintf('debounce: component "%s" is not a yii\caching\CacheInterface; URLs are still sent, the window is not applied.', $store));

                return;
            }
            $cache->set('indexnowkit:check', 1, 5);
            $report->ok(\sprintf('debounce: %ds window in cache component "%s" (%s)', $window, $store, get_debug_type($cache)));
        } catch (Throwable $e) {
            $report->error(\sprintf('debounce: cache component "%s" is not usable (%s); URLs are still sent, the window is not applied.', $store, $e->getMessage()));
        }
    }
}
