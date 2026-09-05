<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use yii\queue\sync\Queue as SyncQueue;

/**
 * `verify.enabled: true` with `dispatch: queue`: the job submits through the graph's decorated submitter, so the
 * pre-flight runs in the worker.
 */
final class VerifyQueueTest extends Yii2TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dispatch' => 'queue', 'verify' => ['enabled' => true]];
    }

    protected function appOverrides(): array
    {
        return ['components' => ['queue' => ['class' => SyncQueue::class, 'handle' => false]]];
    }

    #[TestDox('the worker verifies: the noindex post is dropped, the other one sent')]
    public function testTheWorkerVerifies(): void
    {
        $this->transport
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '', headers: ['X-Robots-Tag' => 'noindex']));

        (new Post(['slug' => 'fine']))->save(false);
        (new Post(['slug' => 'hidden']))->save(false);
        $this->kit()->flush();
        self::assertSame([], $this->transport->gets, 'no pre-flight before the worker runs');

        $queue = $this->app->get('queue');
        \assert($queue instanceof SyncQueue);
        $queue->run();

        self::assertSame(['https://www.example.com/posts/fine'], $this->sentUrls());
        self::assertContains('https://www.example.com/posts/hidden', $this->transport->gets);
    }
}
