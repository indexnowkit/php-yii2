# Yii2 IndexNow extension — `indexnowkit/yii2`

Tell search engines about new, changed and deleted pages the moment an ActiveRecord row is committed.
One attribute on the model, one component, done.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/yii2)](https://packagist.org/packages/indexnowkit/yii2)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2021%2F21%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Yii](https://img.shields.io/badge/yii-2.0.45%2B-1a73e8)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — every engine in the
[IndexNow](https://www.indexnow.org) [registry](https://www.indexnow.org/searchengines.json). One request to the shared
endpoint reaches all of them; name engines explicitly only to reach a single one.

**Google: no.** Google does not support IndexNow; this package will not pretend otherwise.

**Notification, not indexing.** IndexNow tells an engine that a URL changed; whether and when the page is crawled and
indexed is the engine's decision. See the result in Bing Webmaster Tools (IndexNow Insights) and Yandex.Webmaster
(Indexing → Reindex pages); a useful metric is the share of submitted URLs in the index after a few days. Deleted
pages: answer 410 (gone for good) or 404 (temporarily); for a move answer 301 and submit both URLs; a soft-404 or a
redirect to the home page does harm. Bing's URL Submission API and Google's Indexing API are different protocols and
not covered here.

## Why this over X

Most IndexNow packages are a thin HTTP client: you collect the URLs, you call it, you read the answer. This family
does the part that goes wrong in practice:

- **Declared on the model** (`#[IndexNow]`) and submitted from the ORM hooks — no controller code to forget.
- **After the commit**, not on flush: a rolled-back transaction announces nothing.
- **Debounce** (10 minutes per URL, shared through your cache), **batches** of up to 10 000 URLs, one key per host from env.
- **Answers handled**: 202 (key pending), 422, 429 with `Retry-After` back-off and a retry through your queue, 403 escalation.
- **`check` before the first submission** says what is wrong (key file, engines, queue, cache, environment); `explain` says why a URL was or was not sent.
- **One core** under the Symfony, Laravel, Yii2 and Doctrine adapters with a shared conformance suite: the same behaviour everywhere, documented once.


## Install

```bash
composer require indexnowkit/yii2
composer require indexnowkit/sitemap           # optional: the indexnow/sitemap command
```

```php
// config/web.php and config/console.php
'bootstrap' => ['indexnow'],                   // registers the console controller and the key file route
'components' => [
    'indexnow' => [
        'class' => \IndexNowKit\Yii2\IndexNowComponent::class,
        'options' => [
            'key' => getenv('INDEXNOW_KEY'),
            'base_url' => 'https://www.example.com',   // used by console commands and queue workers
        ],
    ],
],
```

```bash
php yii indexnow/key-generate --write-env      # INDEXNOW_KEY in .env (or print it)
php yii indexnow/check                         # options, key file reachable, queue, cache, URL rules
```

The package needs a PSR-18 client (`symfony/http-client` + `nyholm/psr7`, or Guzzle); it discovers one, or takes
the component/class named in `http.client`. Pretty URLs (`urlManager.enablePrettyUrl`) are required for the key file
route `/<key>.txt`.

## Declare what has a public page

`#[IndexNow]` is repeatable: one attribute per family of public URLs. `IndexNowBehavior` registers the hooks.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
final class Post extends ActiveRecord
{
    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
```

| Option | Meaning |
|---|---|
| `route` / `params` | a Yii route (`controller/action`) and `param => attribute, method, "self", dotted.path` (`self` = the primary key) |
| `resolver` | a `UrlResolverInterface` class or component id for anything custom |
| `via` | a relation (or dotted path) whose pages are resubmitted |
| `url` / `urls` | a method returning the URL(s), or literal URLs |
| `when` / `whenFields` | bool attribute or method; drafts are skipped and `published → draft` is sent as a deletion |
| `fields` | for updates, submit only when one of these attributes changed |
| `events`, `locales`, `host`, `name` | subset of events; `current`/`all`/list (`router.languages`); another host; stable rule id |

Accessors read ActiveRecord attributes and relations (`category.slug`) and fall back to methods. A `when` column
that only has a **database** default is null on a fresh record: call `$this->loadDefaultValues()` in `init()` or set
the attribute before `save()`.

Classes you cannot annotate: `'active_record' => ['models' => [Product::class]]` in the options, or
`Yii::$app->indexnow->observe(Product::class, [new IndexNow(...)])` at runtime.

Full model, typed parameters, inheritance and the semantics table:
[core attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

## How it works

- URLs are resolved **in the ActiveRecord event**, while the old state is live (`changedAttributes` on
  `afterUpdate`, the row and its relations in `beforeDelete`). A renamed page announces its old URL as deleted.
- Outside a transaction they go to the request collector right away. Inside one, Yii2 gives no savepoint events, so
  they are held with a verifier and **re-read by primary key when the transaction commits**: a change the row does
  not show (an inner `beginTransaction()` that rolled back) is dropped with every URL it produced. A rollback drops
  everything. One `SELECT` per changed record, only inside explicit transactions. Details: [docs/commit-safety.md](docs/commit-safety.md).
- Everything collected during one request is sent **after the response** (`Response::EVENT_AFTER_SEND`), in one
  batch; console commands flush when they end, queue workers after every job.
- `dispatch: auto` (default) pushes a `SubmitUrlsJob` to the `queue` component when `yiisoft/yii2-queue` is
  configured (429/5xx re-pushed with the delay of `retry.*`, `Retry-After` honoured), else sends synchronously.
  Details: [docs/queue.md](docs/queue.md).
- Nothing thrown from a rule, a resolver or the HTTP layer reaches your application: it is logged under the
  `indexnow` category, the save succeeds. An invalid configuration disables IndexNow with one `critical` line;
  `php yii indexnow/check` prints the exact error.

## Commands

| Command | Options |
|---|---|
| `indexnow/check` | `--live` real probe · `--host=` one host · `--probe-url=` page for the probe |
| `indexnow/submit <urls...>` | `--force` ignore debounce · `--dry-run` · `--json` |
| `indexnow/submit-record <class> [ids...]` | `--event=` · `--limit=` · `--explain` · `--force` · `--dry-run` · `--json` |
| `indexnow/explain <class> <id>` | `--event=` — rules, `when`, URLs, key, debounce; sends nothing |
| `indexnow/sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `--force` · `--dry-run` · `--json` |
| `indexnow/key-generate` | `--length` · `--alphanumeric` · `--write-env[=FILE]` · `--force` rotate |

`<class>` is an FQCN or a short name under `app\models`. Ids are space- or comma-separated.

### Sitemaps

`composer require indexnowkit/sitemap   # optional: the indexnow/sitemap command`

`indexnow/sitemap` with no argument reads `sitemap.url`, else `<base_url>/sitemap.xml`; a local path works too.
Without the package everything else works unchanged: `indexnow/sitemap` says `indexnowkit/sitemap is not
installed: composer require indexnowkit/sitemap` and exits 1, `indexnow/check` prints `sitemap: not installed (…)`,
a `sitemap` block in the options is ignored, `sitemapConfig()` / `sitemapSource()` throw a `LogicException` with
the same sentence. Nothing is logged about it.

## Configuration and docs

Every option, its default and what it does: [docs/configuration.md](docs/configuration.md). Commit safety:
[docs/commit-safety.md](docs/commit-safety.md). Replacing pieces, custom resolvers, checks:
[docs/extending.md](docs/extending.md). Queue, retries, failures: [docs/queue.md](docs/queue.md). Testing your
integration: [docs/testing.md](docs/testing.md).

## Debugging

`php yii indexnow/check` validates the options, fetches the key file and reports how submissions are wired (queue,
cache, pretty URLs, ActiveRecord hooks, sitemap spool); `php yii indexnow/explain app\models\Post 1` shows the
rules, guards and URLs of one record without sending anything; the `indexnow` log category at `debug` tells why a
URL was or was not submitted. Symptoms and fixes: [docs/troubleshooting.md](docs/troubleshooting.md).

## Limitations

- `updateAll()`, `deleteAll()`, `updateAttributes()`, `updateCounters()` fire no events (conformance A13): call
  `Yii::$app->indexnow->submitRecords(Post::find()->where(...)->all())` or `php yii indexnow/submit-record` afterwards.
- `link()` / `unlink()` write the junction row with a plain command, no event on the owner: save the owner with a
  bumped timestamp afterwards (`$post->updated_at = time(); $post->save(false)`), or call `submitRecord($post)`.
- The sync driver of `yii2-queue` ignores the delay between attempts: 429/5xx attempts run back-to-back
  (development only, `check` warns).
- Without pretty URLs the key file cannot be routed: enable them, or serve `/<key>.txt` as a static file and set
  `key_file.enabled: false`.

## Compatibility

Public API: the `options` tree, command names and options, `IndexNowComponent` methods and properties,
`ActiveRecord\IndexNowBehavior`, `Queue\SubmitUrlsJob`. The core's rules apply:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); what this package itself keeps stable: [docs/bc.md](docs/bc.md). Before 1.0 a minor version may break; every
break is listed under "Changed" in [CHANGELOG.md](CHANGELOG.md). Yii 2.0.45+, PHP 8.2–8.5.

## Other frameworks

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel) |
| JS/TS | @indexnowkit/core, next, prisma (soon) |
| Python | indexnowkit, indexnowkit-django (soon) |

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
