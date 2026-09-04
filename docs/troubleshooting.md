# Troubleshooting

Start with `php yii indexnow/check`, then `php yii indexnow/explain app\models\Post <id>`, then the `indexnow` log
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
| jobs run but nothing is retried | `yii\queue\sync\Queue` handles jobs inline, without retries | a real driver (db, redis, amqp); `check` warns about `SyncQueue` |
| `ttr` exceeded on large batches | `queue.ttr` (300 s) is too small for the batch count | raise `queue.ttr`, or lower `batch.max_urls` |

## Sitemap

`php yii indexnow/sitemap --dry-run` lists what would be sent; `sitemap.enabled is false.` means the block is off or
invalid (the log has the reason). `check` prints where documents are spooled; on a read-only filesystem set
`sitemap.spool_dir` or `sitemap.spool: memory`. The reader belongs to
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap).
