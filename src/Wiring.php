<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Yii2\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use IndexNowKit\Yii2\Check\ActiveRecordCheck;
use IndexNowKit\Yii2\Check\CacheProbe;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Check\VerifySampleCheck;
use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use IndexNowKit\Yii2\Queue\QueueDispatcher;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use PDO;
use Psr\SimpleCache\CacheInterface as Psr16;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\db\Connection;
use yii\di\Instance;
use yii\queue\Queue;

/**
 * The core graph of {@see IndexNowComponent}, described once through `Adapter\ServicesBuilder`: the properties of
 * the component are the overrides, the Yii pieces (`http.client` through the container, the cache component as the
 * debounce store, yii2-queue, the URL manager, `#[IndexNow(resolver: ...)]` ids) are closures, everything else
 * comes from the core's factories. Nothing is built before it is used. {@see checks()} lists what
 * `php yii indexnow/check` prints beyond the core's own lines.
 */
final class Wiring
{
    public function __construct(private readonly IndexNowComponent $component) {}

    public function builder(): ServicesBuilder
    {
        $component = $this->component;
        $builder = new ServicesBuilder($component->config(), $component->logger());
        if ($component->transport !== null) {
            $builder->transport(static fn(): TransportInterface => References::ensure(References::reference($component->transport), TransportInterface::class));
        }
        $builder->httpClientLocator(static fn(string $id): mixed => App::component($id) ?? Yii::$container->get($id));
        $builder->events($component->events()); // every Result raises IndexNowComponent::EVENT_RESULT
        if ($component->verifyEnabled()) {
            // The pre-flight decorator around the default submitter of the graph: sync flushes and yii2-queue jobs verify.
            $builder->submitter(static fn(Services $s): SubmitterInterface => VerifyServices::submitterFor(
                new Submitter($s->client(), $s->config, $s->debounceStore(), $s->logger, $s->normalizer(), $component->events(), $s->submissionStore()),
                $component->verifyConfig(),
                $s,
                $component->verifyTransport(),
                $component->robots(),
                $s->config->dispatch === 'sync' && Yii::$app instanceof \yii\web\Application,
            ));
        }
        $builder->debounceStore($component->debounceStore !== null
            ? static fn(): DebounceStoreInterface => References::ensure(References::reference($component->debounceStore), DebounceStoreInterface::class)
            : static fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig(
                $s->config,
                static fn(string $id): DebounceStoreInterface => new YiiCacheDebounceStore(Instance::ensure($id, CacheInterface::class), $s->config->debounceKeyPrefix),
                IndexNowComponent::DEFAULT_DEBOUNCE_STORE,
            ));
        $store = $component->config()->debounceStore ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE;
        if ($component->debounceStore === null && !\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            // The 403 counter shares the cache component behind `debounce.store`; memory/none leave it in the process.
            $builder->failureCache(static fn(): Psr16 => new Psr16Cache(Instance::ensure($store, CacheInterface::class)));
        }
        if ($component->submissionStore !== null) {
            $builder->submissionStore(static fn(): SubmissionStoreInterface => References::ensure(References::reference($component->submissionStore), SubmissionStoreInterface::class));
        } elseif ($component->historyEnabled()) {
            // The store of `history.store` (indexnowkit/history): the submitter, the queue job, the commands and the verify decorator record into it.
            $builder->submissionStore(static fn(Services $s): SubmissionStoreInterface => self::historyStore($component->historyConfig(), $s));
        }
        if ($component->dispatcher !== null) {
            $builder->dispatcher(static fn(): DispatcherInterface => References::ensure(References::reference($component->dispatcher), DispatcherInterface::class));
        }
        $builder->queueFactory(fn(Services $s): DispatcherInterface => $this->queueDispatcher($s));
        // How `params` and `when` are read off records: attributes and relations through Active Record, the rest through the core DSL.
        $builder->paramExtractor(static fn(): ParamExtractor => new ParamExtractor(new ActiveRecordSubjectReader()));
        $builder->router(fn(Services $s): RouteUrlResolverInterface => $this->router($s));
        $builder->resolverLocator(static fn(): ArrayResolverLocator => new ArrayResolverLocator([], locate: self::locateResolver(...), hint: 'a component, a container definition'));
        if ($component->urlResolver !== null) {
            $builder->urlResolver(static fn(): UrlResolverInterface => References::ensure(References::reference($component->urlResolver), UrlResolverInterface::class));
        }
        $builder->checks(fn(Services $s): iterable => $this->checks($s));

        return $builder;
    }

