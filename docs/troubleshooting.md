# Troubleshooting

[Русская версия](troubleshooting.ru.md)

Start with `php yii indexnow/check`, then `php yii indexnow/explain 'app\models\Post' <id>`, then the `indexnow` log
category at `debug` (`log.targets[].categories = ['indexnow']`, `levels = ['error', 'warning', 'info', 'trace']`).

## Nothing is sent

| Symptom | Cause | Fix |
|---|---|---|
| `check`: `configuration: ...` and exit 1 | an `options` value (usually from `getenv()`) is invalid; IndexNow runs disabled | fix the value; the exact error is printed and logged once at `critical` |
| log: `unknown option(s) in the indexnow configuration: ...` | a typo in `options` (`debounce.per_urls`, `key_file.enabld`) | the dotted path names the key |
| `explain`: `when: published -> false` right after `save()` | the `when` attribute only has a database default | set it explicitly before `save()`, or give it a default in `init()` |
| `explain`: `no #[IndexNow] rule` | the class has no attribute and was not registered | add `#[IndexNow]`, `IndexNowBehavior`, `active_record.models` or `observe()` |
| `explain` yields URLs, the log says nothing on save | the behavior is not attached, or `active_record.enabled` / `enabled` is false | `check` prints `active_record: ... hooked` or the reason |
| `debug` log: `did not land on commit` | the change was rolled back with a savepoint, or the verifier could not see the row | expected for a rollback; for a verifier problem see [commit-safety.md](commit-safety.md) |
| `debug` log: `debounced` | the URL was sent within `debounce.per_url` | `--force` on a command, or lower the window |
| `warning`: `skipping ... unmanaged host` | the URL's host is neither `base_url` nor in `hosts` | add the host to `hosts`, or fix `base_url` |
| console: `base_url is not set` | URLs are relative and there is no request | set `base_url`; required with `dispatch: queue` |

## The key file

| Symptom | Cause | Fix |
|---|---|---|
| `GET /<key>.txt` is 404 | pretty URLs are off, or `key_file.enabled` is false, or the key differs | `check` prints the rule; `urlManager.enablePrettyUrl = true` |
| the engines answer 403 | the served body is not the submitted key, a redirect, or a cached old file after a rotation | `curl -i https://host/<key>.txt`; `key_file.cache_max_age` is 300 s on purpose |
| `check`: `key file ... returned 200` but 403 persists | `hosts` and the submitted host differ (www vs apex) | list every host you submit under `hosts`, set `strict_hosts` |

## Queue

| Symptom | Cause | Fix |
|---|---|---|
| `check`: `queue component "queue" is not configured` | `dispatch: queue` (or `auto` resolved to it) without yii2-queue | install `yiisoft/yii2-queue` and configure the component, or `dispatch: sync` |
| 429/5xx attempts run back-to-back, no delay | `yii\queue\sync\Queue` ignores the delay of the re-push | a real driver (db, redis, amqp); `check` warns about `SyncQueue` |
| the queue's `attempts` does not limit 429/5xx | the job re-pushes them itself, up to `retry.max_attempts` | set `retry.max_attempts` (and `retry.*` delays) in the component options, see [queue.md](queue.md) |
| `ttr` exceeded on large batches | `queue.ttr` (300 s) is too small for the batch count | raise `queue.ttr`, or lower `batch.max_urls` |

## Sitemap

`php yii indexnow/sitemap --dry-run` lists what would be sent; `sitemap.enabled is false.` means the block is off or
invalid (the log has the reason). `check` prints where documents are spooled; on a read-only filesystem set
`sitemap.spool_dir` or `sitemap.spool: memory`. The reader belongs to
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap).

## Sent, but the engine answers

| Answer | Meaning | Fix |
|---|---|---|
| 403 (`invalid_key`, job rejected permanently) | `https://<host>/<key>.txt` is not reachable or has another body | `indexnow/check`; a CDN may cache the old file (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URLs of another host than `host`, or the key file on another host | one key per host (`hosts`), `strict_hosts: true`; console URLs need `base_url` on the right host |
| 429 (`rate_limited`) | too many requests | the job re-pushes itself with `Retry-After`; lower `throttle.max_requests_per_minute` |
| 202 (`pending`) | accepted, key verification pending | normal for a new key; `check --live` later answers 200 |

The 403 counter that escalates to `critical` is per process: several workers each count their own five.

## Duplicates, timing

- The same URL is not resubmitted within `debounce.per_url` (600 s). `--force` bypasses it; `debounce.store: cache`
  shares the window between requests and workers, `memory` does not.
- Everything from one request leaves as one batch after the response (`Response::EVENT_AFTER_SEND`); a console
  command flushes when it ends, a queue worker after every job.
- A rolled-back transaction submits nothing; a change re-read on commit that the row does not show is dropped
  ([commit-safety.md](commit-safety.md)).

## Staging submitted its URLs

| Symptom | Cause | Fix |
|---|---|---|
| Bing/Yandex report URLs of `staging.example.com`, or `failed` / `unprocessable` (422) for them in the log | the staging copy runs with the production key and no `dry_run`; its URLs were generated on its own host | outside production set `INDEXNOW_DRY_RUN=1` (or `INDEXNOW_ENABLED=0`); `check` fails on such a copy since core 0.6 |
| the staging host serves the production key file | `key_file.enabled` is on everywhere | `key_file.enabled: false` outside production, so no engine can verify the key on that host |
| the engines indexed staging pages | the staging host answered `200` for them and served the key | return `410` (or `noindex` + block in `robots.txt`) on staging, and rotate the key if it was exposed |
| a preview environment must submit on purpose | — | say `dry_run: false` explicitly in that environment; `check` then warns instead of failing |

## Duplicates with `memory` and several workers

| Symptom | Cause | Fix |
|---|---|---|
| the same URL is submitted by every worker within minutes | `debounce.store: memory` is per process; each web worker and queue worker keeps its own window | `debounce.store` = a shared cache; `check` warns about `memory` |
| duplicates right after a cache outage | the store fails open: no deduplication while the cache is down | expected and bounded (one request per URL); watch the `debounce store unavailable` warning rate |
| duplicates after a deploy | the shared cache was flushed, or `debounce.key_prefix` changed | harmless once; keep the prefix stable per application |
