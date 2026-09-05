<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\Cache\Psr16Cache;
use IndexNowKit\Yii2\Check\ActiveRecordCheck;
use IndexNowKit\Yii2\Check\CacheProbe;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use IndexNowKit\Yii2\Queue\QueueDispatcher;
use IndexNowKit\Yii2\Sitemap\SitemapServices;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use Psr\SimpleCache\CacheInterface as Psr16;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
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
        }
        if ($component->dispatcher !== null) {
            $builder->dispatcher(static fn(): DispatcherInterface => References::ensure(References::reference($component->dispatcher), DispatcherInterface::class));
        }
        $builder->queueFactory(fn(Services $s): DispatcherInterface => $this->queueDispatcher($s));
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

    /** The URL manager bridge with the `router` block (languages, the language parameter, whether to set the app language). */
    private function router(Services $services): RouteUrlResolverInterface
    {
        $router = $this->component->block('router');
        $languages = \is_array($router['languages'] ?? null) ? array_values(array_filter($router['languages'], 'is_string')) : [];
        $parameter = $router['language_parameter'] ?? 'language';

        return new YiiRouteUrlResolver($services->config, $languages, \is_string($parameter) && $parameter !== '' ? $parameter : 'language', (bool) ($router['set_app_language'] ?? true));
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
