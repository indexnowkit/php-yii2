<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Queue\RetryableSubmissionException;
use IndexNowKit\Yii2\Queue\SubmitUrlsJob;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Support\RecordingQueue;
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
        return ['dispatch' => 'auto', 'queue' => ['ttr' => 120, 'delay' => 0], 'retry' => ['max_attempts' => 3, 'server_error_delay' => 7, 'multiplier' => 2.0]];
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

    #[TestDox('429 with Retry-After: the rejected URLs are re-pushed with that delay, the same id and attempt + 1, and the job ends')]
    public function testRetryAfterIsHonoured(): void
    {
        $queue = new RecordingQueue();
        $job = new SubmitUrlsJob(['urls' => ['https://www.example.com/a', 'https://www.example.com/b'], 'id' => 'job1', 'ttr' => 120]);

        $this->transport->willRespond(new Response(429, '', 90));
        $job->execute($queue);

        self::assertCount(1, $queue->pushed);
        ['job' => $next, 'ttr' => $ttr, 'delay' => $delay] = $queue->pushed[0];
        self::assertInstanceOf(SubmitUrlsJob::class, $next);
        self::assertSame(90, $delay, 'Retry-After wins over the policy');
        self::assertSame(120, $ttr);
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $next->urls);
        self::assertSame('job1', $next->id);
        self::assertSame(2, $next->attempt);
        self::assertNotSame([], array_filter($this->logger->messages('info'), static fn(string $m): bool => str_contains($m, 'job job1 will be retried in 90s (attempt 1)')));
        self::assertSame([], $this->logger->messages('error'));
    }

    #[TestDox('5xx without Retry-After: the delay comes from retry.* of the component (server_error_delay × multiplier^(attempt-1))')]
    public function testPolicyDelayForServerErrors(): void
    {
        $queue = new RecordingQueue();
        $job = new SubmitUrlsJob(['urls' => ['https://www.example.com/a'], 'id' => 'job2', 'attempt' => 2]);

        $this->transport->willRespond(new Response(503));
        $job->execute($queue);

        self::assertCount(1, $queue->pushed);
        self::assertSame(14, $queue->pushed[0]['delay'], '7 s × 2^(2-1)');
        $next = $queue->pushed[0]['job'];
        self::assertInstanceOf(SubmitUrlsJob::class, $next);
        self::assertSame(3, $next->attempt);
    }

    #[TestDox('at retry.max_attempts the job gives up: an error line, nothing re-pushed')]
    public function testGivesUpAtMaxAttempts(): void
    {
        $queue = new RecordingQueue();
        $job = new SubmitUrlsJob(['urls' => ['https://www.example.com/a'], 'id' => 'job3', 'attempt' => 3]);

        $this->transport->willRespond(new Response(429, '', 90));
        $job->execute($queue);

        self::assertSame([], $queue->pushed);
        self::assertNotSame([], array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'giving up on 1 URL(s) of job job3 after 3 attempt(s)')));
    }

    #[TestDox('final failures (403) are logged and end the job without a re-push; canRetry() stays for RetryableSubmissionException only')]
    public function testFinalFailuresAndCanRetry(): void
    {
        $queue = new RecordingQueue();
        $job = new SubmitUrlsJob(['urls' => ['https://www.example.com/a'], 'id' => 'job4', 'maxAttempts' => 3]);

        $this->transport->willRespond(new Response(403));
        $job->execute($queue);

        self::assertSame([], $queue->pushed);
        self::assertNotSame([], array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'rejected permanently')));

        $retryable = new RetryableSubmissionException('transport');
        self::assertTrue($job->canRetry(1, $retryable));
        self::assertTrue($job->canRetry(2, $retryable));
        self::assertFalse($job->canRetry(3, $retryable), 'attempts are capped by maxAttempts');
        self::assertFalse($job->canRetry(1, new RuntimeException('other')), 'only the retryable exception is retried');
        self::assertSame(120, (new SubmitUrlsJob(['ttr' => 120]))->getTtr());
    }

    #[TestDox('the sync driver ignores the delay: the re-pushed attempts run back-to-back in the same run() until retry.max_attempts')]
    public function testSyncDriverRunsAttemptsInline(): void
    {
        $queue = $this->app->get('queue');
        \assert($queue instanceof SyncQueue);
        $queue->push(new SubmitUrlsJob(['urls' => ['https://www.example.com/a'], 'id' => 'job5']));

        $this->transport->willRespond(new Response(503), new Response(503), new Response(503));
        $queue->run();

        self::assertCount(3, $this->transport->posts, 'three attempts, back-to-back');
        self::assertNotSame([], array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'giving up on 1 URL(s) of job job5 after 3 attempt(s)')));
    }
}
