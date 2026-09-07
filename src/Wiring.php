<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Check\SampleGateCheck;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
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
use IndexNowKit\Yii2\Check\RouterCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use IndexNowKit\Yii2\Queue\QueueDispatcher;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface as Psr16;
use Yii;
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
    /** @var array<string, mixed>|null the `router` block, read once (the deprecation warnings are written once) */
    private ?array $router = null;

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
        if ($component->clock !== null) {
            // one clock for the throttle, the debounce window and the submission timestamps (Testing\FrozenClock in tests)
            $builder->clock(static fn(): ClockInterface => References::ensure(References::reference($component->clock), ClockInterface::class));
        }
        if ($component->verifyEnabled()) {
            // The pre-flight decorator around the default submitter of the graph: sync flushes and yii2-queue jobs verify.
            // `Services::submitter()` cannot be asked for here (this closure *is* that node), so the clock is passed by hand.
            $builder->submitter(static fn(Services $s): SubmitterInterface => VerifyServices::submitterFor(
                new Submitter($s->client(), $s->config, $s->debounceStore(), $s->logger, $s->normalizer(), $component->events(), $s->submissionStore(), $s->clock()),
                $component->verifyConfig(),
                $s,
                $component->verifyTransport(),
                $component->robots(),
                $s->config->dispatch === DispatcherFactory::SYNC && Yii::$app instanceof \yii\web\Application,
            ));
        }
        $builder->debounceStore($component->debounceStore !== null
            ? static fn(): DebounceStoreInterface => References::ensure(References::reference($component->debounceStore), DebounceStoreInterface::class)
            : static fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig(
                $s->config,
                static fn(string $id): DebounceStoreInterface => new YiiCacheDebounceStore(Instance::ensure($id, CacheInterface::class), $s->config->debounceKeyPrefix),
                IndexNowComponent::DEFAULT_DEBOUNCE_STORE,
                $s->clock(),
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
            $builder->submissionStore(static fn(Services $s): SubmissionStoreInterface => HistoryServices::storeFor(
                $component->historyConfig(),
                $s->config,
                static fn(?string $id): PDO => self::masterPdo($id ?? 'db'),
                static fn(?string $id): Psr16 => new Psr16Cache(Instance::ensure($id ?? IndexNowComponent::DEFAULT_DEBOUNCE_STORE, CacheInterface::class)),
                IndexNowComponent::DEFAULT_DEBOUNCE_STORE,
            ));
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
            new RouterCheck($this->routerLocales(), $component->activeRecordEnabled() ? $component->modelClasses() : [], $services->rules()),
            new ActiveRecordCheck($component->activeRecordEnabled(), $component->modelClasses()),
            $component->sitemapInstalled() ? SitemapServices::spoolCheck($component->sitemapConfig()) : $component->sitemapPackage()->check($component->block('sitemap')),
            ...$component->verifyInstalled()
                ? VerifyServices::checksFor($component->verifyConfig(), $services, 'queue', SampleGateCheck::withPackage($component->samples, VerifyServices::sampleCheck($component->verifyTransport(), $component->verifyConfig(), $services->normalizer(), $services->keys(), $component->samples->sampler, $component->robots())))
                : [SampleGateCheck::withoutPackage($component->samples, $component->verifyPackage(), $component->block('verify'))],
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
            throw new ConfigurationException(\sprintf('indexnow: component "%s" (queue.component) is not a yii\queue\Queue.', $id));
        }

        return $queue;
    }

    /** The PDO of a `db` connection component, for the `pdo` store of `history.store` (`history.pdo.service`). */
    private static function masterPdo(string $id): PDO
    {
        $connection = Instance::ensure($id, Connection::class);
        \assert($connection instanceof Connection);
        $pdo = $connection->getMasterPdo();
        \assert($pdo instanceof PDO);

        return $pdo;
    }

    /** The deprecated spellings of the `router` block (before 0.12.0 the adapter said "language" where the core says "locale"). */
    public const ROUTER_RENAMED = ['languages' => 'locales', 'language_parameter' => 'locale_parameter', 'set_app_language' => 'set_app_locale'];

    /** The URL manager bridge with the `router` block (locales, the locale parameter, whether to set the app language). */
    private function router(Services $services): RouteUrlResolverInterface
    {
        $router = $this->routerBlock();
        $parameter = $router['locale_parameter'] ?? 'language';

        return new YiiRouteUrlResolver($services->config, $this->routerLocales(), \is_string($parameter) && $parameter !== '' ? $parameter : 'language', (bool) ($router['set_app_locale'] ?? true));
    }

    /**
     * The `router` block with the pre-0.12 spellings folded in, each one warned about once.
     *
     * @return array<string, mixed>
     */
    private function routerBlock(): array
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $router = $this->component->block('router');
        foreach (self::ROUTER_RENAMED as $old => $new) {
            if (\array_key_exists($old, $router)) {
                $router[$new] ??= $router[$old];
                $this->component->logger()->warning('indexnow: option "router.{old}" is deprecated since indexnowkit/yii2 0.12.0, rename it to "router.{new}" (the vocabulary of the core: locale); the old spelling is read until the next minor', ['old' => $old, 'new' => $new]);
            }
        }

        return $this->router = $router;
    }

    /**
     * The locales `locales: 'all'` expands to (`router.locales`); empty when the option is unset.
     *
     * @return list<string>
     */
    private function routerLocales(): array
    {
        $locales = $this->routerBlock()['locales'] ?? null;

        return \is_array($locales) ? array_values(array_filter($locales, 'is_string')) : [];
    }

    /**
     * `#[IndexNow(resolver: ...)]` ids: an application component, a container definition or a class `Yii::$container`
     * can build. A throw is left to `Url\ArrayResolverLocator`, which turns every adapter's container failure into the
     * one `ConfigurationException` text.
     */
    private static function locateResolver(string $id): ?object
    {
        $resolver = App::component($id);
        if ($resolver === null && (Yii::$container->has($id) || class_exists($id))) {
            $resolver = Yii::$container->get($id);
        }

        return \is_object($resolver) ? $resolver : null;
    }
}
