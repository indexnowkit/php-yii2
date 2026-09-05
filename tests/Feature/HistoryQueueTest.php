<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;
use yii\queue\sync\Queue as SyncQueue;

/**
 * `history.store: psr16` with `dispatch: queue`: the yii2-queue job's submission lands in the ring buffer of the
 * `cache` component, `status` names the queue component and its class, `check` describes the store.
 */
final class HistoryQueueTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dispatch' => 'queue', 'history' => ['store' => 'psr16', 'limit' => 10]];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['components' => ['queue' => ['class' => SyncQueue::class, 'handle' => false]], 'controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('the worker records into the psr16 store; history, status and check see it')]
    public function testTheWorkerRecords(): void
    {
        $component = $this->component();
        self::assertInstanceOf(Psr16SubmissionStore::class, $component->submissionStore());

        (new Post(['slug' => 'queued']))->save(false);
        $this->kit()->flush();
        self::assertSame([], $this->sentUrls(), 'nothing sent before the worker runs');

        $queue = $this->app->get('queue');
        \assert($queue instanceof SyncQueue);
        $queue->run();
        self::assertSame(['https://www.example.com/posts/queued'], $this->sentUrls());

        [$code, $output] = $this->yii('indexnow/history', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['https://www.example.com/posts/queued'], $decoded['records'][0]['urls']);

        [$code, $output] = $this->yii('indexnow/status', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        HistoryConsoleTest::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'queue', 'adapter' => ['component' => 'queue', 'class' => SyncQueue::class]], $decoded['dispatch']);
        self::assertSame('history', $decoded['history']['store']);
        self::assertSame(1, $decoded['history']['records']);
        self::assertSame(1, $decoded['history']['last_success']['urls']);

        [, $output] = $this->yii('indexnow/check');
        self::assertStringContainsString('history: psr16 store (10 records kept)', $output);
        self::assertMatchesRegularExpression('/history: 1 records?, last \d+ s ago/', $output);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{0: int, 1: string}
     */
    private function yii(string $route, array $params = []): array
    {
        \assert($this->app instanceof \yii\console\Application);
        $code = $this->app->runAction($route, $params);

        return [\is_int($code) ? $code : 0, $this->output->fetch()];
    }
}
