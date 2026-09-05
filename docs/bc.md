# Backward compatibility

`indexnowkit/yii2` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.
The core's tiers ("call", "implement", "may grow") apply to every core class you touch through this package:
[core bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md).

## What the package keeps stable

| Surface | Promise |
|---|---|
| **Component options** (`'options' => [...]` of `IndexNowComponent`, [configuration.md](configuration.md)) | Keys and their meaning stay; new keys are only added with a default. A rename ships the old key as deprecated for one minor and is listed in the changelog. |
| **Component properties** `options`, `transport`, `debounceStore`, `dispatcher`, `urlResolver`, `logger`, `checks`, `environment`, `sitemapInstalled` and the **accessors** `config()`, `kit()`, `rules()`, `keys()`, `transport()`, `submitter()`, `collector()`, `dispatcher()`, `debounceStore()`, `staging()`, `services()`, `sitemapPackage()`, `sitemapInstalled()` | Names and types stay; new properties and accessors are only added. |
| **Console actions and options** (`indexnow/check`, `indexnow/key-generate`, `indexnow/submit`, `indexnow/submit-record`, `indexnow/explain`, `indexnow/sitemap`) | Names, arguments and options come from the core `Console\Definitions`; new options are only added. Output is not a contract except the exit codes and the `--json` shape of the core formatter. |
| **Bootstrap** (`'bootstrap' => ['indexnow']`), the controller id `indexnow`, the key file controller `indexnow-key-file` and its URL rule `<key>.txt` | Ids and the rule stay. |
| **Behavior `ActiveRecord\IndexNowBehavior`**, **`ActiveRecord\IndexNowObserver`** | The behavior stays a drop-in; the observer's public hooks keep their names. |
| **Queue job** `Queue\SubmitUrlsJob` | Its public properties and the serialized shape stay, so jobs pushed before an upgrade still run after it. |
| **Check classes** `Check\QueueCheck`, `Check\UrlManagerCheck`, `Check\ActiveRecordCheck`, `Check\CacheProbe` and the `checks` property | Names stay; adding a `CheckInterface` keeps working. |

Not a contract: log message texts (their `context` keys are), the exact wording the actions print (exit codes and
levels are), and the `Wiring` / `References` helpers the component builds its graph with.

## Pinning

`composer require indexnowkit/yii2:^0.6` gets every 0.6.x patch. Read the changelog before a minor.
