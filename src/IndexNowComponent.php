<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Result;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii2\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii2\Check\ActiveRecordCheck;
use IndexNowKit\Yii2\Check\CacheProbe;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use IndexNowKit\Yii2\Http\KeyFileController;
use IndexNowKit\Yii2\Log\YiiLogger;
use IndexNowKit\Yii2\Queue\QueueDispatcher;
use IndexNowKit\Yii2\Sitemap\SitemapServices;
use IndexNowKit\Yii2\Sitemap\SitemapSupport;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;
use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event as YiiEvent;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\db\BaseActiveRecord;
use yii\di\Instance;
use yii\queue\Queue;
use yii\web\Response;
use yii\web\UrlManager;

/**
 * The `indexnow` application component: describes the core graph from `options` with `Adapter\ServicesBuilder`
 * (the core's factories underneath, the Yii pieces as closures, see {@see services()}), hooks ActiveRecord (through {@see ActiveRecord\IndexNowBehavior} or the `active_record.models`
 * list), registers the key file route and the console controller, and flushes the collector when the response has
 * been sent.
 *
 *   'bootstrap' => ['indexnow'],
 *   'components' => ['indexnow' => ['class' => IndexNowComponent::class, 'options' => ['key' => getenv('INDEXNOW_KEY'), 'base_url' => 'https://www.example.com']]],
 *
 * Every core piece is replaceable through a property (`transport`, `debounceStore`, `dispatcher`, `urlResolver`,
 * `logger`, `checks`), given as an instance, a class name or a component id (`Instance::ensure`). The sitemap
 * pieces come from `Sitemap\SitemapServices` when the optional `indexnowkit/sitemap` is installed
 * ({@see SitemapSupport}); without it `indexnow/sitemap` prints one sentence and `indexnow/check` one line.
 */
final class IndexNowComponent extends Component implements BootstrapInterface
{
    public const CONTROLLER_ID = 'indexnow';
    public const KEY_FILE_CONTROLLER_ID = 'indexnow-key-file';
    public const KEY_FILE_ROUTE = self::KEY_FILE_CONTROLLER_ID . '/index';
    /** The debounce store when `debounce.store` is unset: the `cache` application component. */
    public const DEFAULT_DEBOUNCE_STORE = 'cache';

    /** @var array<string, mixed> the configuration tree (core options + the Yii blocks, see docs/configuration.md) */
    public array $options = [];

    /** @var TransportInterface|array<string, mixed>|string|null replacement transport (instance, config array, class or component id) */
    public mixed $transport = null;

    /** @var DebounceStoreInterface|array<string, mixed>|string|null */
    public mixed $debounceStore = null;

    /** @var DispatcherInterface|array<string, mixed>|string|null */
    public mixed $dispatcher = null;

    /** @var UrlResolverInterface|array<string, mixed>|string|null replaces the attribute resolver entirely */
    public mixed $urlResolver = null;

    /** @var LoggerInterface|array<string, mixed>|string|null PSR-3 logger; default: Yii's logger under `logging.category` */
    public mixed $logger = null;

    /** @var list<CheckInterface|array<string, mixed>|string> extra checks for `php yii indexnow/check` */
    public array $checks = [];

    /** Environment name for `production_environments`; default YII_ENV. */
    public ?string $environment = null;

    private static bool $readerRegistered = false;

    private ?Config $config = null;
    private ?LoggerInterface $psrLogger = null;
    private ?Services $services = null;
    private ?IndexNowObserver $observer = null;
    private ?VerifyingStaging $staging = null;
    private ?SitemapConfig $sitemapConfig = null;
    private ?SitemapSourceInterface $sitemap = null;

    public function init(): void
    {
        parent::init();
        if (!self::$readerRegistered) {
            ParamExtractor::registerReader(new ActiveRecordSubjectReader());
            self::$readerRegistered = true;
        }
    }

    /**
     * @param Application $app
     */
    public function bootstrap($app): void
    {
        $isConsole = $app instanceof \yii\console\Application;
        if ($isConsole) {
            $app->controllerMap[self::CONTROLLER_ID] ??= ['class' => IndexNowController::class];
            $app->on(Application::EVENT_AFTER_REQUEST, function (): void {
                $this->flushIfCollected();
            });
            if (class_exists(Queue::class)) {
                $flush = function (): void {
                    $this->flushIfCollected();
                };
                YiiEvent::on(Queue::class, Queue::EVENT_AFTER_EXEC, $flush);
                YiiEvent::on(Queue::class, Queue::EVENT_AFTER_ERROR, $flush);
            }
        } elseif ($app instanceof \yii\web\Application) {
            $app->controllerMap[self::KEY_FILE_CONTROLLER_ID] ??= ['class' => KeyFileController::class];
            $this->registerKeyFileRule($app);
            $app->getResponse()->on(Response::EVENT_AFTER_SEND, function (): void {
                $this->flushIfCollected();
            });
        }
        $this->hookModels();
    }

