<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\History;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\History\Check\HistoryCheck;
use IndexNowKit\History\Console\HistoryRunner;
use IndexNowKit\History\Console\StatusRunner;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Yii2\App;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use IndexNowKit\Yii2\IndexNowComponent;
use PDO;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use yii\caching\CacheInterface;
use yii\db\Connection;
use yii\di\Instance;

/**
 * The history pieces of the component: the only wiring of the package that reads `IndexNowKit\History\*`, called
 * only when {@see package()} says the package is installed (`IndexNowComponent::historyInstalled()`). With
 * `history.store` set {@see Wiring} puts the package's store into the graph as the submission store (the
 * `submissionStore` property of the component still wins), so sync flushes, yii2-queue jobs, the commands and the
 * verify decorator record into it. `indexnow/history` and `indexnow/status` run over whatever the graph's store is.
 */
final class HistoryServices
{
    /**
     * The one predicate for `indexnowkit/history` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (the component's `historyInstalled`).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/history', HistoryConfig::class, 'history', $installed); // a class, not the interface: OptionalPackage asks class_exists()
    }

    /**
     * The dotted keys of the `history` block, for `Config\ConfigFactory` (`HistoryConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return HistoryConfig::OPTIONS;
    }

    /**
     * The validated `history` block; a broken value (a DSN together with a connection, a bad table name) switches
     * the history off with a critical log line.
     *
     * @param array<string, mixed> $block
     */
    public static function config(array $block, LoggerInterface $logger): HistoryConfig
    {
        return HistoryConfig::loadOrDisabled($block, $logger, 'php yii indexnow/check');
    }

    /**
     * The store of `history.store`: `psr16` over the cache component of `debounce.store` (the `cache` component
     * with `memory`/`none`) through the package's PSR-16 bridge, `pdo` over the PDO of the `db` component named by
     * `history.pdo.service` (default `db`) or a PDO built from `history.pdo.dsn`. Null when `history.store` is null.
     */
    public static function store(HistoryConfig $history, Services $services): ?SubmissionStoreInterface
    {
        if ($history->store === null) {
            return null;
        }
        if ($history->store === HistoryConfig::STORE_PDO) {
            return new PdoSubmissionStore(self::pdo($history), $history->pdoTable);
        }
        $store = $services->config->debounceStore ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE;
        $cache = \in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true) ? IndexNowComponent::DEFAULT_DEBOUNCE_STORE : $store;

        return new Psr16SubmissionStore(new Psr16Cache(Instance::ensure($cache, CacheInterface::class)), $history->keyPrefix ?? $services->config->debounceKeyPrefix, $history->limit);
    }

    /** A PDO of `history.pdo.dsn`, throwing on every error (the store expects exceptions, not false). */
    public static function pdoFromDsn(string $dsn): PDO
    {
        return new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * The `check` lines with the package: `history.store` and `history.records` over the graph's submission store
     * (the null store when nothing is configured).
     *
     * @return list<CheckInterface>
     */
    public static function checks(HistoryConfig $history, Services $services): array
    {
        return [new HistoryCheck($history, $services->submissionStore() ?? new NullSubmissionStore())];
    }

    /** The body of `php yii indexnow/history`. */
    public static function historyRunner(HistoryConfig $history, Services $services): HistoryRunner
    {
        return new HistoryRunner($services->submissionStore() ?? new NullSubmissionStore(), $history, $services->normalizer());
    }

    /**
     * The body of `php yii indexnow/status`: the debounce store described as `<component id> (<class>)`, the
     * graph's 403 counter reader, and with `dispatch: queue` the queue component and its class as the adapter facts.
     */
    public static function statusRunner(IndexNowComponent $component, Services $services): StatusRunner
    {
        $store = $services->config->debounceStore ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE;
        $description = $store;
        if ($component->debounceStore !== null) {
            $description = 'custom (' . (new ReflectionClass($services->debounceStore()))->getShortName() . ')';
        } elseif (!\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            $cache = App::component($store);
            $description = \sprintf('%s (%s)', $store, $cache === null ? 'missing' : (new ReflectionClass($cache))->getShortName());
        }
        $facts = $services->config->dispatch === 'queue' ? self::queueFacts($component) : null;

        return new StatusRunner($services->config, $services->keys(), $services->forbiddenCounter(), $description, $services->submissionStore(), $facts);
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

    private static function pdo(HistoryConfig $history): PDO
    {
        if ($history->pdoDsn !== null) {
            return self::pdoFromDsn($history->pdoDsn);
        }
        $connection = Instance::ensure($history->pdoService ?? 'db', Connection::class);
        \assert($connection instanceof Connection);
        $pdo = $connection->getMasterPdo();
        \assert($pdo instanceof PDO);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
