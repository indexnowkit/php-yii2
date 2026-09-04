<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Yii2\ActiveRecord\ActiveRecordSubjectReader;
use IndexNowKit\Yii2\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Yii2\Tests\Fixtures\Category;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use stdClass;

final class SubjectReaderTest extends Yii2TestCase
{
    #[TestDox('attributes and relations are claimed and read; methods and unknown names are left to the core DSL')]
    public function testReader(): void
    {
        $reader = new ActiveRecordSubjectReader();
        $category = new Category(['slug' => 'news']);
        $category->save(false);
        $post = new CategorizedPost(['slug' => 'p', 'category_id' => $category->id]);
        $post->save(false);

        self::assertTrue($reader->supports($post));
        self::assertFalse($reader->supports(new stdClass()));
        self::assertTrue($reader->has($post, 'slug'));
        self::assertSame('p', $reader->read($post, 'slug'));
        self::assertTrue($reader->has($post, 'category'), 'a relation declared by getCategory()');
        $related = $reader->read($post, 'category');
        self::assertInstanceOf(Category::class, $related);
        self::assertSame('news', $related->slug);
        self::assertFalse($reader->has($post, 'nope'));
        self::assertFalse($reader->has($post, 'tableName'), 'methods stay with the core DSL');
    }
}
