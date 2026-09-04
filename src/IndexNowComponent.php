<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\SitemapSpoolCheck;
use IndexNowKit\Client;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Result;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii2\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii2\Check\ActiveRecordCheck;
use IndexNowKit\Yii2\Check\CacheCheck;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Debounce\YiiCacheDebounceStore;
use IndexNowKit\Yii2\Http\KeyFileController;
use IndexNowKit\Yii2\Log\YiiLogger;
use IndexNowKit\Yii2\Queue\QueueDispatcher;
use IndexNowKit\Yii2\Url\ContainerResolverLocator;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use Psr\Http\Client\ClientInterface as PsrClient;
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
 * The `indexnow` application component: builds the core graph from `options`, hooks ActiveRecord (through
 * {@see ActiveRecord\IndexNowBehavior} or the `active_record.models` list), registers the key file route and the
 * console controller, and flushes the collector when the response has been sent.
 *
 *   'bootstrap' => ['indexnow'],
 *   'components' => ['indexnow' => ['class' => IndexNowComponent::class, 'options' => ['key' => getenv('INDEXNOW_KEY'), 'base_url' => 'https://www.example.com']]],
 *
 * Every core piece is replaceable through a property (`transport`, `debounceStore`, `dispatcher`, `urlResolver`,
 * `logger`, `checks`), given as an instance, a class name or a component id (`Instance::ensure`).
 */
final class IndexNowComponent extends Component implements BootstrapInterface
{
    public const CONTROLLER_ID = 'indexnow';
    public const KEY_FILE_CONTROLLER_ID = 'indexnow-key-file';
    public const KEY_FILE_ROUTE = self::KEY_FILE_CONTROLLER_ID . '/index';

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
    private ?IndexNowKit $kit = null;
    private ?RuleRegistry $rules = null;
    private ?TransportInterface $builtTransport = null;
    private ?KeyProviderInterface $keys = null;
    private ?UrlNormalizerInterface $normalizer = null;
    private ?ThrottleInterface $throttle = null;
    private ?DebounceStoreInterface $builtDebounce = null;
    private ?SubmitterInterface $submitter = null;
    private ?CollectorInterface $collector = null;
    private ?DispatcherInterface $builtDispatcher = null;
    private ?RouteUrlResolverInterface $routeResolver = null;
    private ?GuardedUrlResolver $guardedResolver = null;
    private ?IndexNowObserver $observer = null;
    private ?VerifyingStaging $staging = null;
    private ?SitemapSourceInterface $sitemap = null;
    private ?CheckerInterface $checker = null;
    private ?KeyFileResponder $keyFileResponder = null;

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

    public function kit(): IndexNowKit
    {
        if ($this->kit === null) {
            $config = $this->config();
            $this->kit = new IndexNowKit(
                config: $config,
                submitter: $this->submitter(),
                collector: $this->collector(),
                dispatcher: $this->dispatcher(),
                keys: $this->keys(),
                attributes: $this->rules(),
                resolver: $this->guardedResolver(),
                logger: $this->logger(),
                transport: $this->transport(),
                sitemap: ($this->block('sitemap')['enabled'] ?? true) === false ? null : $this->sitemapSource(),
            );
        }

        return $this->kit;
    }

    public function rules(): RuleRegistry
    {
        return $this->rules ??= new RuleRegistry(new AttributeReader());
    }

    public function keys(): KeyProviderInterface
    {
        return $this->keys ??= StaticKeyProvider::fromConfig($this->config());
    }

    public function transport(): TransportInterface
    {
        if ($this->builtTransport === null) {
            if ($this->transport !== null) {
                $transport = Instance::ensure($this->transport, TransportInterface::class);
                \assert($transport instanceof TransportInterface);
                $this->builtTransport = $transport;
            } else {
                $client = $this->block('http')['client'] ?? null;
                $this->builtTransport = new LazyTransport(function () use ($client): TransportInterface {
                    $timeout = $this->config()->httpTimeout;
                    if (!\is_string($client) || $client === '') {
                        return Psr18Transport::discover(timeout: $timeout);
                    }
                    $instance = App::component($client) ?? Yii::$container->get($client);
                    if (!$instance instanceof PsrClient) {
                        throw new ConfigurationException(\sprintf('http.client "%s" resolves to %s, which is not a PSR-18 client.', $client, get_debug_type($instance)));
                    }

                    return Psr18Transport::discover($instance, $timeout);
                });
            }
        }

        return $this->builtTransport;
    }

