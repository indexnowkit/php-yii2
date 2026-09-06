<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Queue;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use yii\queue\Queue;

/**
 * `dispatch: queue`: hands each flushed batch to {@see SubmitUrlsJob} on the yii2-queue component.
 */
final class QueueDispatcher implements DispatcherInterface
{
    /**
     * @param Closure(): Queue $queue resolved lazily: the component may not exist until the first flush
     */
    public function __construct(
        private readonly Closure $queue,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $ttr = 300,
        private readonly int $delay = 0,
        private readonly int|string|null $priority = null,
    ) {}

    /** One job per `batch.max_urls` URLs: a bulk import of 500 000 rows is many jobs the queue accepts, not one payload it rejects. */
    public function dispatch(array $urls): void
    {
        foreach (array_chunk($urls, max(1, $this->config->batchMaxUrls)) as $chunk) {
            $id = SubmitUrlsJob::newId();
            try {
                $job = new SubmitUrlsJob(['urls' => $chunk, 'id' => $id, 'maxAttempts' => $this->config->retryMaxAttempts, 'ttr' => $this->ttr]);
                $queue = ($this->queue)()->ttr($this->ttr);
                if ($this->delay > 0) {
                    $queue = $queue->delay($this->delay);
                }
                if ($this->priority !== null) {
                    $queue = $queue->priority($this->priority);
                }
                $queue->push($job);
                $this->logger->debug('indexnow: {count} URL(s) queued as job {id}', ['count' => \count($chunk), 'id' => $id, 'urls' => $this->config->logSample($chunk)]);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: cannot queue {count} URL(s) (job {id}), they are lost: {error}', ['count' => \count($chunk), 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => $this->config->logSample($chunk)]);
            }
        }
    }
}
