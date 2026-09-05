# Диагностика

[English version](troubleshooting.md)

Начните с `php yii indexnow/check`, затем `php yii indexnow/explain app\models\Post <id>`, затем категория лога `indexnow`
на `debug` (`log.targets[].categories = ['indexnow']`, `levels = ['error', 'warning', 'info', 'trace']`).

## Не отправляется ничего

| Симптом | Причина | Исправление |
|---|---|---|
| `check`: `configuration: ...` и exit 1 | значение в `options` (обычно из `getenv()`) невалидно; IndexNow работает выключенным | исправьте значение; точная ошибка напечатана и залогирована один раз на `critical` |
| лог: `unknown option(s) in the indexnow configuration: ...` | опечатка в `options` (`debounce.per_urls`, `key_file.enabld`) | путь через точку называет ключ |
| `explain`: `when: published -> false` сразу после `save()` | у атрибута `when` дефолт только в базе | задайте его до `save()` или вызовите `loadDefaultValues()` в `init()` |
| `explain`: `no #[IndexNow] rule` | у класса нет атрибута и он не зарегистрирован | добавьте `#[IndexNow]`, `IndexNowBehavior`, `active_record.models` или `observe()` |
| `explain` даёт URL, лог на save молчит | behavior не подключён, либо `active_record.enabled` / `enabled` выключены | `check` печатает `active_record: ... hooked` или причину |
| `debug`-лог: `did not land on commit` | изменение откатилось savepoint'ом, или verifier не увидел строку | ожидаемо для отката; про verifier см. [commit-safety.md](commit-safety.md) |
| `debug`-лог: `debounced` | URL отправлен в пределах `debounce.per_url` | `--force` у команды, или уменьшите окно |
| `warning`: `skipping ... unmanaged host` | хост URL — ни `base_url`, ни в `hosts` | добавьте хост в `hosts` или исправьте `base_url` |
| консоль: `base_url is not set` | URL относительные, а запроса нет | задайте `base_url`; обязателен при `dispatch: queue` |

## Файл ключа

| Симптом | Причина | Исправление |
|---|---|---|
| `GET /<key>.txt` — 404 | pretty URL выключены, или `key_file.enabled` false, или ключ другой | `check` печатает правило; `urlManager.enablePrettyUrl = true` |
| движки отвечают 403 | отдаваемое тело — не отправляемый ключ, редирект, или закэшированный старый файл после ротации | `curl -i https://host/<key>.txt`; `key_file.cache_max_age` — 300 с нарочно |
| `check`: `key file ... returned 200`, но 403 остаётся | `hosts` и отправляемый хост различаются (www vs apex) | перечислите каждый отправляемый хост в `hosts`, включите `strict_hosts` |

## Очередь

| Симптом | Причина | Исправление |
|---|---|---|
| `check`: `queue component "queue" is not configured` | `dispatch: queue` (или `auto`, разрешённый в него) без yii2-queue | установите `yiisoft/yii2-queue` и настройте компонент, либо `dispatch: sync` |
| попытки 429/5xx идут подряд без задержки | `yii\queue\sync\Queue` игнорирует задержку повторного push | реальный драйвер (db, redis, amqp); `check` предупреждает о `SyncQueue` |
| `attempts` очереди не ограничивает 429/5xx | job перепушивает их сам, до `retry.max_attempts` | задайте `retry.max_attempts` (и задержки `retry.*`) в опциях компонента, см. [queue.md](queue.md) |
| `ttr` превышен на больших батчах | `queue.ttr` (300 с) мал для числа батчей | поднимите `queue.ttr` или понизьте `batch.max_urls` |

## Sitemap

`php yii indexnow/sitemap --dry-run` перечисляет, что было бы отправлено; `sitemap.enabled is false.` значит, что блок
выключен или невалиден (причина в логе). `check` печатает, куда spool'ятся документы; на read-only ФС задайте
`sitemap.spool_dir` или `sitemap.spool: memory`. Ридер — в
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap).

## Отправлено, но движок отвечает

| Ответ | Смысл | Исправление |
|---|---|---|
| 403 (`invalid_key`, job rejected permanently) | `https://<host>/<key>.txt` недоступен или с другим телом | `indexnow/check`; CDN может кэшировать старый файл (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URL другого хоста, чем `host`, или файл ключа на другом хосте | ключ на каждый хост (`hosts`), `strict_hosts: true`; консольным URL нужен `base_url` на нужном хосте |
| 429 (`rate_limited`) | слишком много запросов | job перепушивает себя с `Retry-After`; понизьте `throttle.max_requests_per_minute` |
| 202 (`pending`) | принято, проверка ключа в процессе | нормально для нового ключа; `check --live` позже ответит 200 |

Счётчик 403, эскалирующий в `critical`, — на процесс: несколько воркеров считают каждый свои пять.

## Дубли, тайминги

- Тот же URL не отправляется повторно в течение `debounce.per_url` (600 с). `--force` обходит это;
  `debounce.store: cache` делит окно между запросами и воркерами, `memory` — нет.
- Всё из одного запроса уходит одним батчем после ответа (`Response::EVENT_AFTER_SEND`); консольная команда сбрасывает
  при завершении, воркер очереди — после каждого job.
- Откатившаяся транзакция ничего не отправляет; изменение, перечитанное на commit и не подтверждённое строкой,
  отбрасывается ([commit-safety.md](commit-safety.md)).

## Стейджинг отправил свои URL

| Симптом | Причина | Исправление |
|---|---|---|
| Bing/Яндекс показывают URL `staging.example.com`, или в логе `failed` / `unprocessable` (422) по ним | стейджинг работает с боевым ключом и без `dry_run`; URL сгенерированы на его хосте | вне production задайте `'dry_run' => true` (или `'enabled' => false`); с core 0.6 `check` на такой копии падает |
| стейджинг отдаёт боевой файл ключа | `key_file.enabled` включён везде | `key_file.enabled: false` вне production |
| движки проиндексировали страницы стейджинга | хост стейджинга ответил `200` и отдал ключ | отдавайте `410` (или `noindex` + запрет в `robots.txt`) и ротируйте ключ, если он утёк |
| preview-окружение должно отправлять нарочно | — | явный `'dry_run' => false`; `check` тогда предупреждает, а не падает |

## Дубли с `memory` и несколькими воркерами

| Симптом | Причина | Исправление |
|---|---|---|
| один и тот же URL отправляет каждый воркер | `debounce.store: memory` — на процесс | `debounce.store` = компонент кэша (`cache`); `check` предупреждает о `memory` |
| дубли сразу после сбоя кэша | store fails open | ожидаемо и ограничено; следите за warning `debounce store unavailable` |
| дубли после деплоя | кэш сброшен или изменился `debounce.key_prefix` | безвредно один раз; держите префикс стабильным |
