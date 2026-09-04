<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\SubmitterFactory;
use IndexNowKit\Console\SubmitterFactoryInterface;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Yii2\ActiveRecord\ActiveRecordLoader;
use IndexNowKit\Yii2\App;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\IndexNowComponent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\di\Instance;

/**
 * `php yii indexnow/<action>`: the console commands, over the shared command bodies of the core (`Console\*Runner`).
 * Registered by the component under the `indexnow` controller id; output goes through symfony/console so every
 * framework prints the same thing.
 *
 *   php yii indexnow/check --live
 *   php yii indexnow/submit /a https://www.example.com/b --dry-run
 *   php yii indexnow/submit-record Post 1 2 --explain
 *   php yii indexnow/explain Post 1
 *   php yii indexnow/sitemap --changed-since="1 day"
 *   php yii indexnow/key-generate --write-env
 */
final class IndexNowController extends Controller
{
    public $defaultAction = 'check';

    /** Component id of {@see IndexNowComponent}. */
    public string $component = 'indexnow';

    /** @var SubjectLoaderInterface|array<string, mixed>|string|null how submit-record/explain find records (default: ActiveRecordLoader over app\models) */
    public mixed $loader = null;

    /** @var ResultFormatterInterface|array<string, mixed>|string|null table/JSON rendering of results */
    public mixed $formatter = null;

    /** @var SubmitterFactoryInterface|array<string, mixed>|string|null the separate submitter --force/--dry-run use */
    public mixed $submitters = null;

    /** @var list<string> namespaces a short class name is looked up in */
    public array $modelNamespaces = ['app\\models'];

    /** Output the runners write to (tests inject a BufferedOutput). */
    public ?OutputInterface $output = null;

    // options
    public bool $force = false;
    public bool $dryRun = false;
    public bool $json = false;
    public bool $live = false;
    public ?string $host = null;
    public ?string $probeUrl = null;
    public string $event = 'updated';
    public int|string $limit = 1000;
    public bool $explain = false;
    public ?string $changedSince = null;
    public bool $allowForeignHosts = false;
    public int|string $length = 32;
    public bool $alphanumeric = false;
    public mixed $writeEnv = null;
    public bool $verbose = false;

    public function options($actionID): array
    {
        $common = ['verbose'];

        return array_merge(parent::options($actionID), match ($actionID) {
            'check' => [...$common, 'live', 'host', 'probeUrl'],
            'submit' => [...$common, 'force', 'dryRun', 'json'],
            'submit-record' => [...$common, 'event', 'limit', 'explain', 'force', 'dryRun', 'json'],
            'explain' => [...$common, 'event'],
            'sitemap' => [...$common, 'changedSince', 'allowForeignHosts', 'force', 'dryRun', 'json'],
            'key-generate' => [...$common, 'length', 'alphanumeric', 'writeEnv', 'force'],
            default => $common,
        });
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return parent::optionAliases() + ['f' => 'force', 'l' => 'length', 'v' => 'verbose'];
    }

    /** Validate the configuration, verify the key file is reachable, report how submissions are wired. */
    public function actionCheck(): int
    {
        $component = $this->component();
        $runner = new CheckRunner($component->checker(), $this->words());

        return $runner->run($this->io(), fn(): mixed => ConfigFactory::build($component->options, $component->environment ?? (\defined('YII_ENV') ? (string) \constant('YII_ENV') : 'prod'), $component->queueExists()), $this->live, $this->host, $this->probeUrl);
    }

    /**
     * Submit URLs to IndexNow immediately (synchronously, bypassing the queue).
     *
     * @param string ...$urls absolute URLs or paths relative to base_url
     */
    public function actionSubmit(string ...$urls): int
    {
        $component = $this->component();
        $runner = new SubmitRunner($component->kit(), $this->submitterFactory(), $this->formatter());

        return $runner->run($this->io(), array_values($urls), $this->force, $this->dryRun, $this->json);
    }

