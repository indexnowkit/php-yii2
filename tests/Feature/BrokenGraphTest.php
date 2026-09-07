<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A graph that cannot be built at all (here: a `urlResolver` override naming a component that does not exist) is one
 * error line from the ActiveRecord hook, not an exception out of `save()`, and the line is written once — the graph
 * is not going to appear between two saves.
 */
final class BrokenGraphTest extends Yii2TestCase
{
    protected function componentOverrides(): array
    {
        return ['urlResolver' => 'no-such-resolver'];
    }

    #[TestDox('a save() with an unbuildable graph does not throw and logs one error line, however many records are saved')]
    public function testSaveSurvivesAnUnbuildableGraph(): void
    {
        (new Post(['slug' => 'first']))->save(false);
        (new Post(['slug' => 'second']))->save(false);

        self::assertSame(2, (int) Post::find()->count());
        self::assertSame([], $this->sentUrls());
        self::assertCount(1, $this->logger->messages('error'));
        self::assertStringContainsString('the graph cannot be built', $this->logger->messages('error')[0]);
    }
}
