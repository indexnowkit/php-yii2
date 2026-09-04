# Yii2-расширение IndexNow — `indexnowkit/yii2`

Сообщайте поисковым системам о новых, изменённых и удалённых страницах в момент, когда строка ActiveRecord
закоммичена. Один атрибут на модели, один компонент — готово.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/yii2)](https://packagist.org/packages/indexnowkit/yii2)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Yii](https://img.shields.io/badge/yii-2.0.45%2B-1a73e8)

[English version](README.md)

## Кого уведомляем

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep** — все движки протокола [IndexNow](https://www.indexnow.org).
**Google — нет**: Google не поддерживает IndexNow, пакет не будет делать вид, что это не так.

## Установка

```bash
composer require indexnowkit/yii2
composer require indexnowkit/sitemap           # опционально: команда indexnow/sitemap
php yii indexnow/key-generate --write-env      # INDEXNOW_KEY в .env (или просто напечатать)
php yii indexnow/check                         # опции, доступность файла ключа, очередь, кэш, URL-правила
```

```php
// config/web.php и config/console.php
'bootstrap' => ['indexnow'],
'components' => [
    'indexnow' => [
        'class' => \IndexNowKit\Yii2\IndexNowComponent::class,
        'options' => [
            'key' => getenv('INDEXNOW_KEY'),
            'base_url' => 'https://www.example.com',   // для консольных команд и воркеров очереди
        ],
    ],
],
```

Нужен PSR-18 клиент (`symfony/http-client` + `nyholm/psr7` или Guzzle): пакет находит его сам либо берёт компонент/класс
из `http.client`. Для файла ключа `/<key>.txt` нужны красивые URL (`urlManager.enablePrettyUrl`).

## Объявите, у чего есть публичная страница

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // изменённый пост обновляет и страницу категории
#[IndexNow(urls: ['/'])]          // и главную
final class Post extends ActiveRecord
{
    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
```

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

Полная модель атрибута: [справочник core](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

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
  (429/5xx повторяет очередь), иначе отправляет синхронно.
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
Замена частей, свои резолверы и проверки: [docs/extending.md](docs/extending.md). Тесты интеграции: [docs/testing.md](docs/testing.md).

## Отладка

`php yii indexnow/check` проверяет опции, скачивает файл ключа и показывает, как подключена отправка (очередь,
кэш, красивые URL, хуки ActiveRecord, spool sitemap); `php yii indexnow/explain app\models\Post 1` показывает
правила, guard-условия и URL одной записи, ничего не отправляя; категория лога `indexnow` на уровне `debug`
объясняет, почему URL был или не был отправлен. Симптомы и решения: [docs/troubleshooting.md](docs/troubleshooting.md).

## Ограничения

- `updateAll()`, `deleteAll()`, `updateAttributes()`, `updateCounters()` событий не вызывают (A13): после них
  `Yii::$app->indexnow->submitRecords(Post::find()->where(...)->all())` или `php yii indexnow/submit-record`.
- `link()` / `unlink()` пишут junction-строку командой без события владельца: сохраните владельца с новой меткой времени
  (`$post->updated_at = time(); $post->save(false)`) или вызовите `submitRecord($post)`.
- Повторы в `yii2-queue` — драйверные (`ttr`, `attempts`); `Retry-After` учесть нельзя.
- Без красивых URL файл ключа не маршрутизируется: включите их либо отдавайте `/<key>.txt` статикой и поставьте
  `key_file.enabled: false`.

## Совместимость

Публичный API: дерево `options`, имена и опции команд, методы и свойства `IndexNowComponent`,
`ActiveRecord\IndexNowBehavior`, `Queue\SubmitUrlsJob`. Действуют правила core:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md). До 1.0 минорная версия может ломать
совместимость; каждое изменение — в «Changed» [CHANGELOG.md](CHANGELOG.md). Yii 2.0.45+, PHP 8.2–8.5.

## Другие фреймворки

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel) |
| JS/TS | @indexnowkit/core, next, prisma (скоро) |
| Python | indexnowkit, indexnowkit-django (скоро) |

MIT. IndexNow — торговая марка её владельца; проект независим и не связан с Microsoft, Яндексом или indexnow.org.
