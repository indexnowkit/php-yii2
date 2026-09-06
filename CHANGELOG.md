# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [0.12.0] — Unreleased

### Changed

- **The `router` block speaks the core's vocabulary**: `router.locales`, `router.locale_parameter`, `router.set_app_locale`
  (what the attribute calls `locales`, what `ResolvedUrl::$locale` and `locale_hosts` are, and what the Laravel adapter has
  always used). The pre-0.12 spellings `router.languages`, `router.language_parameter` and `router.set_app_language` are
  still read, each with a deprecation warning in the log naming the new key; they go in the next minor. The default GET
  parameter stays `language` (the Yii convention).
- **Console output goes through `Controller::stdout()`**: the runners wrote to `php://stdout` directly through a
  `ConsoleOutput`, past `--color`, `isColorEnabled()` and any controller that redirects or captures `stdout()`. The
  default output is now `Console\ControllerOutput` (a symfony/console `Output` over the controller: decorated exactly
  when Yii would colour, every write through `stdout()`); an injected `$output` (tests) is untouched. symfony/console
  stays a requirement: the runners of indexnowkit/console are written against its `OutputInterface`, as Laravel's and
  Yii3's consoles are.
- `IndexNowComponent::submitRecords()` delegates to `IndexNowKit::submitEntities()` (core 0.12.0).
- Requires `indexnowkit/core ^0.12`.

## [0.11.0] — 2026-09-07

### Changed

- **`Queue\QueueDispatcher` pushes one job per `batch.max_urls` URLs**: a bulk import was one job a queue payload limit
  rejected, and every URL was lost with one log line.
- **`Cache\Psr16Cache`** keeps a stored `false` apart from a miss (an envelope around the value: entries written by 0.10
  read as misses once, then are rewritten) and throws the PSR-16 `InvalidArgumentException` (`Cache\InvalidKey`) for a
  key with reserved characters or over 64 characters.
- `check` gets `verify.transport`; the `verify.dispatch` line comes from `Verify\Check\DispatchCheck`.
- README: a "Verify" step (`php yii indexnow/check`) after the model declaration, as the other adapters have.
- `psr/log ^1.1` is gone from the constraint (the core requires `^2 || ^3`).
- Requires `indexnowkit/core ^0.11`, `indexnowkit/console ^0.4`; tests against `verify ^0.2`, `history ^0.2`, `sitemap ^0.6`.

## [0.10.0] — 2026-09-06

### Added

- The `paramExtractor` node of the component's graph: `new ParamExtractor(new ActiveRecordSubjectReader())`, shared by the
  resolver, the change handler, the facade and `indexnow/explain` (`Adapter\ServicesBuilder::paramExtractor()`).

### Changed

- Requires `indexnowkit/core ^0.10`; the component no longer registers the reader through the removed static
  `ParamExtractor::registerReader()` (the same accessors resolve the same way; the process-wide flag is gone).

## [0.9.0] — 2026-09-06

### Added

- **`indexnowkit/verify` wiring** (spec 17 §6.1). The `verify` block of the component options (`enabled`, `redirect`,
  `non_canonical`, `origin_error`, `delay`, `timeout`, `max_redirects`, `max_batch`, `robots_cache_ttl`, `user_agent`);
  with the package and `verify.enabled: true` the graph's submitter is `VerifyingSubmitter` and the commands'
  factory `VerifyingSubmitterFactory` (`Verify\VerifyServices`, `Wiring`), so sync flushes, yii2-queue jobs and the
  commands verify. Component: `verifyInstalled` (predicate override), `verifyTransport` (the pre-flight transport
  override), `samples`; `verifyPackage()`, `verifyInstalled()`, `verifyConfig()`, `verifyEnabled()`,
  `verifyTransport()`, `robots()`, `submitterFactory()`, `unverifiedSubmitterFactory()`, `events()`. `check` lines:
  `verify.installed`, `verify.dispatch` (warning with `dispatch: sync`), `verify.sample`. Without the package the
  block is ignored as a whole (`check` says so), `--sample` is an error naming the install line and `verifyConfig()`
  throws the install line.
