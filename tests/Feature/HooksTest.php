<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\Tests\Fixtures\ModelPost;
use IndexNowKit\Yii2\Tests\Fixtures\Tag;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class HooksTest extends Yii2TestCase
{
    #[TestDox('a class listed in active_record.models is hooked through class-level events, no behavior needed')]
    public function testModelsList(): void
    {
        $record = new ModelPost(['name' => 'listed']);
        $record->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/pages/listed'], $this->sentUrls());
        self::assertTrue($this->component()->observer()->isAttachedTo(ModelPost::class));
    }

    #[TestDox('observe() hooks a class and registers rules for it at runtime')]
    public function testObserve(): void
    {
        $this->component()->observe(Tag::class, [new IndexNow(route: 'page/view', params: ['slug' => 'name'])]);
        $tag = new Tag(['name' => 'observed']);
        $tag->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/pages/observed'], $this->sentUrls());
    }

    #[TestDox('submitRecords() is the manual path after updateAll(): one request for many records')]
    public function testSubmitRecords(): void
    {
        (new ModelPost(['name' => 'a']))->save(false);
        (new ModelPost(['name' => 'b']))->save(false);
        $this->kit()->flush();
        $this->transport->posts = [];
        ModelPost::updateAll(['name' => 'z']);
        self::assertSame([], $this->transport->posts, 'updateAll() fires no events (A13)');

        $this->component()->submitRecords(ModelPost::find()->all());

        self::assertCount(1, $this->transport->posts);
        self::assertSame(['https://www.example.com/pages/z'], $this->sentUrls());
    }
}
