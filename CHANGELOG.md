# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [Unreleased]

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
