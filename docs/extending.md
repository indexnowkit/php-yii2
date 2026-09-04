# Extending

## Replacing pieces

Yii2 has no application-wide interface bindings, so the component builds the core graph itself and exposes the seams
as properties (an instance, a config array, a class name or a component id):

| Property | Interface | Use it for |
|---|---|---|
| `transport` | `IndexNowKit\Http\TransportInterface` | a recording transport in tests, a proxy |
| `debounceStore` | `IndexNowKit\Debounce\DebounceStoreInterface` | a shared store the cache component cannot express |
| `dispatcher` | `IndexNowKit\Dispatch\DispatcherInterface` | another queue, an outbox table |
| `urlResolver` | `IndexNowKit\Url\UrlResolverInterface` | replace the whole "object → URLs" step |
| `logger` | PSR-3 | anything but Yii's logger |
| `checks` | list of `IndexNowKit\Check\CheckInterface` | extra lines in `indexnow/check` (a CDN purge, a tenant table) |

Underneath, the component describes the graph once with the core's `Adapter\ServicesBuilder` (the properties above
are its overrides, the Yii pieces are closures) and `services()` returns the lazy `Adapter\Services`; nothing is
built before it is used, and a request that collects nothing builds nothing. Everything built is also readable, as
delegates: `kit()`, `config()`, `submitter()`, `collector()`, `keys()`, `transport()`, `debounceStore()`,
`routeResolver()`, `observer()`, `staging()`, `checker()`, `sitemapSource()`, `rules()`.

## Custom resolvers

```php
#[IndexNow(resolver: ProductUrlResolver::class)]      // a class Yii::$container can build
#[IndexNow(resolver: 'productUrls')]                    // or an application component id
```

The class implements `IndexNowKit\Url\UrlResolverInterface`; constructor dependencies come from `Yii::$container`.

## Rules at runtime

```php
Yii::$app->indexnow->observe(Product::class, [new IndexNow(route: 'product/view', params: ['id' => 'self'])], new IndexNowDefaults(when: 'active'));
Yii::$app->indexnow->rules()->registerFor(Page::class, fn (Page $page): ?RuleSet => ...);   // decided per object
```

`observe()` hooks the class through class-level events (`yii\base\Event::on`), the same mechanism as the
`active_record.models` list.

## Manual submissions

`submit(iterable $urls)`, `submitRecord($record, Event $event)`, `submitRecords(iterable $records)` (one request for
many), `urlsFor()`, `explain()` return `Result`s; `collect()` parks URLs in the request collector, `flush()` sends
them now. Listen to results: `Yii::$app->indexnow->submitter()->addListener(fn (Result $r) => ...)`.

## What is the core's

The observer keeps only what is Yii's (the change set from `changedAttributes`, the previous state, the
verify-on-commit staging); guarding, logging and the URLs of a row about to be deleted are the core's
`Hook\ObserverHelper`. `SubmitUrlsJob` is `Retry\WorkerOutcome` plus yii2-queue's `canRetry()` (no delay from the
job: the queue driver's delay applies). The options of every `indexnow/<action>` come from `Console\Definitions`
and `Sitemap\Console\Definitions`, so `php yii help indexnow/submit-record` matches the bundle and artisan.

## Console

`controllerMap['indexnow']` accepts `loader` (`IndexNowKit\Console\SubjectLoaderInterface`, tenant scoping or another
id format), `formatter` (`ResultFormatterInterface`, your JSON envelope) and `submitters` (`SubmitterFactoryInterface`).
The command bodies are the core's `IndexNowKit\Console\*Runner`; a tenant loop over `SubmitSubjectsRunner` is a
ten-line controller action.
