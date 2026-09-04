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
 * Submits one batch of URLs from a yii2-queue worker. Retryable outcomes (429, 5xx, network) throw
 * {@see RetryableSubmissionException} so the queue retries (`canRetry()` allows `retry.max_attempts` attempts; the
 * delay between attempts is the queue driver's, `Retry-After` cannot be honoured); final failures (400, 403, 422)
 * are logged at error and end the job, a retry would not help. Successful URLs are debounced, so a retried job only
 * resends what was rejected.
 */
final class SubmitUrlsJob extends BaseObject implements RetryableJobInterface
{
    /** @var list<string> normalized absolute URLs */
    public array $urls = [];

    /** correlation id shared by the dispatch and worker log lines */
    public string $id = '';

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
            $logger->info(...$outcome->retryLog($this->id));

            throw new RetryableSubmissionException(\sprintf('IndexNow: %d URL(s) still rejected (job %s)', \count($outcome->retryUrls), $this->id));
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
}
