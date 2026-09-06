<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use Closure;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\HistoryOptions;
use IndexNowKit\Yii2\App;
use IndexNowKit\Yii2\IndexNowComponent;
use ReflectionClass;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bodies of `php yii indexnow/history` and `php yii indexnow/status`: the only part of the controller that reads
 * `IndexNowKit\History\*`, called by {@see IndexNowController} only when `indexnowkit/history` is installed.
 */
final class HistoryAction
{
    /** The inputs of `indexnow/history`, from the history package's definitions. */
    public static function definition(): CommandDefinition
    {
        return Definitions::history();
    }

    /** The inputs of `indexnow/status`. */
    public static function statusDefinition(): CommandDefinition
    {
        return Definitions::status();
    }

    /**
     * @param int|string       $limit as typed (`--limit=20`)
     * @param bool|string|null $purge null = no purge; true = `--purge` alone (history.retention_days); a string = that many days
     */
    public static function history(IndexNowComponent $component, SymfonyStyle $io, ?string $host, ?string $status, ?string $url, ?string $since, int|string $limit, bool $json, bool|string|null $purge): int
    {
        $runner = HistoryServices::historyRunnerFor($component->historyConfig(), $component->services());

        return $runner->run($io, new HistoryOptions($host, $status, $url, $since, $limit, $json, $purge));
    }

    /**
     * `indexnow/status`: the debounce store described as `<component id> (<class>)`, and with `dispatch: queue` the
     * queue component and its class as the adapter facts.
     */
    public static function status(IndexNowComponent $component, SymfonyStyle $io, bool $json): int
    {
        $services = $component->services();
        $facts = $services->config->dispatch === 'queue' ? self::queueFacts($component) : null;

        return HistoryServices::statusRunnerFor($services, self::debounceDescription($component, $services), $facts)->run($io, $json);
    }

    private static function debounceDescription(IndexNowComponent $component, Services $services): string
    {
        $store = $services->config->debounceStore ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE;
        if ($component->debounceStore !== null) {
            return 'custom (' . (new ReflectionClass($services->debounceStore()))->getShortName() . ')';
        }
        if (\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            return $store;
        }
        $cache = App::component($store);

        return \sprintf('%s (%s)', $store, $cache === null ? 'missing' : (new ReflectionClass($cache))->getShortName());
    }

    /**
     * @return Closure(): array<string, scalar|null>
     */
    private static function queueFacts(IndexNowComponent $component): Closure
    {
        return static function () use ($component): array {
            $id = $component->queueComponentId();
            $queue = App::component($id);

            return ['component' => $id, 'class' => $queue === null ? null : $queue::class];
        };
    }
}
