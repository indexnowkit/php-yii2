<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Yii2 fires commit/rollback events only for the outermost transaction and nothing for savepoints: changes made
 * inside a transaction are re-read by primary key when it commits, and dropped when the row does not show them.
 */
final class VerifyOnCommitTest extends Yii2TestCase
{
    #[TestDox('an update inside a savepoint that rolls back is dropped at the outer commit (the row still has the old title)')]
    public function testRolledBackUpdateIsNotSubmitted(): void
    {
        $post = new Post(['slug' => 'stable', 'title' => 'v1']);
        $post->save(false);
        $this->kit()->flush();
        $this->transport->posts = [];

        $outer = $this->app->getDb()->beginTransaction();
        $inner = $this->app->getDb()->beginTransaction();
        $post->title = 'v2';
        $post->save(false);
        $inner->rollBack();
        self::assertSame([], $this->transport->posts);
        $outer->commit();
        $this->kit()->flush();

        self::assertSame([], $this->sentUrls(), 'the re-read row carries v1, the change did not land');
        self::assertContains('indexnow: discarding 1 staged URL(s) of ' . Post::class . '#' . $post->id . ', change not committed', $this->logger->messages('debug'));
    }

    #[TestDox('a rename inside a rolled-back savepoint announces nothing: the old page still exists')]
    public function testRolledBackRenameDoesNotDeleteTheOldUrl(): void
    {
        $post = new Post(['slug' => 'before']);
        $post->save(false);
        $this->kit()->flush();
        $this->transport->posts = [];

        $outer = $this->app->getDb()->beginTransaction();
        $inner = $this->app->getDb()->beginTransaction();
        $post->slug = 'after';
        $post->save(false);
        $inner->rollBack();
        $outer->commit();
        $this->kit()->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('a committed update inside a transaction is delivered at COMMIT, verified against the row')]
    public function testCommittedUpdateIsSubmittedAtCommit(): void
    {
        $post = new Post(['slug' => 'live']);
        $post->save(false);
        $this->kit()->flush();
        $this->transport->posts = [];

        $tx = $this->app->getDb()->beginTransaction();
        $post->title = 'changed';
        $post->save(false);
        $this->kit()->flush();
        self::assertSame([], $this->sentUrls(), 'nothing leaves before COMMIT');
        $tx->commit();
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/live'], $this->sentUrls());
    }

    #[TestDox('a rollback of the outer transaction discards without re-reading anything')]
    public function testRollbackDiscards(): void
    {
        $tx = $this->app->getDb()->beginTransaction();
        (new Post(['slug' => 'gone']))->save(false);
        $tx->rollBack();
        $this->kit()->flush();

        self::assertSame([], $this->sentUrls());
        self::assertContains('indexnow: discarding 1 staged URL(s), transaction rolled back', $this->logger->messages('debug'));
    }
}
