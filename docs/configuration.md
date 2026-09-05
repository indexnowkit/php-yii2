# Configuration

Everything lives in the `options` array of the `indexnow` component. Keys mirror the family-wide schema of
`indexnowkit/core` ([configuration.md there](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md));
the blocks marked **Yii** are handled by this package. Verify with `php yii indexnow/check`.

## Core keys

Values usually come from `getenv()` in your config file; nothing is read from the environment by the package itself.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | kill switch; `false` = nothing is sent, changes are logged at debug |
| `key` | — | the IndexNow key, `[A-Za-z0-9-]{8,128}` (`php yii indexnow/key-generate`) |
| `previous_key` | — | the key before a rotation: its file is still served, nothing is submitted under it |
| `key_location` | — | full URL of the key file when it is not `https://<host>/<key>.txt` |
| `base_url` | — | origin for URLs generated outside web requests (console, queue workers); required with `dispatch: queue` |
| `hosts` | `[]` | `host => key` or `host => ['key', 'key_location', 'base_url', 'engines', 'previous_key']` |
| `strict_hosts` | `false` | skip URLs of hosts outside `base_url` / `hosts` instead of sending them under the default key |
| `environment` | `YII_ENV` (component property `environment` overrides) | feeds the non-production dry-run safety net and the `check` staging error |
| `production_environments` | `['prod', 'production']` | environments where a missing key is an error, not dry-run |
| `max_url_length` | `2048` | longer URLs are `invalid_url` |
| `engines` | `['api']` | `api`, `yandex`, `bing`, `naver`, `seznam`, `yep`, `internetarchive`, `amazon`, an endpoint URL or an alias |
| `engine_aliases` | `[]` | `alias => endpoint URL` |
| `locale_hosts` | `[]` | `language => host` for rules with `locales` and no `host` |
| `dispatch` | `auto` | `auto`, `queue`, `sync`, `none` (see below) |
| `dry_run` | unset | log the request instead of sending it; unset outside production makes `check` fail when a key is configured |
| `batch.max_urls` | `10000` | URLs per request (protocol ceiling, not a target) |
| `debounce.per_url` | `600` | seconds a URL is not resubmitted; `0` = off |
| `debounce.key_prefix` | `indexnowkit_` | cache key prefix of the shared window |
| `throttle.max_requests_per_minute` | `60` | token bucket per process |
| `http.timeout` | `10` | seconds |
| `http.user_agent` | `null` | override the `indexnowkit-php/x.y.z` agent |
| `retry.*` | `3 / 60 / 2.0 / 3600 / 5` | `max_attempts`, `base_delay`, `multiplier`, `max_delay`, `server_error_delay` of `SubmitUrlsJob` |
| `resolver.max_via_depth` / `max_via_fanout` | `3` / `100` | limits of `via:` |
| `collector.max_urls` / `detect_leaks` | `0` / `true` | early flush threshold; warn at shutdown about unflushed URLs |
| `logging.max_urls` / `forbidden_escalation` / `max_body` / `levels` | `20` / `5` / `300` / `[]` | log line shaping; see the core operations guide |

The full semantics of every core key, and the same table for the other adapters, are in the
[core configuration reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md)
("One concept, three keys").

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
| `sitemap.*` | | needs `indexnowkit/sitemap` (`composer require indexnowkit/sitemap`), else the block is ignored and `indexnow/check` says so: `enabled`, `url`, `max_depth`, `max_sitemaps`, `max_bytes`, `allow_foreign_hosts`, `spool` (auto\|disk\|memory), `spool_dir`, `fetch_retries` |
| `logging.category` | `indexnow` | category of the lines written to `Yii::getLogger()`; route it in `log.targets` |

## Component properties

Besides `options`, the component accepts replacements as instances, config arrays, class names or component ids
(`Instance::ensure`): `transport` (`TransportInterface`), `debounceStore`, `dispatcher`, `urlResolver` (replaces the
attribute resolver entirely), `logger` (PSR-3), `checks` (extra `CheckInterface`s for `indexnow/check`), `environment`,
`sitemapInstalled` (`null` detects `indexnowkit/sitemap`; `false` runs as if the package were absent — tests, or a
deployment that must not read sitemaps).

## Console controller properties

`controllerMap['indexnow']` may configure `loader` (`SubjectLoaderInterface`: how `submit-record`/`explain` find
records), `formatter` (`ResultFormatterInterface`: table/JSON rendering), `submitters` (`SubmitterFactoryInterface`),
`modelNamespaces` (default `['app\models']`).

## Invalid configuration

Values come from `getenv()`, so they are only known at runtime. A broken value does not throw from a save or the
response: one `critical` line (`indexnow: invalid configuration, IndexNow is disabled until it is fixed: ...`) and
IndexNow runs disabled. `php yii indexnow/check` prints the exact error. Unknown keys are logged at `warning`.