    // -- the graph ---------------------------------------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config ??= ConfigFactory::create($this->options, $this->environment ?? (\defined('YII_ENV') ? (string) \constant('YII_ENV') : 'prod'), $this->queueExists(), $this->logger());
    }

    public function logger(): LoggerInterface
    {
        if ($this->psrLogger === null) {
            if ($this->logger !== null) {
                $logger = Instance::ensure($this->logger, LoggerInterface::class);
                \assert($logger instanceof LoggerInterface);
                $this->psrLogger = $logger;
            } else {
                $category = $this->block('logging')['category'] ?? null;
                $this->psrLogger = new YiiLogger(Yii::getLogger(), \is_string($category) && $category !== '' ? $category : 'indexnow');
            }
        }

        return $this->psrLogger;
    }

    /**
     * The core graph (`Adapter\Services`), described once through `Adapter\ServicesBuilder`: the properties of this
     * component are the overrides, the Yii pieces (`http.client` through the container, the cache component as the
     * debounce store, yii2-queue, the URL manager, `#[IndexNow(resolver: ...)]` ids) are closures, everything else
     * comes from the core's factories. Nothing is built before it is used.
     */
    public function services(): Services
    {
        if ($this->services === null) {
            $builder = new ServicesBuilder($this->config(), $this->logger());
            if ($this->transport !== null) {
                $builder->transport(fn(): TransportInterface => $this->ensure($this->reference($this->transport), TransportInterface::class));
            }
            $builder->httpClientLocator(static fn(string $id): mixed => App::component($id) ?? Yii::$container->get($id));
            $builder->debounceStore($this->debounceStore !== null
                ? fn(): DebounceStoreInterface => $this->ensure($this->reference($this->debounceStore), DebounceStoreInterface::class)
                : static fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig(
                    $s->config,
                    static fn(string $id): DebounceStoreInterface => new YiiCacheDebounceStore(Instance::ensure($id, CacheInterface::class), $s->config->debounceKeyPrefix),
                    self::DEFAULT_DEBOUNCE_STORE,
                ));
            if ($this->dispatcher !== null) {
                $builder->dispatcher(fn(): DispatcherInterface => $this->ensure($this->reference($this->dispatcher), DispatcherInterface::class));
            }
            $builder->queueFactory(function (Services $s): DispatcherInterface {
                $queue = $this->block('queue');
                $ttr = $queue['ttr'] ?? null;
                $delay = $queue['delay'] ?? null;
                $priority = $queue['priority'] ?? null;

                return new QueueDispatcher(fn(): Queue => $this->queue(), $s->config, $s->logger, is_numeric($ttr) ? (int) $ttr : 300, is_numeric($delay) ? (int) $delay : 0, \is_int($priority) || \is_string($priority) ? $priority : null);
            });
            $builder->router(function (Services $s): RouteUrlResolverInterface {
                $router = $this->block('router');
                $languages = \is_array($router['languages'] ?? null) ? array_values(array_filter($router['languages'], 'is_string')) : [];
                $parameter = $router['language_parameter'] ?? 'language';

                return new YiiRouteUrlResolver($s->config, $languages, \is_string($parameter) && $parameter !== '' ? $parameter : 'language', (bool) ($router['set_app_language'] ?? true));
            });
            $builder->resolverLocator(static fn(): ArrayResolverLocator => new ArrayResolverLocator([], locate: static function (string $id): ?object {
                try {
                    $resolver = App::component($id);
                    if ($resolver === null && (Yii::$container->has($id) || class_exists($id))) {
                        $resolver = Yii::$container->get($id);
                    }
                } catch (Throwable $e) {
                    throw new ConfigurationException(\sprintf('IndexNow URL resolver "%s" cannot be built: %s', $id, $e->getMessage()), 0, $e);
                }

                return \is_object($resolver) ? $resolver : null;
            }, hint: 'a component, a container definition'));
            if ($this->urlResolver !== null) {
                $builder->urlResolver(fn(): UrlResolverInterface => $this->ensure($this->reference($this->urlResolver), UrlResolverInterface::class));
            }
            $builder->checks(fn(Services $s): iterable => $this->checks($s));
            $this->services = $builder->build();
        }

        return $this->services;
    }

    public function kit(): IndexNowKit
    {
        return $this->services()->kit();
    }

    public function rules(): RuleRegistry
    {
        return $this->services()->rules();
    }

    public function keys(): KeyProviderInterface
    {
        return $this->services()->keys();
    }

    /**
     * The `transport` property, else `http.client` (an application component id, a DI definition or a class of a
     * PSR-18 client, resolved on the first request), else PSR-18 discovery.
     */
    public function transport(): TransportInterface
    {
        return $this->services()->transport();
    }

    public function normalizer(): UrlNormalizerInterface
    {
        return $this->services()->normalizer();
    }

    public function throttle(): ThrottleInterface
    {
        return $this->services()->throttle();
    }

    /**
     * The `debounceStore` property, else `debounce.store`: `memory`, `none`, or a cache component id (the `cache`
     * component by default) wrapped in {@see YiiCacheDebounceStore}, since Yii's cache is not PSR-16.
     */
    public function debounceStore(): DebounceStoreInterface
    {
        return $this->services()->debounceStore();
    }

    public function submitter(): SubmitterInterface
    {
        return $this->services()->submitter();
    }

    public function collector(): CollectorInterface
    {
        return $this->services()->collector();
    }

    /**
     * The `dispatcher` property, else `dispatch`: none, sync, or `queue` through yii2-queue with the `queue` block.
     */
    public function dispatcher(): DispatcherInterface
    {
        return $this->services()->dispatcher();
    }

    public function routeResolver(): RouteUrlResolverInterface
    {
        $router = $this->services()->router();
        \assert($router instanceof RouteUrlResolverInterface, 'the component always gives the builder a router');

        return $router;
    }

    /**
     * The `urlResolver` property, else the attribute resolver over the router bridge; `#[IndexNow(resolver: ...)]`
     * ids are application components, DI definitions or classes `Yii::createObject()` can build.
     */
    public function guardedResolver(): GuardedUrlResolver
    {
        return $this->services()->guardedResolver();
    }

    public function staging(): VerifyingStaging
    {
        return $this->staging ??= new VerifyingStaging($this->logger(), $this->config()->logUrls);
    }

    public function observer(): IndexNowObserver
    {
        return $this->observer ??= new IndexNowObserver($this->kit(), $this->staging(), $this->logger(), $this->activeRecordEnabled());
    }

    /**
     * The validated `sitemap` block; a broken value disables the sitemap command with a critical log line, like the
     * core options. Needs the optional `indexnowkit/sitemap`: without it a LogicException with the install line.
     *
     * @throws LogicException when indexnowkit/sitemap is not installed
     */
    public function sitemapConfig(): SitemapConfig
    {
        $this->requireSitemap();

        return $this->sitemapConfig ??= SitemapServices::config($this->block('sitemap'), $this->logger());
    }

    /**
     * @throws LogicException when indexnowkit/sitemap is not installed
     */
    public function sitemapSource(): SitemapSourceInterface
    {
        $this->requireSitemap();

        return $this->sitemap ??= SitemapServices::reader($this->sitemapConfig(), $this->transport(), $this->logger());
    }

    /** Whether the optional `indexnowkit/sitemap` is installed ({@see SitemapSupport}). */
    public function sitemapInstalled(): bool
    {
        return SitemapSupport::installed();
    }

    private function requireSitemap(): void
    {
        if (!SitemapSupport::installed()) {
            throw new LogicException(SitemapSupport::NOT_INSTALLED);
        }
    }

    public function keyFileResponder(): KeyFileResponder
    {
        return $this->services()->keyFileResponder();
    }

    public function checker(): CheckerInterface
    {
        return $this->services()->checker();
    }

    /**
     * The lines of `php yii indexnow/check` beyond the core's own: the Yii pieces, then the `checks` property.
     *
     * @return list<CheckInterface>
     */
    private function checks(Services $services): array
    {
        $checks = [
            new QueueCheck($this->options, $services->config->dispatch, $this->queueExists()),
            new DebounceStoreCheck($services->config, (new CacheProbe())(...), self::DEFAULT_DEBOUNCE_STORE),
            new UrlManagerCheck($this->options),
            new ActiveRecordCheck($this->activeRecordEnabled(), $this->modelClasses()),
            SitemapSupport::installed() ? SitemapServices::spoolCheck($this->sitemapConfig()) : new StaticCheck(CheckLevel::Ok, SitemapSupport::checkLine($this->block('sitemap'))),
        ];
        foreach ($this->checks as $check) {
            $checks[] = $this->ensure($this->reference($check), CheckInterface::class);
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>|object|string what `Instance::ensure()` accepts, or an InvalidConfigException naming the value
     */
    private function reference(mixed $value): array|object|string
    {
        if (\is_object($value) || \is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        throw new InvalidConfigException(\sprintf('indexnow: an override must be an instance, a config array, a class name or a component id, got %s.', get_debug_type($value)));
    }

    /**
     * A property override (an instance, a config array, a class name or a component id) as the type it must be.
     *
     * @template T of object
     *
     * @param array<string, mixed>|object|string $reference
     * @param class-string<T>                    $type
     *
     * @return T
     */
    private function ensure(array|object|string $reference, string $type): object
    {
        $instance = Instance::ensure($reference, $type);
        \assert($instance instanceof $type);

        return $instance;
    }

    // -- the application-facing API ----------------------------------------------------------------------------------

    /**
     * Hooks an ActiveRecord class that has no IndexNowBehavior: with $rules, they replace whatever #[IndexNow]
     * attributes the class carries; without, the class's own attributes are used.
     *
     * @param class-string<BaseActiveRecord> $class
     * @param list<IndexNow>                 $rules
     */
    public function observe(string $class, array $rules = [], ?IndexNowDefaults $defaults = null): void
    {
        if ($rules !== [] || $defaults !== null) {
            $this->rules()->register($class, $rules, $defaults);
        }
        $this->observer()->attachTo($class);
    }

    /**
     * @param iterable<string> $urls
     *
     * @return list<Result>
     */
    public function submit(iterable $urls): array
    {
        return $this->kit()->submit($urls);
    }

    /**
     * @return list<Result>
     */
    public function submitRecord(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->submitEntity($record, $event);
    }

    /**
     * The manual path after updateAll()/deleteAll()/link(), which fire no ActiveRecord events.
     *
     * @param iterable<object> $records
     *
     * @return list<Result>
     */
    public function submitRecords(iterable $records, Event $event = Event::Updated): array
    {
        return $this->kit()->submitAll($records, $event);
    }

    /**
     * URLs the rules yield for many records, de-duplicated across the set.
     *
     * @param iterable<object> $records
     *
     * @return list<string>
     */
    public function urlsForAll(iterable $records, Event $event = Event::Updated): array
    {
        return $this->kit()->urlsForAll($records, $event);
    }

    /**
     * @return list<string>
     */
    public function urlsFor(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->urlsFor($record, $event);
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function explain(object $record, Event $event = Event::Updated): array
    {
        return $this->kit()->explain($record, $event);
    }

    /**
     * @param iterable<string> $urls
     */
    public function collect(iterable $urls): void
    {
        $this->kit()->collect($urls);
    }

    public function flush(): void
    {
        $this->kit()->flush();
    }

    /** Flush point: nothing collected means nothing built, so an idle request pays nothing. */
    public function flushIfCollected(): void
    {
        $this->services?->flushIfCollected();
    }

    // -- options -----------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function block(string $name): array
    {
        $block = $this->options[$name] ?? null;

        return \is_array($block) ? $block : [];
    }

    public function activeRecordEnabled(): bool
    {
        return (bool) ($this->block('active_record')['enabled'] ?? true) && $this->config()->enabled;
    }

    /**
     * @return list<class-string<BaseActiveRecord>>
     */
    public function modelClasses(): array
    {
        $models = $this->block('active_record')['models'] ?? [];
        $classes = [];
        foreach (\is_array($models) ? $models : [] as $class) {
            if (\is_string($class) && is_subclass_of($class, BaseActiveRecord::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function queueComponentId(): string
    {
        $id = $this->block('queue')['component'] ?? 'queue';

        return \is_string($id) && $id !== '' ? $id : 'queue';
    }

    public function queueExists(): bool
    {
        return class_exists(Queue::class) && App::current()->has($this->queueComponentId());
    }

    private function queue(): Queue
    {
        $queue = App::component($this->queueComponentId());
        if (!$queue instanceof Queue) {
            throw new InvalidConfigException(\sprintf('indexnow: component "%s" is not a yii\queue\Queue.', $this->queueComponentId()));
        }

        return $queue;
    }

    private function hookModels(): void
    {
        if (!$this->activeRecordEnabled()) {
            return;
        }
        foreach ($this->modelClasses() as $class) {
            $this->observer()->attachTo($class);
        }
    }

    private function registerKeyFileRule(\yii\web\Application $app): void
    {
        $keyFile = $this->block('key_file');
        $urlManager = $app->getUrlManager();
        if (!$this->config()->serveKeyFile || !$urlManager instanceof UrlManager || !$urlManager->enablePrettyUrl) {
            return;
        }
        $pattern = $keyFile['pattern'] ?? null;
        $urlManager->addRules([[
            'pattern' => \is_string($pattern) && $pattern !== '' ? $pattern : KeyFileController::DEFAULT_PATTERN,
            'route' => self::KEY_FILE_ROUTE,
            'suffix' => '',
        ]], false);
    }
}
