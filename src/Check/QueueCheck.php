<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Yii2\App;
use yii\queue\sync\Queue as SyncQueue;

/**
 * `dispatch: queue` needs the yii2-queue component; the sync driver works but ignores the delay between attempts.
 */
final class QueueCheck implements CheckInterface
{
    /**
     * @param array<string, mixed> $options the component's options
     */
    public function __construct(private readonly array $options, private readonly string $dispatch, private readonly bool $queueExists) {}

    public function check(CheckReport $report): void
    {
        if ($this->dispatch !== 'queue') {
            $report->ok(\sprintf('dispatch "%s": URLs are %s', $this->dispatch, $this->dispatch === 'none' ? 'collected but never sent (drain the collector yourself)' : 'sent synchronously after the response is sent (or when the command ends); 429/5xx are not retried'));

            return;
        }
        $queue = \is_array($this->options['queue'] ?? null) ? $this->options['queue'] : [];
        $id = \is_string($queue['component'] ?? null) && $queue['component'] !== '' ? $queue['component'] : 'queue';
        if (!$this->queueExists) {
            $report->error(\sprintf('queue: component "%s" does not exist (needs yiisoft/yii2-queue); SubmitUrlsJob cannot be queued.', $id));

            return;
        }
        $component = App::component($id);
        if ($component instanceof SyncQueue) {
            $report->warning(\sprintf('queue: component "%s" is the sync driver, SubmitUrlsJob runs inline and the delay of a 429/5xx re-push is ignored (attempts run back-to-back). Use a real driver in production.', $id));

            return;
        }
        $report->ok(\sprintf('queue: SubmitUrlsJob goes to component "%s" (%s); run a worker (php yii %s/listen) or nothing is sent', $id, get_debug_type($component), $id));
    }
}
