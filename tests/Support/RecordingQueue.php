<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Support;

use yii\queue\Queue;

/**
 * A yii2-queue driver that records every push (the job, ttr, delay, priority) instead of storing it, so a test
 * sees what {@see \IndexNowKit\Yii2\Queue\SubmitUrlsJob} re-pushes and with which delay.
 */
final class RecordingQueue extends Queue
{
    /** @var list<array{job: object, ttr: int, delay: int, priority: mixed}> */
    public array $pushed = [];

    /**
     * @param string $message
     * @param int    $ttr
     * @param int    $delay
     * @param mixed  $priority
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $job = $this->serializer->unserialize($message);
        \assert(\is_object($job));
        $this->pushed[] = ['job' => $job, 'ttr' => (int) $ttr, 'delay' => (int) $delay, 'priority' => $priority];

        return (string) \count($this->pushed);
    }

    /**
     * @param string $id
     */
    public function status($id): int
    {
        return self::STATUS_WAITING;
    }
}