    /**
     * The lines of `php yii indexnow/check` beyond the core's own: the Yii pieces, then the `checks` property.
     *
     * @return list<CheckInterface>
     */
    public function checks(Services $services): array
    {
        $component = $this->component;
        $checks = [
            new QueueCheck($component->options, $services->config->dispatch, $component->queueExists()),
            new DebounceStoreCheck($services->config, (new CacheProbe())(...), IndexNowComponent::DEFAULT_DEBOUNCE_STORE),
            new UrlManagerCheck($component->options),
            new ActiveRecordCheck($component->activeRecordEnabled(), $component->modelClasses()),
            $component->sitemapInstalled() ? SitemapServices::spoolCheck($component->sitemapConfig()) : $component->sitemapPackage()->check($component->block('sitemap')),
            ...$component->verifyInstalled()
                ? VerifyServices::checksFor($component->verifyConfig(), $services, 'queue', new VerifySampleCheck($component->samples, VerifyServices::sampleCheck($component->verifyTransport(), $component->verifyConfig(), $services->normalizer(), $services->keys(), $component->samples->sampler, $component->robots())))
                : [new VerifySampleCheck($component->samples, null, $component->verifyPackage()->checkLine($component->block('verify')), $component->verifyPackage()->checkLevel($component->block('verify')))],
            ...$component->historyInstalled()
                ? HistoryServices::checksFor($component->historyConfig(), $services)
                : [$component->historyPackage()->check($component->block('history'))],
        ];
        foreach ($component->checks as $check) {
            $checks[] = References::ensure(References::reference($check), CheckInterface::class);
        }

        return $checks;
    }

    /** `dispatch: queue`: yii2-queue with the `queue` block, the component resolved on the first flush. */
    private function queueDispatcher(Services $services): DispatcherInterface
    {
        $queue = $this->component->block('queue');
        $ttr = $queue['ttr'] ?? null;
        $delay = $queue['delay'] ?? null;
        $priority = $queue['priority'] ?? null;

        return new QueueDispatcher(fn(): Queue => $this->queue(), $services->config, $services->logger, is_numeric($ttr) ? (int) $ttr : 300, is_numeric($delay) ? (int) $delay : 0, \is_int($priority) || \is_string($priority) ? $priority : null);
    }

    private function queue(): Queue
    {
        $id = $this->component->queueComponentId();
        $queue = App::component($id);
        if (!$queue instanceof Queue) {
            throw new InvalidConfigException(\sprintf('indexnow: component "%s" is not a yii\queue\Queue.', $id));
        }

        return $queue;
    }

    /**
     * The store of `history.store` (indexnowkit/history): `pdo` over the PDO of the `db` component named by
     * `history.pdo.service` (default `db`) or a PDO built from `history.pdo.dsn`; `psr16` over the cache component of
     * `debounce.store` (the `cache` component with `memory`/`none`) through the package's PSR-16 bridge.
     */
    private static function historyStore(HistoryConfig $history, Services $services): SubmissionStoreInterface
    {
        if ($history->store === HistoryConfig::STORE_PDO) {
            if ($history->pdoDsn !== null) {
                return HistoryServices::pdoStore(HistoryServices::pdoFromDsn($history->pdoDsn), $history);
            }
            $connection = Instance::ensure($history->pdoService ?? 'db', Connection::class);
            \assert($connection instanceof Connection);
            $pdo = $connection->getMasterPdo();
            \assert($pdo instanceof PDO);

            return HistoryServices::pdoStore($pdo, $history);
        }
        if ($history->store !== HistoryConfig::STORE_PSR16) {
            throw new InvalidConfigException('indexnow: history.store is set but no store was built.');
        }
        $cache = HistoryServices::debounceCacheId($services->config) ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE;

        return HistoryServices::psr16Store(new Psr16Cache(Instance::ensure($cache, CacheInterface::class)), $history, $services->config);
    }

    /** The deprecated spellings of the `router` block (before 0.12.0 the adapter said "language" where the core says "locale"). */
    public const ROUTER_RENAMED = ['languages' => 'locales', 'language_parameter' => 'locale_parameter', 'set_app_language' => 'set_app_locale'];

    /** The URL manager bridge with the `router` block (locales, the locale parameter, whether to set the app language). */
    private function router(Services $services): RouteUrlResolverInterface
    {
        $router = $this->component->block('router');
        foreach (self::ROUTER_RENAMED as $old => $new) {
            if (\array_key_exists($old, $router)) {
                $router[$new] ??= $router[$old];
                $this->component->logger()->warning('indexnow: option "router.{old}" is deprecated since indexnowkit/yii2 0.12.0, rename it to "router.{new}" (the vocabulary of the core: locale); the old spelling is read until the next minor', ['old' => $old, 'new' => $new]);
            }
        }
        $locales = \is_array($router['locales'] ?? null) ? array_values(array_filter($router['locales'], 'is_string')) : [];
        $parameter = $router['locale_parameter'] ?? 'language';

        return new YiiRouteUrlResolver($services->config, $locales, \is_string($parameter) && $parameter !== '' ? $parameter : 'language', (bool) ($router['set_app_locale'] ?? true));
    }

    /** `#[IndexNow(resolver: ...)]` ids: an application component, a container definition or a class `Yii::$container` can build. */
    private static function locateResolver(string $id): ?object
    {
        try {
            $resolver = App::component($id);
            if ($resolver === null && (Yii::$container->has($id) || class_exists($id))) {
                $resolver = Yii::$container->get($id);
            }
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('IndexNow URL resolver "%s" cannot be built: %s', $id, $e->getMessage()), 0, $e);
        }

        return \is_object($resolver) ? $resolver : null;
    }
}
