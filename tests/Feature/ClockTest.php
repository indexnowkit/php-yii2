<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The `clock` property is one node of the graph, and every piece that reads the time takes it: the submission
 * timestamps of the submitter, the debounce window and the throttle. A `Testing\FrozenClock` therefore makes a whole
 * flush deterministic, the recorded history included.
 */
final class ClockTest extends Yii2TestCase
{
    private FrozenClock $clock;

    protected function optionOverrides(): array
    {
        return ['debounce' => ['per_url' => 600, 'store' => 'memory'], 'history' => ['store' => 'pdo']];
    }

    protected function componentOverrides(): array
    {
        return ['clock' => $this->clock = new FrozenClock('2026-09-07 08:00:00')];
    }

    #[TestDox('the clock node reaches the submission timestamps and the debounce window: a frozen clock freezes both, advancing it reopens the window')]
    public function testFrozenClockReachesTheSubmitterAndTheDebounceStore(): void
    {
        foreach (Schema::sql('sqlite') as $sql) {
            $this->app->getDb()->createCommand($sql)->execute();
        }
        $store = $this->component()->submissionStore();
        self::assertInstanceOf(PdoSubmissionStore::class, $store);

        (new Post(['slug' => 'timed']))->save(false);
        $this->kit()->flush();

        $records = [...$store->recent(5)];
        self::assertCount(1, $records);
        self::assertSame('2026-09-07 08:00:00', $records[0]->at->format('Y-m-d H:i:s'), 'the submitter records at the clock of the graph');

        $this->component()->collect(['https://www.example.com/posts/timed']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/timed'], $this->sentUrls(), 'the debounce window is still open on the frozen clock');

        $this->clock->advance(601);
        $this->component()->collect(['https://www.example.com/posts/timed']);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/timed', 'https://www.example.com/posts/timed'], $this->sentUrls(), 'the window expired on the advanced clock');
        $records = [...$store->recent(5)];
        self::assertSame('2026-09-07 08:10:01', $records[0]->at->format('Y-m-d H:i:s'));
    }
}