    /**
     * Resolve the URLs of ActiveRecord rows through their #[IndexNow] rules and submit them (the manual path after updateAll()/link()).
     *
     * @param string        $class record class (FQCN or a short name under app\models)
     * @param array<string> $ids   identifiers (space- or comma-separated); none = every record of the class up to --limit
     */
    public function actionSubmitRecord(string $class, array $ids = []): int
    {
        // Yii binds the first positional argument to $ids (comma-split) and appends the rest: accept both forms.
        $ids = array_values(array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', [...$ids, ...\array_slice(\func_get_args(), 2)]));
        $component = $this->component();
        $runner = new SubmitSubjectsRunner($component->kit(), $this->loader(), $this->submitterFactory(), $this->formatter(), $this->words());

        return $runner->run($this->io(), new SubmitSubjectsOptions($class, $ids, $this->event, is_numeric($this->limit) ? (int) $this->limit : 1000, $this->explain, $this->force, $this->dryRun, $this->json));
    }

    /**
     * Explain what IndexNow would do for one record: rules, guards, URLs, key, debounce (sends nothing).
     */
    public function actionExplain(string $class, string $id): int
    {
        $component = $this->component();
        $runner = new ExplainRunner($component->kit(), $this->loader(), $component->config(), $component->keys(), $component->debounceStore(), $component->normalizer(), $this->words());

        return $runner->run($this->io(), $class, $id, $this->event);
    }

    /**
     * Submit every URL of a sitemap (or only those with lastmod after --changed-since).
     *
     * @param string|null $sitemap sitemap URL or local file (default: sitemap.url from the options, else <base_url>/sitemap.xml)
     */
    public function actionSitemap(?string $sitemap = null): int
    {
        $component = $this->component();
        $config = $component->sitemapConfig();
        if (!$config->enabled) {
            $this->io()->error('sitemap.enabled is false.');

            return ExitCode::INVALID;
        }
        $runner = new SitemapRunner($component->kit(), $component->sitemapSource(), $this->submitterFactory(), $config->url, $this->formatter(), sitemapUrlOption: 'sitemap.url');

        return $runner->run($this->io(), new SitemapOptions($sitemap, $this->changedSince, $this->allowForeignHosts, $this->force, $this->dryRun, $this->json));
    }

    /**
     * Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env).
     */
    public function actionKeyGenerate(): int
    {
        $envFile = match (true) {
            $this->writeEnv === null || $this->writeEnv === false => null,
            \is_string($this->writeEnv) && $this->writeEnv !== '' && $this->writeEnv !== '1' => $this->writeEnv,
            default => Yii::getAlias('@app') . '/.env',
        };

        return (new KeyGenerateRunner($this->words()))->run($this->io(), is_numeric($this->length) ? (int) $this->length : 32, !$this->alphanumeric, $envFile, $this->force);
    }

    private function component(): IndexNowComponent
    {
        $component = App::indexNow($this->component);
        if ($component === null) {
            throw new InvalidConfigException(\sprintf('Component "%s" is not an %s.', $this->component, IndexNowComponent::class));
        }

        return $component;
    }

    private function io(): SymfonyStyle
    {
        $output = $this->output ??= new ConsoleOutput($this->verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);

        return new SymfonyStyle(new ArrayInput([]), $output);
    }

    private function words(): Vocabulary
    {
        return new Vocabulary(
            subject: 'record',
            subjects: 'records',
            cli: 'php yii',
            submitSubjects: 'indexnow/submit-record',
            configLocation: 'the indexnow component options and INDEXNOW_* env vars',
            keyFileServedBy: 'once the component is bootstrapped and pretty URLs are on',
            check: 'indexnow/check',
            submit: 'indexnow/submit',
            explain: 'indexnow/explain',
        );
    }

    private function loader(): SubjectLoaderInterface
    {
        if ($this->loader === null) {
            return new ActiveRecordLoader($this->modelNamespaces);
        }
        $loader = Instance::ensure($this->loader, SubjectLoaderInterface::class);
        \assert($loader instanceof SubjectLoaderInterface);

        return $loader;
    }

    private function formatter(): ResultFormatterInterface
    {
        if ($this->formatter === null) {
            return new ResultRenderer();
        }
        $formatter = Instance::ensure($this->formatter, ResultFormatterInterface::class);
        \assert($formatter instanceof ResultFormatterInterface);

        return $formatter;
    }

    private function submitterFactory(): SubmitterFactoryInterface
    {
        if ($this->submitters === null) {
            $component = $this->component();

            return new SubmitterFactory($component->transport(), $component->keys(), $component->config(), $component->debounceStore(), $component->throttle(), $component->normalizer(), $component->logger());
        }
        $factory = Instance::ensure($this->submitters, SubmitterFactoryInterface::class);
        \assert($factory instanceof SubmitterFactoryInterface);

        return $factory;
    }
}
