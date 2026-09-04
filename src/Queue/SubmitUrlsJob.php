<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Queue;

use IndexNowKit\Config;
use IndexNowKit\Retry\WorkerOutcome;
use IndexNowKit\Yii2\App;
use RuntimeException;
use yii\base\BaseObject;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * Submits one batch of URLs from a yii2-queue worker. Retryable outcomes (429, 5xx, network) re-push the rejected
 * URLs as a new job with the delay `Retry\RetryPolicy` computes from the component's `retry.*` (Retry-After wins),
 * the same `id` and `attempt + 1`, until `retry.max_attempts`; then the job ends successfully, so the queue's own
 * retry settings do not apply to them. Final failures (400, 403, 422) are logged at error and end the job, a retry
 * would not help. Successful URLs are debounced, so a re-pushed job only resends what was rejected.
 *
 * The sync driver ignores the delay: with `yii\queue\sync\Queue` the attempts run back-to-back (development only).
 * `canRetry()` is for exceptions only (the core's client never throws on HTTP or network failures): a
 * {@see RetryableSubmissionException} thrown by a custom transport or submitter is retried by the driver within
 * `maxAttempts`, anything else is not.
 */
final class SubmitUrlsJob extends BaseObject implements RetryableJobInterface
{
    /** @var list<string> normalized absolute URLs */
    public array $urls = [];

    /** correlation id shared by the dispatch and worker log lines, kept across re-pushes */
    public string $id = '';

    /** 1-based number of this attempt: the dispatcher pushes 1, a re-push carries the previous attempt + 1 */
    public int $attempt = 1;

    /** attempts the driver grants a job that throws {@see RetryableSubmissionException} ({@see canRetry()}) */
    public int $maxAttempts = Config::DEFAULT_RETRY_MAX_ATTEMPTS;

    public int $ttr = 300;

    /** Component id of {@see IndexNowComponent}. */
    public string $component = 'indexnow';

    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        $component = App::indexNow($this->component);
        if ($component === null) {
            throw new RuntimeException(\sprintf('indexnow: component "%s" is not configured in the worker application.', $this->component));
        }
        $logger = $component->logger();
        $outcome = WorkerOutcome::of($component->submitter()->submit($this->urls));
        if ($outcome->hasRetryable()) {
            $delay = $outcome->delay($component->config()->retryPolicy(), $this->attempt);
            if ($delay === null) {
                $logger->error(...$outcome->gaveUpLog($this->id, $this->attempt));
            } else {
                $logger->info(...$outcome->retryLog($this->id, $delay, $this->attempt));
                $queue->ttr($this->ttr)->delay($delay)->push($this->next($outcome->retryUrls));
            }
        }
        if ($outcome->hasFinalFailures()) {
            $logger->error(...$outcome->finalLog($this->id, 'php yii indexnow/check'));
        }
    }

    public function getTtr(): int
    {
        return $this->ttr;
    }

    public function canRetry($attempt, $error): bool
    {
        return $error instanceof RetryableSubmissionException && $attempt < $this->maxAttempts;
    }

    /**
     * The job of the next attempt: the rejected URLs, the same id, ttr and component.
     *
     * @param list<string> $urls
     */
    private function next(array $urls): self
    {
        return new self(['urls' => $urls, 'id' => $this->id, 'attempt' => $this->attempt + 1, 'maxAttempts' => $this->maxAttempts, 'ttr' => $this->ttr, 'component' => $this->component]);
    }
}
