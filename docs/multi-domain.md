# Multiple domains and languages

## One application, several hosts

Every host gets its own key (engines verify `https://<host>/<key>.txt` on the submitted host):

```php
'options' => [
    'key' => getenv('INDEXNOW_KEY'),                       // www.example.com, the base_url host
    'base_url' => 'https://www.example.com',
    'hosts' => [
        'example.de' => getenv('INDEXNOW_KEY_DE'),
        'shop.example.com' => [
            'key' => getenv('INDEXNOW_KEY_SHOP'),
            'base_url' => 'https://shop.example.com',    // origin for this host's URLs outside requests
            'engines' => ['yandex', 'bing'],             // per-host engine list
        ],
    ],
    'strict_hosts' => true,                             // hosts not listed are skipped, not sent under the default key
],
```

The key file action serves each host's own key only (a request for `example.de`'s key on `www.example.com` is 404)
and answers with `Vary: Host`, so a shared CDN never caches one host's file for another. `php yii indexnow/check`
fetches every host's key file; `--host=example.de` limits it to one.

## Rules on another host

```php
#[IndexNow(route: 'product/view', params: ['slug' => 'slug'], host: 'shop.example.com')]
```

`host` can also be an accessor (`host: 'tenant.domain'`) for multi-tenant records. The URL is generated through
`urlManager` and rebased onto `hosts.<host>.base_url`, else `https://<host>`. A URL rule with its own host
(`'http://shop.example.com/products/<slug>' => 'product/view'`) keeps it.

## Languages

```php
'router' => ['languages' => ['en', 'de'], 'language_parameter' => 'language', 'set_app_language' => true],
'locale_hosts' => ['de' => 'example.de'],           // optional: one host per language
```

```php
#[IndexNow(route: 'article/view', params: ['slug' => 'slug'], locales: 'all')]
```

- `locales: 'current'` (default) generates one URL; `'all'` one per `router.languages`; a list as given.
- The language is passed as the `router.language_parameter` parameter, so a URL rule that declares it
  (`'<language:(en|de)>/articles/<slug>' => 'article/view'`) puts it in the path; without such a rule it becomes a
  query parameter — declare the rule.
- With `set_app_language`, `Yii::$app->language` is switched for the duration of the generation and restored, which
  is what localized slugs and `Url::to()` helpers read.
- With `locale_hosts`, a rule without `host` generates each language on that language's host and under that host's key.

## Origin of generated URLs

| Context | Origin |
|---|---|
| web request | whatever `urlManager` generated (the request host) |
| console command, queue worker | `base_url` (the console `urlManager` has no host; the package rebases scheme, host and port) |
| rule with `host:` | `hosts.<host>.base_url`, else `https://<host>` |
| URL rule with its own host | the rule's host, always |

A staging copy reached under another hostname would otherwise submit its URLs under the production key; that is what
`strict_hosts: true` prevents, and why `indexnow/check` warns when it is off in production.

## www and apex

`example.com` and `www.example.com` are two hosts to IndexNow: each needs its own key file, and a URL submitted
under the other one's key answers 422. Pick the canonical one (the one your pages link to and `<link
rel="canonical">` names), put it in `base_url`, redirect the other with `301`, and do not list it in `hosts` —
listing both would announce two copies of every page. With `strict_hosts: true` a request that reached the
application under the non-canonical name submits nothing instead of announcing duplicates.

## hreflang clusters

Localized pages that point at each other with `hreflang` are one cluster to the engines: when one changes, announce
the cluster. A rule with `locales: 'all'` does that for the locales of one model; for locales living on other hosts
`locale_hosts` sends each locale to its host under that host's key. When translations are separate objects, `via:`
walks to them:

```php
#[IndexNow(route: 'article/view', params: ['slug' => 'slug'], locales: 'all')]   // every language of this article
#[IndexNow(via: 'translations')]                                                 // or: the sibling objects' own rules
```
