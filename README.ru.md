# Yii2-расширение IndexNow — `indexnowkit/yii2`

Сообщайте поисковым системам о новых, изменённых и удалённых страницах в момент, когда строка ActiveRecord
закоммичена. Один атрибут на модели, один компонент — готово.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/yii2)](https://packagist.org/packages/indexnowkit/yii2)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/yii2)](https://packagist.org/packages/indexnowkit/yii2)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Yii](https://img.shields.io/badge/yii-2.0.45%2B-1a73e8)
[![License](https://img.shields.io/packagist/l/indexnowkit/yii2)](LICENSE)

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Кого уведомляем

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — все участники
[реестра](https://www.indexnow.org/searchengines.json) протокола [IndexNow](https://www.indexnow.org). Один запрос на
общий endpoint доходит до всех; перечислять движки явно нужно только чтобы отправить в один.
**Google — нет**: Google не поддерживает IndexNow, пакет не будет делать вид, что это не так.

**Уведомление, не индексация.** IndexNow сообщает поисковику, что URL изменился; обойти и проиндексировать страницу — его
решение и его сроки. Результат виден в Bing Webmaster Tools (IndexNow Insights) и в Яндекс.Вебмастере (Индексирование →
Переобход страниц); полезная метрика — доля отправленных URL в индексе через несколько дней. Удалённые страницы: отдавайте
410 (навсегда) или 404 (временно); при переезде — 301 и отправка обоих URL; soft-404 и редирект на главную вредят.
Bing URL Submission API и Google Indexing API — другие протоколы, здесь не покрываются.

## Почему это, а не X

Большинство пакетов IndexNow — тонкий HTTP-клиент: URL собираете вы, вызываете вы, ответ читаете вы. Это семейство делает
то, что на практике ломается:

- **Объявлено на модели** (`#[IndexNow]`) и отправляется из хуков ORM — нет кода в контроллере, который можно забыть.
- **После commit**, не на flush: откатившаяся транзакция ничего не объявляет.
- **Дебаунс** (10 минут на URL, через ваш кэш), **батчи** до 10 000 URL, ключ на host из env.
- **Ответы обработаны**: 202 (ключ проверяется), 422, 429 с `Retry-After` и повтором через вашу очередь, эскалация 403.
- **`check` до первой отправки** говорит, что не так (файл ключа, движки, очередь, кэш, окружение); `explain` — почему URL ушёл или не ушёл.
- **Одно ядро** под адаптерами Symfony, Laravel, Yii2 и Doctrine с общим conformance-набором: поведение одинаковое везде и описано один раз.


## Установка

```bash
composer require indexnowkit/yii2 symfony/http-client nyholm/psr7   # подойдёт любой PSR-18 клиент + PSR-17 фабрики
composer require indexnowkit/sitemap                                # опционально: команда indexnow/sitemap
```

```php
// config/web.php и config/console.php
'bootstrap' => ['indexnow'],                   // регистрирует консольный контроллер и маршрут файла ключа
'components' => [
    'indexnow' => [
        'class' => \IndexNowKit\Yii2\IndexNowComponent::class,
        'options' => [
            'key' => getenv('INDEXNOW_KEY'),
            'base_url' => 'https://www.example.com',   // для консольных команд и воркеров очереди
            'dry_run' => YII_ENV_DEV,                  // dev/staging: логировать, не отправлять (без этого вне production check падает)
        ],
    ],
],
```

```bash
php yii indexnow/key-generate --write-env      # пишет INDEXNOW_KEY=… в .env (или печатает ключ)
php yii indexnow/check                         # опции, доступность файла ключа, очередь, кэш, URL-правила
```

Yii2 сам `.env` не читает: экспортируйте переменную (`export INDEXNOW_KEY=…`), задайте её в окружении веб-сервера или
контейнера, либо загрузите файл через `vlucas/phpdotenv` до `config/*.php` — иначе `getenv('INDEXNOW_KEY')` вернёт
`false`, а `check` скажет `no key configured`. В `yii2-app-basic` `config/web.php` и `config/console.php` независимы:
настройте компонент `indexnow` **и** `urlManager` (красивые URL, правила) в обоих, иначе `check`, `explain` и
`submit-record` увидят не ту конфигурацию, что веб-приложение. Для файла ключа `/<key>.txt` нужны красивые URL
(`urlManager.enablePrettyUrl`). Нужен PSR-18 клиент (`symfony/http-client` + `nyholm/psr7`, как выше, или Guzzle):
пакет находит его сам либо берёт компонент/класс из `http.client`.

## Объявите, у чего есть публичная страница

`#[IndexNow]` повторяемый: один атрибут на семейство публичных URL. `IndexNowBehavior` регистрирует хуки. Сохраните
пример как `models/Post.php` с `namespace app\models;` — он читает колонки `slug`, `title`, `body`, `published`, `amp`
(AMP-страница существует, пока true) и `category_id`; `Category` — ваша запись со своим правилом `#[IndexNow]`
(уберите строку `via: 'category'`, если её нет).

<!-- test: quickstart-model -->
```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // изменившийся пост обновляет и страницу категории
#[IndexNow(urls: ['/'])]          // и главную
final class Post extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public function init(): void
    {
        parent::init();
        $this->loadDefaultValues();   // у `published` дефолт в базе: сделать его видимым до первого сохранения
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }

    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
```
<!-- /test -->
| Опция | Смысл |
|---|---|
| `route` / `params` | Yii-маршрут (`controller/action`) и `param => атрибут, метод, "self", путь.через.точку` (`self` = первичный ключ) |
| `resolver` | класс или id компонента `UrlResolverInterface` для нестандартного |
| `via` | отношение (или путь), страницы которого переотправляются |
| `url` / `urls` | метод, возвращающий URL, либо литеральные URL |
| `when` / `whenFields` | bool-атрибут или метод; черновики пропускаются, `published → draft` уходит как удаление |
| `fields` | при обновлении отправлять только если изменился один из этих атрибутов |
| `events`, `locales`, `host`, `name` | подмножество событий; `current`/`all`/список (`router.languages`); другой хост; стабильный id правила |

Accessor'ы читают атрибуты и отношения AR (`category.slug`), затем методы. Колонка `when`, у которой есть только дефолт
**в базе**, на свежей записи равна null: вызовите `$this->loadDefaultValues()` в `init()` или задайте атрибут до `save()`.

Классы, которые нельзя аннотировать: `'active_record' => ['models' => [Product::class]]` в опциях или
`Yii::$app->indexnow->observe(Product::class, [new IndexNow(...)])` в рантайме.

Полная модель атрибута: [справочник core](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.ru.md).

## Как это работает

- URL резолвятся **в событии ActiveRecord**, пока живо старое состояние (`changedAttributes` в `afterUpdate`, строка и
  отношения в `beforeDelete`). Переименованная страница объявляет старый URL удалённым.
- Вне транзакции URL сразу идут в коллектор запроса. Внутри транзакции Yii2 не даёт событий savepoint'ов, поэтому URL
  придерживаются вместе с проверкой и **перечитываются по первичному ключу на commit**: изменение, которого в строке нет
  (откатанный вложенный `beginTransaction()`), отбрасывается со всеми своими URL. Rollback отбрасывает всё. Один `SELECT`
  на изменённую запись, только внутри явных транзакций. Подробности: [docs/commit-safety.md](docs/commit-safety.md).
- Всё собранное за запрос уходит **после ответа** (`Response::EVENT_AFTER_SEND`) одним батчем; консольные команды
  сбрасывают при завершении, воркеры очереди — после каждой job.
- `dispatch: auto` (по умолчанию) кладёт `SubmitUrlsJob` в компонент `queue`, если настроен `yiisoft/yii2-queue`
  (429/5xx job перекладывает в очередь с задержкой из `retry.*`, `Retry-After` учитывается), иначе отправляет
  синхронно. Подробности: [docs/queue.md](docs/queue.md).
- Ничто из правила, резолвера или HTTP-слоя не долетает до приложения: пишется в лог (категория `indexnow`), сохранение
  проходит. Невалидная конфигурация выключает IndexNow с одной строкой `critical`; точную ошибку печатает `php yii indexnow/check`.

## Команды

| Команда | Опции |
|---|---|
| `indexnow/check` | `--live` · `--host=` · `--probe-url=` |
| `indexnow/submit <urls...>` | `--force` · `--dry-run` · `--json` |
| `indexnow/submit-record <class> [ids...]` | `--event=` · `--limit=` · `--explain` · `--force` · `--dry-run` · `--json` |
| `indexnow/explain <class> <id>` | `--event=` — правила, `when`, URL, ключ, дебаунс; ничего не отправляет |
| `indexnow/sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `--force` · `--dry-run` · `--json` |
| `indexnow/key-generate` | `--length` · `--alphanumeric` · `--write-env[=FILE]` · `--force` ротация |

`<class>` — FQCN или короткое имя в `app\models`. Идентификаторы через пробел или запятую.

### Sitemap

`composer require indexnowkit/sitemap   # опционально: команда indexnow/sitemap`

`indexnow/sitemap` без аргумента читает `sitemap.url`, иначе `<base_url>/sitemap.xml`; локальный путь тоже
работает. Без пакета всё остальное работает как прежде: `indexnow/sitemap` отвечает `indexnowkit/sitemap is not
installed: composer require indexnowkit/sitemap` и завершается с кодом 1, `indexnow/check` печатает `sitemap: not
installed (…)`, блок `sitemap` в опциях игнорируется, `sitemapConfig()` / `sitemapSource()` бросают `LogicException`
с той же фразой. В логи ничего не пишется.

## Конфигурация и документация

Все опции: [docs/configuration.md](docs/configuration.md). Commit-safety: [docs/commit-safety.md](docs/commit-safety.md).
Замена частей, свои резолверы и проверки: [docs/extending.md](docs/extending.md). Очередь, повторы, отказы:
[docs/queue.md](docs/queue.md). Тесты интеграции: [docs/testing.md](docs/testing.md).

## Эксплуатация

- [Чеклист production](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#production-checklist)
  — ключ и base URL, `check` в деплое, `strict_hosts`, общий debounce store, очередь под мониторингом,
  стейджинг, который не может отправлять, три строки для алертов.
- [Правила мониторинга и фильтр Sentry](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#monitoring-rules),
  [удалённые страницы](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#deleted-pages-what-your-site-must-return),
  [что не отправлять](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#what-not-to-submit).
- [Мультидомен: хосты, www и apex, языки](docs/multi-domain.md) · [очередь](docs/queue.md) · [commit safety](docs/commit-safety.md) · [troubleshooting](docs/troubleshooting.ru.md).

## Отладка

`php yii indexnow/check` проверяет опции, скачивает файл ключа и показывает, как подключена отправка (очередь,
кэш, красивые URL, хуки ActiveRecord, spool sitemap); `php yii indexnow/explain 'app\models\Post' 1` показывает
правила, guard-условия и URL одной записи, ничего не отправляя; категория лога `indexnow` на уровне `debug`
объясняет, почему URL был или не был отправлен. Симптомы и решения: [docs/troubleshooting.ru.md](docs/troubleshooting.ru.md).

## Ограничения

- `updateAll()`, `deleteAll()`, `updateAttributes()`, `updateCounters()` событий не вызывают (A13): после них
  `Yii::$app->indexnow->submitRecords(Post::find()->where(...)->all())` или `php yii indexnow/submit-record`.
- `link()` / `unlink()` пишут junction-строку командой без события владельца: сохраните владельца с новой меткой времени
  (`$post->updated_at = time(); $post->save(false)`) или вызовите `submitRecord($post)`.
- Sync-драйвер `yii2-queue` игнорирует задержку между попытками: 429/5xx повторяются подряд (только для разработки,
  `check` предупреждает).
- Без красивых URL файл ключа не маршрутизируется: включите их либо отдавайте `/<key>.txt` статикой и поставьте
  `key_file.enabled: false`.

## Совместимость

Публичный API: дерево `options`, имена и опции команд, методы и свойства `IndexNowComponent`,
`ActiveRecord\IndexNowBehavior`, `Queue\SubmitUrlsJob`. Действуют правила core:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); что стабильно в самом пакете: [docs/bc.md](docs/bc.md). До 1.0 минорная версия может ломать
совместимость; каждое изменение — в «Changed» [CHANGELOG.md](CHANGELOG.md). Yii 2.0.45+, PHP 8.2–8.5.

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/yii2` (Yii 2.0.45+, поверх `indexnowkit/core`); команда `sitemap` требует `indexnowkit/sitemap`. Конфигурация: компонент приложения `indexnow` (массив `options`), `'bootstrap' => ['indexnow']`.
- Минимальный полный сниппет (все `use` на месте):

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(urls: ['/'])]
final class Post extends ActiveRecord { public function behaviors(): array { return [IndexNowBehavior::class]; } }
```

- Проверка: `php yii indexnow/check` (exit 1 при любой ошибке; `--strict` падает и на предупреждениях, `--json` для машин), `php yii indexnow/config --json` (эффективная конфигурация с маскированными ключами: вставьте её в баг-репорт), `php yii indexnow/explain 'app\\models\\Post' 1` (почему URL был или не был получен), `php yii indexnow/submit-record 'app\\models\\Post' 1 --dry-run`.
- Ловушки:
  - `dispatch: auto` есть в Symfony (`auto` | `messenger` | `sync` | `none`) и Yii2 (`auto` | `queue` | `sync` | `none`), в Laravel **нет** (`queue` | `sync` | `none`).
  - Локали: `router.locales` в Laravel, `router.languages` в Yii2, `framework.enabled_locales` в Symfony; `locales: 'all'` у правила берёт этот список.
  - `url:` — имя аксессора (метод или свойство), который возвращает URL; `urls:` — список литеральных URL. Литерал в `url:` не ставить.
  - Строка в `when:` — аксессор, читаемый как truthy (`published`, `isPublished`). Строка статуса требует `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Ручная отправка: `submitEntity()` в Symfony, `submitModel()` в Laravel, `submitRecord()` в Yii2; команды — `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`. Массовые запросы (`update()`, `DB::table()`, `updateAll()`) хуков не вызывают — отправляйте ими после.
  - В Laravel два класса `IndexNowKit`: фасад `IndexNowKit\Laravel\Facades\IndexNowKit` и сервис ядра `IndexNowKit\IndexNowKit` (инжектится по типу). В Yii2 ядро — `Yii::$app->indexnow->kit()`.
  - Вне production настроенный ключ с незаданным `dry_run` делает `check` красным (стейджинг отправил бы боевые URL): задайте там `dry_run: true`, либо явный `dry_run: false`, если отправка нарочно.
  - Неизвестные ключи конфигурации дают warning при загрузке (опечатки вроде debounce.per_urls); список — `Config::OPTIONS` плюс ключи адаптера.


## Другие фреймворки

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel) |
| JS/TS | @indexnowkit/core, next, prisma (скоро) |
| Python | indexnowkit, indexnowkit-django (скоро) |

MIT. IndexNow — торговая марка её владельца; проект независим и не связан с Microsoft, Яндексом или indexnow.org.