    public function normalizer(): UrlNormalizerInterface
    {
        return $this->normalizer ??= new UrlNormalizer($this->config()->baseUrl, $this->config()->maxUrlLength);
    }

    public function throttle(): ThrottleInterface
    {
        return $this->throttle ??= new TokenBucket($this->config()->throttleMaxRequestsPerMinute, logger: $this->logger());
    }

    public function debounceStore(): DebounceStoreInterface
    {
        if ($this->builtDebounce === null) {
            if ($this->debounceStore !== null) {
                $store = Instance::ensure($this->debounceStore, DebounceStoreInterface::class);
                \assert($store instanceof DebounceStoreInterface);
                $this->builtDebounce = $store;
            } else {
                $store = $this->block('debounce')['store'] ?? 'cache';
                $store = \is_string($store) && $store !== '' ? $store : 'cache';
                $this->builtDebounce = match ($store) {
                    'memory' => new MemoryDebounceStore(),
                    'none' => new NullDebounceStore(),
                    default => new YiiCacheDebounceStore(Instance::ensure($store, CacheInterface::class), $this->config()->debounceKeyPrefix),
                };
            }
        }

        return $this->builtDebounce;
    }

    public function submitter(): SubmitterInterface
    {
        return $this->submitter ??= new Submitter(new Client($this->transport(), $this->keys(), $this->config(), $this->logger(), $this->throttle(), $this->normalizer()), $this->config(), $this->debounceStore(), $this->logger(), $this->normalizer());
    }

    public function collector(): CollectorInterface
    {
        return $this->collector ??= new Collector($this->logger(), $this->config()->collectorDetectLeaks, $this->config()->logUrls);
    }

    public function dispatcher(): DispatcherInterface
    {
        if ($this->builtDispatcher === null) {
            if ($this->dispatcher !== null) {
                $dispatcher = Instance::ensure($this->dispatcher, DispatcherInterface::class);
                \assert($dispatcher instanceof DispatcherInterface);
                $this->builtDispatcher = $dispatcher;
            } else {
                $config = $this->config();
                $queue = $this->block('queue');
                $this->builtDispatcher = match (true) {
                    !$config->enabled || $config->dispatch === 'none' => new NullDispatcher(),
                    $config->dispatch === 'queue' => new QueueDispatcher(fn(): Queue => $this->queue(), $config, $this->logger(), self::intOf($queue['ttr'] ?? null, 300), self::intOf($queue['delay'] ?? null, 0), \is_int($queue['priority'] ?? null) || \is_string($queue['priority'] ?? null) ? $queue['priority'] : null),
                    default => new SyncDispatcher($this->submitter(), $this->logger(), $config->logUrls),
                };
            }
        }

        return $this->builtDispatcher;
    }

    public function routeResolver(): RouteUrlResolverInterface
    {
        if ($this->routeResolver === null) {
            $router = $this->block('router');
            $languages = \is_array($router['languages'] ?? null) ? array_values(array_filter($router['languages'], 'is_string')) : [];
            $parameter = $router['language_parameter'] ?? 'language';
            $this->routeResolver = new YiiRouteUrlResolver($this->config(), $languages, \is_string($parameter) && $parameter !== '' ? $parameter : 'language', (bool) ($router['set_app_language'] ?? true));
        }

        return $this->routeResolver;
    }

