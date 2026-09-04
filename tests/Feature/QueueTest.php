<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Queue\RetryableSubmissionException;
use IndexNowKit\Yii2\Queue\SubmitUrlsJob;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use yii\queue\sync\Queue as SyncQueue;

final class QueueTest extends Yii2TestCase
{
    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dispatch' => 'auto', 'queue' => ['ttr' => 120, 'delay' => 0]];
    }

    protected function appOverrides(): array
    {
        return ['components' => ['queue' => ['class' => SyncQueue::class, 'handle' => true]]];
    }

    #[TestDox('dispatch auto resolves to queue when the queue component exists; the flushed batch becomes a SubmitUrlsJob the worker runs')]
    public function testQueueDispatch(): void
    {
        self::assertSame('queue', $this->component()->config()->dispatch);

        (new Post(['slug' => 'queued']))->save(false);
        self::assertSame([], $this->transport->posts);
        $this->kit()->flush();
        self::assertSame([], $this->transport->posts, 'the batch is a job now, not a request');
        $queue = $this->app->get('queue');
        \assert($queue instanceof SyncQueue);
        $queue->run();

        self::assertSame(['https://www.example.com/posts/queued'], $this->sentUrls(), 'the worker ran the job');
        self::assertNotSame([], array_filter($this->logger->messages('debug'), static fn(string $m): bool => str_contains($m, 'queued as job')));
    }

    #[TestDox('the job throws a retryable exception on 429/5xx so the queue retries within retry.max_attempts, and only logs on 403')]
    public function testJobRetrySemantics(): void
    {
        $queue = $this->app->get('queue');
        \assert($queue instanceof SyncQueue);
        $job = new SubmitUrlsJob(['urls' => ['https://www.example.com/r'], 'id' => 'job1', 'maxAttempts' => 3]);

        $this->transport->willRespond(new Response(429));
        try {
            $job->execute($queue);
            self::fail('expected a retryable exception');
        } catch (RetryableSubmissionException $e) {
            self::assertTrue($job->canRetry(1, $e));
            self::assertTrue($job->canRetry(2, $e));
            self::assertFalse($job->canRetry(3, $e), 'attempts are capped by retry.max_attempts');
        }
        self::assertFalse($job->canRetry(1, new RuntimeException('other')), 'only retryable outcomes are retried');

        $this->transport->willRespond(new Response(403));
        $job->execute($queue);
        self::assertNotSame([], array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'rejected permanently')));
        self::assertSame(120, (new SubmitUrlsJob(['ttr' => 120]))->getTtr());
    }
}
