<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Queue;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\BatchingDispatcher;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use yii\queue\Queue;

/**
 * `dispatch: queue`: hands each flushed batch to {@see SubmitUrlsJob} on the yii2-queue component. The batching, the
 * correlation id and the "they are lost" log line are the core's `Dispatch\BatchingDispatcher`; this class is the
 * push onto the queue component.
 */
final class QueueDispatcher implements DispatcherInterface
{
    private readonly BatchingDispatcher $batches;

    /**
     * @param Closure(): Queue $queue resolved lazily: the component may not exist until the first flush
     */
    public function __construct(
        private readonly Closure $queue,
        private readonly Config $config,
        LoggerInterface $logger = new NullLogger(),
        private readonly int $ttr = 300,
        private readonly int $delay = 0,
        private readonly int|string|null $priority = null,
    ) {
        $this->batches = new BatchingDispatcher($this->push(...), $config, $logger, 'job');
    }

    public function dispatch(array $urls): void
    {
        $this->batches->dispatch($urls);
    }

    /**
     * @param list<string> $urls
     */
    private function push(array $urls, string $id): void
    {
        $job = new SubmitUrlsJob(['urls' => $urls, 'id' => $id, 'maxAttempts' => $this->config->retryMaxAttempts, 'ttr' => $this->ttr]);
        $queue = ($this->queue)()->ttr($this->ttr);
        if ($this->delay > 0) {
            $queue = $queue->delay($this->delay);
        }
        if ($this->priority !== null) {
            $queue = $queue->priority($this->priority);
        }
        $queue->push($job);
    }
}