    public function guardedResolver(): GuardedUrlResolver
    {
        if ($this->guardedResolver === null) {
            if ($this->urlResolver !== null) {
                $resolver = Instance::ensure($this->urlResolver, UrlResolverInterface::class);
                \assert($resolver instanceof UrlResolverInterface);
            } else {
                $config = $this->config();
                $resolver = new AttributeUrlResolver($this->rules(), $this->routeResolver(), new ContainerResolverLocator(), $this->logger(), $config->resolverMaxViaDepth, $config->resolverMaxViaFanout, $config->localeHosts);
            }
            $this->guardedResolver = new GuardedUrlResolver($resolver, $this->rules(), $this->logger());
        }

        return $this->guardedResolver;
    }

    public function staging(): VerifyingStaging
    {
        return $this->staging ??= new VerifyingStaging($this->logger(), $this->config()->logUrls);
    }

    public function observer(): IndexNowObserver
    {
        return $this->observer ??= new IndexNowObserver($this->kit(), $this->staging(), $this->logger(), $this->activeRecordEnabled());
    }

    public function sitemapSource(): SitemapSourceInterface
    {
        if ($this->sitemap === null) {
            $sitemap = $this->block('sitemap');
            $spool = SpoolMode::tryFrom(\is_string($sitemap['spool'] ?? null) ? $sitemap['spool'] : 'auto') ?? SpoolMode::Auto;
            $dir = $sitemap['spool_dir'] ?? null;
            $this->sitemap = new SitemapReader(
                $this->transport(),
                self::intOf($sitemap['max_depth'] ?? null, 3),
                $this->logger(),
                self::intOf($sitemap['max_sitemaps'] ?? null, SitemapReader::MAX_SITEMAPS),
                self::intOf($sitemap['max_bytes'] ?? null, SitemapReader::MAX_XML_BYTES),
                (bool) ($sitemap['allow_foreign_hosts'] ?? false),
                $spool,
                \is_string($dir) && $dir !== '' ? $dir : null,
                self::intOf($sitemap['fetch_retries'] ?? null, 2),
            );
        }

        return $this->sitemap;
    }

    public function keyFileResponder(): KeyFileResponder
    {
        return $this->keyFileResponder ??= new KeyFileResponder($this->keys(), $this->config()->serveKeyFile);
    }

    public function checker(): CheckerInterface
    {
        if ($this->checker === null) {
            $checks = [
                new QueueCheck($this->options, $this->config()->dispatch, $this->queueExists()),
                new CacheCheck($this->options),
                new UrlManagerCheck($this->options),
                new ActiveRecordCheck($this->activeRecordEnabled(), $this->modelClasses()),
                new SitemapSpoolCheck($this->block('sitemap')),
            ];
            foreach ($this->checks as $check) {
                $instance = Instance::ensure($check, CheckInterface::class);
                \assert($instance instanceof CheckInterface);
                $checks[] = $instance;
            }
            $this->checker = new Checker($this->config(), $this->keys(), $this->transport(), $checks);
        }

        return $this->checker;
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
        return $this->kit()->submit($this->urlsForAll($records, $event));
    }

    /**
     * @param iterable<object> $records
     *
     * @return list<string>
     */
    public function urlsForAll(iterable $records, Event $event = Event::Updated): array
    {
        $resolved = [];
        foreach ($records as $record) {
            $resolved = [...$resolved, ...$this->kit()->explain($record, $event)];
        }

        return ResolvedUrl::urls($resolved);
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
        if ($this->kit === null || $this->kit->collector->isEmpty()) {
            return;
        }
        try {
            $this->kit->flush();
        } catch (Throwable $e) {
            $this->logger()->error('indexnow: flush failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
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
        $enabled = \is_bool($this->options['serve_key_file'] ?? null) ? $this->options['serve_key_file'] : (bool) ($keyFile['enabled'] ?? true);
        $urlManager = $app->getUrlManager();
        if (!$enabled || !$urlManager instanceof UrlManager || !$urlManager->enablePrettyUrl) {
            return;
        }
        $pattern = $keyFile['pattern'] ?? null;
        $urlManager->addRules([[
            'pattern' => \is_string($pattern) && $pattern !== '' ? $pattern : KeyFileController::DEFAULT_PATTERN,
            'route' => self::KEY_FILE_ROUTE,
            'suffix' => '',
        ]], false);
    }

    private static function intOf(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
