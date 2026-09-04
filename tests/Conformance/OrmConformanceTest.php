<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Conformance;

use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\OrmConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Fixtures\BadAttribute;
use IndexNowKit\Yii2\Tests\Fixtures\Broken;
use IndexNowKit\Yii2\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Yii2\Tests\Fixtures\Category;
use IndexNowKit\Yii2\Tests\Fixtures\MultiPost;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Fixtures\Tag;
use IndexNowKit\Yii2\Tests\Fixtures\Untracked;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use yii\db\ActiveRecord;
use yii\db\Transaction;
use yii\web\Application;

/**
 * The core ORM conformance kit (A01-A21) driven through Yii2 ActiveRecord: IndexNowBehavior + verify-on-commit
 * staging for commit safety (Yii2 has no savepoint events), nested `beginTransaction()` through savepoints, the
 * `updated_at` recipe for the junction-table scenario.
 */
final class OrmConformanceTest extends OrmConformanceTestCase
{
    private Application $app;
    private FakeTransport $transport;
    private ArrayLogger $logger;

    /** @var list<Transaction> */
    private array $transactions = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->app = Fixtures::webApp($this->transport, $this->logger);
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function logger(): ArrayLogger
    {
        return $this->logger;
    }

    protected function flush(): void
    {
        $this->component()->kit()->flush();
    }

    protected function collectedCount(): int
    {
        return \count($this->component()->kit()->collector->all());
    }

    protected function begin(): void
    {
        $this->transactions[] = $this->app->getDb()->beginTransaction();
    }

    protected function commit(): void
    {
        $transaction = array_pop($this->transactions);
        $transaction?->commit();
    }

    protected function rollback(): void
    {
        $transaction = array_pop($this->transactions);
        $transaction?->rollBack();
    }

    protected function createPost(string $slug, bool $published = true): object
    {
        return $this->save(new Post(['slug' => $slug, 'published' => $published]));
    }

    protected function createMultiPost(string $slug, bool $published, bool $amp): object
    {
        return $this->save(new MultiPost(['slug' => $slug, 'published' => $published, 'amp' => $amp]));
    }

    protected function createCategory(string $slug): object
    {
        return $this->save(new Category(['slug' => $slug]));
    }

    protected function createCategorizedPost(string $slug, ?object $category = null): object
    {
        \assert($category === null || $category instanceof Category);

        return $this->save(new CategorizedPost(['slug' => $slug, 'category_id' => $category?->id, 'updated_at' => 1]));
    }

    protected function createTag(string $name): object
    {
        return $this->save(new Tag(['name' => $name]));
    }

    protected function createUntracked(): object
    {
        return $this->save(new Untracked(['name' => 'x']));
    }

    protected function createBroken(): object
    {
        return $this->save(new Broken(['name' => 'x']));
    }

    protected function createBadAttribute(): object
    {
        return $this->save(new BadAttribute(['name' => 'x']));
    }

    protected function update(object $model, array $fields): void
    {
        \assert($model instanceof ActiveRecord);
        foreach ($fields as $field => $value) {
            $model->setAttribute($field, $value);
        }
        $model->save(false);
    }

    protected function delete(object $model): void
    {
        \assert($model instanceof ActiveRecord);
        $model->delete();
    }

    /** link() writes the junction row with a plain command; the owner is saved with a bumped timestamp (the documented recipe). */
    protected function attachTag(object $post, object $tag): void
    {
        \assert($post instanceof CategorizedPost && $tag instanceof Tag);
        $post->link('tags', $tag);
        $post->updated_at = ($post->updated_at ?? 0) + 1;
        $post->save(false);
    }

    protected function bulkUpdateTitle(string $title): void
    {
        Post::updateAll(['title' => $title]);
    }

    private function save(ActiveRecord $record): ActiveRecord
    {
        $record->save(false);

        return $record;
    }

    private function component(): IndexNowComponent
    {
        $component = $this->app->get('indexnow');
        \assert($component instanceof IndexNowComponent);

        return $component;
    }
}
