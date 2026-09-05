<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Event;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Result;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii2\ActiveRecord\IndexNowObserver;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Http\KeyFileController;
use IndexNowKit\Yii2\Log\YiiLogger;
use IndexNowKit\Yii2\Sitemap\SitemapServices;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface as Psr16;
use Yii;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event as YiiEvent;
use yii\db\BaseActiveRecord;
use yii\di\Instance;
use yii\queue\Queue;
use yii\web\Response;
use yii\web\UrlManager;

/**
 * The `indexnow` application component: describes the core graph from `options` with `Adapter\ServicesBuilder`
 * ({@see Wiring}: the core's factories underneath, the Yii pieces as closures), hooks ActiveRecord (through
 * {@see ActiveRecord\IndexNowBehavior} or the `active_record.models` list), registers the key file route and the
 * console controller, and flushes the collector when the response has been sent.
 *
 *   'bootstrap' => ['indexnow'],
 *   'components' => ['indexnow' => ['class' => IndexNowComponent::class, 'options' => ['key' => getenv('INDEXNOW_KEY'), 'base_url' => 'https://www.example.com']]],
 *
 * Every core piece is replaceable through a property (`transport`, `debounceStore`, `dispatcher`, `urlResolver`,
 * `logger`, `checks`), given as an instance, a class name or a component id (`Instance::ensure`). The sitemap
 * pieces come from `Sitemap\SitemapServices` when the optional `indexnowkit/sitemap` is installed
 * (`Adapter\OptionalPackage`, {@see sitemapPackage()}); without it `indexnow/sitemap` prints one sentence and
 * `indexnow/check` one line.
 */
final class IndexNowComponent extends Component implements BootstrapInterface
{
    public const CONTROLLER_ID = 'indexnow';
    public const KEY_FILE_CONTROLLER_ID = 'indexnow-key-file';
    public const KEY_FILE_ROUTE = self::KEY_FILE_CONTROLLER_ID . '/index';
    /** The debounce store when `debounce.store` is unset: the `cache` application component. */
    public const DEFAULT_DEBOUNCE_STORE = 'cache';
    /**
     * Triggered on the component with an {@see Event\ResultEvent} for every `Result` the submitter produces (the commands'
     * submitters included): `Yii::$app->indexnow->on(IndexNowComponent::EVENT_RESULT, fn (ResultEvent $e) => ...)`.
     */
    public const EVENT_RESULT = 'indexnowResult';

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

    /** @var SubmissionStoreInterface|array<string, mixed>|string|null where the submitter records every Result (indexnowkit/history, or your own); default: nowhere */
    public mixed $submissionStore = null;

    /** @var list<CheckInterface|array<string, mixed>|string> extra checks for `php yii indexnow/check` */
    public array $checks = [];

    /** Environment name for `production_environments`; default YII_ENV. */
    public ?string $environment = null;

    /**
     * Whether `indexnowkit/sitemap` is installed; null (the default) detects it, false makes the component behave
     * as if the package were absent (tests, or a deployment that must not read sitemaps).
     */
    public ?bool $sitemapInstalled = null;

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
        return $this->config ??= ConfigFactory::create($this->options, $this->environment ?? (\defined('YII_ENV') ? (string) \constant('YII_ENV') : 'prod'), $this->queueExists(), $this->logger(), $this->sitemapInstalled());
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
     * The core graph (`Adapter\Services`) {@see Wiring} describes once: the properties of this component are the
     * overrides, the Yii pieces are closures, everything else comes from the core's factories. Nothing is built
     * before it is used.
     */
    public function services(): Services
    {
        return $this->services ??= (new Wiring($this))->builder()->build();
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

    /** The PSR-16 view of the `debounce.store` cache component the 403 counter of the client lives in; null for memory/none. */
    public function failureCache(): ?Psr16
    {
        return $this->services()->failureCache();
    }

    /** The `submissionStore` property resolved, null when none is configured (`Submission\NullSubmissionStore` behaviour). */
    public function submissionStore(): ?SubmissionStoreInterface
    {
        return $this->services()->submissionStore();
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

    /** The optional `indexnowkit/sitemap` behind its one predicate: the `sitemapInstalled` property, else detection. */
    public function sitemapPackage(): OptionalPackage
    {
        return SitemapServices::package($this->sitemapInstalled);
    }

    /** Whether the optional `indexnowkit/sitemap` is installed ({@see sitemapPackage()}). */
    public function sitemapInstalled(): bool
    {
        return $this->sitemapPackage()->installed();
    }

    private function requireSitemap(): void
    {
        if (!$this->sitemapInstalled()) {
            throw new LogicException($this->sitemapPackage()->notInstalledMessage());
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