- **`indexnow/check --sample=a,b` / `--sample-class=Class,Class:id`** (comma-separated; `Check\SampleOptions`,
  `Check\VerifySampleCheck`, `Check\RecordSampler` over the controller's loader). A URL with a comma cannot be given.
- **`indexnow/config --json`** prints the `verify` section when the package is installed.
- **`indexnowkit/history` wiring** (spec 17 §6.2). The `history` block of the component options (`store`, `limit`,
  `key_prefix`, `pdo.dsn`, `pdo.service`, `pdo.table`, `retention_days`); with the package and `history.store:
  psr16|pdo` the graph's submission store is the package's (`History\HistoryServices`, `Wiring`): `Psr16SubmissionStore`
  over the cache component of `debounce.store` (the `cache` component with `memory`/`none`) through `Cache\Psr16Cache`,
  or `PdoSubmissionStore` over the PDO of the `db` component named by `pdo.service` / a PDO built from `pdo.dsn` —
  never both — so sync flushes, yii2-queue jobs, the commands and the verify decorator record into it; the
  `submissionStore` property still wins. The table is not created (see the package's `docs/migrations.md`).
  Component: `historyInstalled` (predicate override), `historyPackage()`, `historyInstalled()`, `historyConfig()`,
  `historyEnabled()`. Commands **`indexnow/history`** (`--host`, `--status`, `--url`, `--since`, `--limit`, `--json`,
  `--purge[=days]`; `Console\HistoryAction`) and **`indexnow/status`** (`--json` per the package's `status.schema.json`;
  the queue component and its class as the adapter facts); without the package both print the install line and
  exit 1 (their options are still accepted) and `historyConfig()` throws. `check` lines: `history.installed`
  (without the package), `history.store`, `history.records`. `indexnow/config --json` prints the `history` section.
- `Config\ConfigFactory::factory()/create()/build()` take an appended `?bool $verifyInstalled = null` and
  `?bool $historyInstalled = null`.

### Changed

- Requires `indexnowkit/core ^0.9`, `indexnowkit/console ^0.3` and (dev/suggest) `indexnowkit/sitemap ^0.5`,
  `indexnowkit/verify ^0.1`, `indexnowkit/history ^0.1`.
- `IndexNowController` submits `--force` / `--dry-run` through `IndexNowComponent::submitterFactory()` (decorated
  when verify is on) instead of the graph's plain factory; the `submitters` property still overrides it.

## [0.8.0] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.8`, `indexnowkit/console ^0.2` and (dev/suggest) `indexnowkit/sitemap ^0.4`. Core 0.8 strips tracking parameters by default (`normalizer.strip_tracking_params`) and makes `Equals` in `params` an error (see the core changelog).

### Added

- The `indexnow/check` lines of the adapter carry stable codes (core 0.8, `check --json`): `queue.dispatch`,
  `queue.component`, `queue.driver`, `active_record.enabled`, `url_manager.key_file`, `url_manager.pretty_url`,
  `url_manager.rule`, plus the core's `debounce.store` and `sitemap.installed`. Listed in the core's `docs/check-codes.md`.
- `php yii indexnow/check --json` (the report as JSON, schema `docs/check.schema.json` of `indexnowkit/console`),
  `--strict` (warnings fail the command: put it in the deploy pipeline) and `--host=a,b` (a list; console 0.2).
- `php yii indexnow/key-generate --force` keeps the replaced key as `INDEXNOW_PREVIOUS_KEY` and refuses a second rotation
  while it is set; `--no-previous` and `--yes` decide (console 0.2).
- The 403 escalation of `Client` counts in the cache component behind `debounce.store` (core 0.8) through the new
  `Cache\Psr16Cache` (a `yii\caching\CacheInterface` as PSR-16): one `critical` line per streak for every worker;
  `IndexNowComponent::failureCache()` returns it (null with `memory`/`none` or a custom `debounceStore`).
- Component property `submissionStore` (instance, class, configuration array or component id of a
  `Submission\SubmissionStoreInterface`; default: nothing is recorded): the store the submitter and the command
  submitters record every `Result` in (core 0.8); `IndexNowComponent::submissionStore()` returns it.
- Options block `normalizer` (`strip_tracking_params`, `tracking_params`, `trailing_slash`, `sort_query`): the canonical
  form of every URL (core 0.8), read through `Config::OPTIONS`. Tracking parameters are stripped by default.
- `php yii indexnow/explain --json` and the `when` values in the text output (console 0.2).
- `php yii indexnow/config` (`--json`): the effective configuration with masked keys plus the component's own option
  blocks (console 0.2).
- **`IndexNowComponent::EVENT_RESULT`** (spec 17 §5.7): the component triggers an `Event\ResultEvent` (`$result`) for
  every `Result` of the submitter and of the command submitters, through the PSR-14 `Event\ResultDispatcher` the
  wiring gives the core.

### Changed

- **`indexnow/*` console actions take `-v`, `-vv`, `-vvv` (and `SHELL_VERBOSITY`) as symfony/console does; the own
  `--verbose` option is gone.** Migration: `php yii indexnow/sitemap --verbose` → `php yii indexnow/sitemap -v`.

## [0.7.0] — 2026-09-06

### Changed

- Requires core 0.7: `Console\SubmitterFactory` / `Console\SubmitterFactoryInterface` are now
  `IndexNowKit\Adapter\SubmitterFactory` / `IndexNowKit\Adapter\SubmitterFactoryInterface`, `Console\ResultSummary` is
  `IndexNowKit\Submission\ResultSummary`. Application code that names them (a decorator of the `submitters` property of `IndexNowController`) changes the `use` line; nothing else.
- The test suite requires `indexnowkit/testing ^0.1` (`require-dev`): the conformance kits and the H01–H05 assertions
  moved there from the core (`Testing\Conformance\KeyFileAssertions`, `CheckOutputAssertions`, `ReadmeAssertions`).
- Requires `indexnowkit/console ^0.1`: the runners and definitions the console controller are built on moved there from the core
  with their FQCN unchanged (`IndexNowKit\Console\*`); Composer installs it with this package, nothing to do.
- `Sitemap\SitemapSupport` (the `@internal` predicate with its static override) is gone: the component exposes
  `sitemapPackage()` (an `IndexNowKit\Adapter\OptionalPackage`) and the new property `sitemapInstalled: ?bool`
  (`null` detects, `false` runs as if the package were absent — tests set it in the component configuration).
  `Config\ConfigFactory::factory()`, `create()` and `build()` take an appended `?bool $sitemapInstalled = null`. The
  `check` line for a configured but ignored `sitemap` block is a warning now (it was ok). The invalid-block critical
  line now ends with `(run "php yii indexnow/check")` (`SitemapConfig::loadOrDisabled()`).

### Documentation

- README: the quick-start record is `tests/Readme/Post.php` verbatim (complete `use` lines, the `category` relation the
  `via:` rule reads, casts/defaults that make it run); `ReadmeQuickstartTest` compares the README block with the file
  and runs the record through the test application against the FakeTransport.
- README: "Notes for AI assistants" (package, minimal complete snippet, verification, pitfalls across the adapters);
  `ReadmeAiNotesTest` keeps it consistent with the commands and configuration keys.
- README "Operations": the production checklist first, then monitoring rules, deleted pages, what not to submit,
  multi-domain, queue, commit safety and troubleshooting.
- docs/configuration.md: the full core key table with defaults (parity with the Laravel adapter);
  docs/testing.md: URLs without HTTP, transactions and verify-on-commit, queue, dry_run, conformance;
  docs/multi-domain.md (new): hosts, rules on another host, languages, origin of generated URLs, www and apex,
  hreflang; docs/troubleshooting.md: "Sent, but the engine answers" (403/422/429/202), "Duplicates, timing",
  "Staging submitted its URLs", "Duplicates with `memory` and several workers".
- Russian translation: docs/troubleshooting.ru.md (linked from README.ru.md).
- `homepage` in composer.json points at the docs site (https://indexnowkit.github.io/php/).

## [0.6.0] — 2026-09-05

Wave 0a of docs/spec/17 with core 0.6.0. **`indexnow/check` fails when `YII_ENV` (or the component's `environment`)
is not in `production_environments`, a key is configured and `dry_run` is not set** (a staging copy with the
production key submits real URLs). A staging or preview environment that submits on purpose sets
`'dry_run' => false` in the options and gets a warning instead.

### Changed

- Requires `indexnowkit/core ^0.6`; `indexnowkit/sitemap ^0.2` when installed.

### Added

- `internetarchive` and `amazon` accepted in `engines` (core 0.6).

### Fixed

- `"app\models\Post" is not an ActiveRecord class` names the base class the command expects; the verify-on-commit
  message for a record without a primary key says what to do and what happens meanwhile.

### Documentation

- README: the component configuration comes before `key-generate` / `check` (the controller is registered by
  `bootstrap()`); "Why this over X", "Notification, not indexing", the issues link. [docs/bc.md](docs/bc.md):
  component options and properties, console actions, bootstrap ids, behavior, job.

## [0.5.0] — 2026-09-05

`Retry-After` and the `retry.*` backoff are honoured by the yii2-queue job, which re-pushes the rejected URLs itself.
Migration: **`SubmitUrlsJob` no longer throws on 429/5xx**, so the retry settings of the queue (`attempts`, `ttr`
of the driver) do not limit them any more; set `retry.max_attempts` and the `retry.*` delays in the component
options instead. Nothing else changes for a 403 (still logged, no retry).

### Changed

- **`Queue\SubmitUrlsJob` re-pushes retryable failures instead of throwing.** After a 429, 5xx or network failure the
  job pushes a new `SubmitUrlsJob` with the rejected URLs, the same `id`, `attempt + 1` and the delay of the core's
  `Retry\RetryPolicy` built from `retry.*` (`Retry-After` first), then ends successfully; at `retry.max_attempts` it
  logs `giving up on N URL(s) of job <id>` at error and ends. New public property `attempt` (1 from the dispatcher).
  `canRetry()` keeps its previous behaviour for exceptions (`RetryableSubmissionException` within `maxAttempts`), the
  job itself no longer throws it. The sync driver ignores the delay: the attempts run back-to-back in one `run()`;
  `indexnow/check` says so. [docs/queue.md](docs/queue.md).

- Internal refactor, no API change: the `Adapter\ServicesBuilder` description of the graph and the `check` lines
  moved from `IndexNowComponent` to `Wiring`, the resolution of the override properties to `References`. The
  component keeps every public method and property; only relevant if you extend the component's internals.

### Fixed

- `php yii help indexnow/<action>` describes the options with the texts and defaults of the core's
  `Console\Definitions` (and `Sitemap\Console\Definitions`), the same the bundle and artisan print:
  `IndexNowController::getActionOptionsHelp()` reads them from the definitions instead of the property docblocks of
  the controller. `sitemap` without `indexnowkit/sitemap` keeps Yii's help.

## [0.4.0] — 2026-09-05

`indexnowkit/sitemap` is optional again (docs/spec/16, wave C): the package suggests it instead of requiring it.
Options, commands and the component's properties do not change.

### Added

- `IndexNowComponent::sitemapInstalled()`: whether the optional `indexnowkit/sitemap` is installed.

### Changed

- **`indexnowkit/sitemap` is no longer installed automatically.** If you use `indexnow/sitemap`, run
  `composer require indexnowkit/sitemap`; otherwise, after `composer update`, the command reports that the package is
  missing and exits with code 1. Requires `indexnowkit/core ^0.5.1`.
- Without the package: `indexnow/sitemap` (its options still accepted) prints `indexnowkit/sitemap is not installed:
  composer require indexnowkit/sitemap` and exits 1; `indexnow/check` prints `sitemap: not installed (composer require
  indexnowkit/sitemap)`, or `sitemap: not installed, the sitemap block in the configuration is ignored (…)` when the
  options carry a `sitemap` block; `Config\ConfigFactory` ignores that block as a whole (no "unknown option" warning);
  `IndexNowComponent::sitemapConfig()` and `sitemapSource()` throw a `LogicException` with the install line before
  touching the package's types. Nothing is logged at bootstrap or on a request.
- The sitemap pieces moved to `Sitemap\SitemapServices` and `Console\SitemapAction`, used only when
  `Sitemap\SitemapSupport::installed()` holds (the predicate; `@internal` `SitemapSupport::$installed` forces it in
  tests). Only relevant if you reach into the component or the controller yourself.

## [0.3.0] — 2026-09-05

The core 0.5 "adapter kit" release, second wave: the component describes its graph with `Adapter\ServicesBuilder`,
the observer, the queue job and the console controller are built on `Hook\ObserverHelper`, `Retry\WorkerOutcome`
and `Console\Definitions`. Options, commands and the component's properties do not change.

### Added

- `IndexNowComponent::services()`: the lazy `Adapter\Services` graph the component describes once (the properties
  `transport`, `debounceStore`, `dispatcher`, `urlResolver`, `checks` are its overrides). The graph methods
  (`kit()`, `transport()`, `debounceStore()`, `submitter()`, `dispatcher()`, `checker()`, …) are delegates.

### Changed

- Requires `indexnowkit/core ^0.5` and `indexnowkit/sitemap ^0.1.1`.
- `ActiveRecord\IndexNowObserver` on `Hook\ObserverHelper`: what is Yii's stays (change set, previous state, the
  verify-on-commit staging); the log line for a resolve failure before a deletion is now the helper's
  `indexnow: cannot resolve the URLs of {class}: {error}` (was "... before deletion: ...").
- `Queue\SubmitUrlsJob` on `Retry\WorkerOutcome`: same behaviour (`canRetry()` up to `retry.max_attempts`, no delay
  from the job), the retry line now reads `indexnow: {count} URL(s) of job {id} will be retried` (was "... were
  not accepted and will be retried by the queue").
- `Console\IndexNowController::options()` / `optionAliases()` come from `Console\Definitions` /
  `Sitemap\Console\Definitions`: the same option names and shortcuts as the bundle and artisan.
- Tests: H01–H05 assert through the core's `Testing\KeyFileAssertions` and `Testing\CheckOutputAssertions`.

## [0.2.0] — 2026-09-05

The core 0.4 "adapter kit" release: the component is built on the core's factories and `Adapter\ConfigFactory`,
and the sitemap reader is `indexnowkit/sitemap` (required by this package, installed transitively). Options,
commands and the component's properties do not change.

### Added

- `docs/troubleshooting.md` and a Debugging section in the README.
- `IndexNowComponent::sitemapConfig()`: the validated `sitemap` block; an invalid one is logged at `critical` and
  disables `php yii indexnow/sitemap` (`sitemap.enabled is false.`, exit 2).

### Changed

- Requires `indexnowkit/core ^0.4` and `indexnowkit/sitemap ^0.1`. The sitemap classes moved:
  `IndexNowKit\Sitemap\*` keep their names, `Console\SitemapRunner`/`SitemapOptions` are `Sitemap\Console\*`,
  `Check\SitemapSpoolCheck` is `Sitemap\Check\SitemapSpoolCheck`. `IndexNowKit::sitemap()` is gone:
  `IndexNowComponent::sitemapSource()` stays.
- `Config\ConfigFactory` is a declaration of the core's `Adapter\ConfigFactory` (`dispatch: auto` resolved by the
  queue component, "queue component is not configured" post-check); `coreOptions()` is gone. A typo inside
  `key_file`/`sitemap` (`key_file.enabld`) is warned about again.
- `Check\CacheCheck` is the core's `Check\DebounceStoreCheck` with `Check\CacheProbe`; `Url\ContainerResolverLocator`
  is the core's `ArrayResolverLocator(locate:)`; both classes are removed. `ActiveRecordLoader` delegates to
  `Console\ClassNameResolver`.
- `IndexNowComponent::submitRecords()`/`urlsForAll()` delegate to `IndexNowKit::submitAll()`/`urlsForAll()`;
  `KeyFileController` sends `Config::keyFileHeaders()`.
- Dev tooling: phpstan runs on the `lowest` flavour too; phpstan floors are the current releases.

## [0.1.0] — 2026-09-04

First release. Yii2 ≥ 2.0.45, PHP 8.2–8.5, `indexnowkit/core ^0.3.1`.

### Added

- **`IndexNowComponent`** (`components.indexnow` + `bootstrap`): builds the core graph from `options` (the family-wide
  configuration tree plus the Yii blocks `queue`, `key_file`, `router`, `active_record`, `sitemap`, `debounce.store`,
  `http.client`, `logging.category`), registers the key file URL rule and controller, the console controller and the
  flush points (`Response::EVENT_AFTER_SEND`, console `EVENT_AFTER_REQUEST`, yii2-queue `EVENT_AFTER_EXEC`/`EVENT_AFTER_ERROR`).
  Every piece is replaceable through a property (`transport`, `debounceStore`, `dispatcher`, `urlResolver`, `logger`, `checks`).
- **`ActiveRecord\IndexNowBehavior`** and the `active_record.models` list: `#[IndexNow]` rules on ActiveRecord classes,
  URLs resolved in the event while the old state is live (`AfterSaveEvent::$changedAttributes`, `EVENT_BEFORE_DELETE`),
  renamed pages (A21), `via` relations, `self` = primary key.
- **Verify-on-commit.** Yii2 has no savepoint events: changes made inside a transaction are staged
  (core `Transaction\VerifyingStaging`) and re-read by primary key on `EVENT_COMMIT_TRANSACTION`; a change the row
  does not show (savepoint rolled back) is dropped with every URL it produced. `EVENT_ROLLBACK_TRANSACTION` discards.
- `ActiveRecordSubjectReader` (attributes and relations behind `__get()`), `YiiRouteUrlResolver`
  (`UrlManager::createAbsoluteUrl`, `base_url` in console, pinned hosts, `router.language_parameter`),
  `YiiCacheDebounceStore`, `YiiLogger` (PSR-3 over `Yii::getLogger()`, category `logging.category`).
- Console: `php yii indexnow/check|submit|submit-record|explain|sitemap|key-generate` over the core command bodies.
- `dispatch: auto` (default): `queue` when the yii2-queue component exists, else `sync`. `Queue\SubmitUrlsJob`
  (`RetryableJobInterface`, retries within `retry.max_attempts`), `Queue\QueueDispatcher`.
- Checks for `indexnow/check`: queue component, cache component, pretty URLs and the key file rule, ActiveRecord hooks,
  sitemap spool.

[0.1.0]: https://github.com/indexnowkit/php-yii2/releases/tag/0.1.0
