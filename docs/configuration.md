# Configuration

Everything lives in the `options` array of the `indexnow` component. Keys mirror the family-wide schema of
`indexnowkit/core` ([configuration.md there](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md));
the blocks marked **Yii** are handled by this package. Verify with `php yii indexnow/check`.

## Core keys

`enabled`, `key`, `previous_key`, `key_location`, `base_url`, `hosts`, `strict_hosts`, `environment` (default `YII_ENV`),
`production_environments` (`['prod', 'production']`), `max_url_length`, `engines`, `engine_aliases`, `locale_hosts`,
`dry_run`, `batch.max_urls`, `debounce.per_url` (600), `debounce.key_prefix`, `throttle.max_requests_per_minute`,
`http.timeout`, `http.user_agent`, `retry.*`, `resolver.*`, `collector.*`, `logging.{max_urls,forbidden_escalation,max_body,levels}`.

## Yii blocks

| Key | Default | Meaning |
|---|---|---|
| `dispatch` | `auto` | `auto` = `queue` when the queue component exists, else `sync`; or `sync`, `queue`, `none` |
| `queue.component` | `queue` | id of the `yiisoft/yii2-queue` component `SubmitUrlsJob` is pushed to |
| `queue.ttr` | `300` | time-to-reserve of the job (seconds) |
| `queue.delay` | `0` | delay before the first execution (seconds) |
| `queue.priority` | `null` | driver priority |
| `debounce.store` | `cache` | id of a `yii\caching\CacheInterface` component, `memory` (per process) or `none` |
| `http.client` | `null` | component id or class of a PSR-18 client (`null` = discover one) |
| `key_file.enabled` | `true` | register the URL rule and controller for `/<key>.txt` |
| `key_file.pattern` | `<key:[A-Za-z0-9-]{8,128}>.txt` | the URL rule pattern |
| `key_file.cache_max_age` | `300` | `Cache-Control: max-age` of the key file |
| `router.languages` | `[]` | languages generated for rules with `locales: 'all'` |
| `router.language_parameter` | `language` | GET parameter that carries the language (added whenever a rule has a locale) |
| `router.set_app_language` | `true` | switch `Yii::$app->language` while generating a locale's URL, restored afterwards |
| `active_record.enabled` | `true` | `false` = `IndexNowBehavior` and the `models` list are inert |
| `active_record.models` | `[]` | ActiveRecord classes hooked through class-level events (no behavior needed) |
| `sitemap.*` | | `enabled`, `url`, `max_depth`, `max_sitemaps`, `max_bytes`, `allow_foreign_hosts`, `spool` (auto\|disk\|memory), `spool_dir`, `fetch_retries` |
| `logging.category` | `indexnow` | category of the lines written to `Yii::getLogger()`; route it in `log.targets` |

## Component properties

Besides `options`, the component accepts replacements as instances, config arrays, class names or component ids
(`Instance::ensure`): `transport` (`TransportInterface`), `debounceStore`, `dispatcher`, `urlResolver` (replaces the
attribute resolver entirely), `logger` (PSR-3), `checks` (extra `CheckInterface`s for `indexnow/check`), `environment`.

## Console controller properties

`controllerMap['indexnow']` may configure `loader` (`SubjectLoaderInterface`: how `submit-record`/`explain` find
records), `formatter` (`ResultFormatterInterface`: table/JSON rendering), `submitters` (`SubmitterFactoryInterface`),
`modelNamespaces` (default `['app\models']`).

## Invalid configuration

Values come from `getenv()`, so they are only known at runtime. A broken value does not throw from a save or the
response: one `critical` line (`indexnow: invalid configuration, IndexNow is disabled until it is fixed: ...`) and
IndexNow runs disabled. `php yii indexnow/check` prints the exact error. Unknown keys are logged at `warning`.
