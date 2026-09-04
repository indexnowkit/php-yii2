# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

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
