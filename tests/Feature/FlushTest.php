<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use yii\web\Response;

final class FlushTest extends Yii2TestCase
{
    #[TestDox('H06 record saved during a request -> nothing sent before the response, POST after EVENT_AFTER_SEND')]
    public function testFlushAfterResponseIsSent(): void
    {
        $post = new Post(['slug' => 'hello']);
        $post->save(false);

        self::assertCount(1, $this->kit()->collector->all(), 'the URL waits in the collector while the response is built');
        self::assertSame([], $this->transport->posts);

        \assert($this->app instanceof \yii\web\Application);
        $this->app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        self::assertSame(['https://www.example.com/posts/hello'], $this->sentUrls());
        self::assertSame('www.example.com', $this->transport->posts[0]['body']['host']);
        self::assertSame(self::KEY, $this->transport->posts[0]['body']['key']);
    }

    #[TestDox('A04 delete -> the URL resolved in beforeDelete is submitted after the row is gone')]
    public function testDelete(): void
    {
        $post = new Post(['slug' => 'bye']);
        $post->save(false);
        $this->kit()->flush();
        $post->delete();
        $this->kit()->flush();

        self::assertCount(2, $this->transport->posts);
        self::assertSame(['https://www.example.com/posts/bye'], $this->transport->posts[1]['body']['urlList']);
    }

    #[TestDox('a `self` parameter is the primary key value')]
    public function testSelfIsPrimaryKey(): void
    {
        $item = new \IndexNowKit\Yii2\Tests\Fixtures\Item(['name' => 'thing']);
        $item->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/items/' . $item->id], $this->sentUrls());
    }
}
