<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\ResultStatus;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/history installed with `history.store: pdo` over the `db` component in a web application: the graph's
 * submission store is the PDO store and a flush after the response is recorded in the table.
 */
final class HistoryTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo']];
    }

    #[TestDox('a sync flush is recorded in the pdo store of the db component')]
    public function testFlushIsRecorded(): void
    {
        foreach (Schema::sql('sqlite') as $sql) {
            $this->app->getDb()->createCommand($sql)->execute();
        }
        $component = $this->component();
        self::assertTrue($component->historyEnabled());
        $store = $component->submissionStore();
        self::assertInstanceOf(PdoSubmissionStore::class, $store);

        (new Post(['slug' => 'recorded']))->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/recorded'], $this->sentUrls());
        self::assertSame(1, $store->count());
        $records = [...$store->recent(5, null, ResultStatus::Ok)];
        self::assertCount(1, $records);
        self::assertSame(['https://www.example.com/posts/recorded'], $records[0]->urls);
        self::assertSame('api', $records[0]->result->engine);
    }

    #[TestDox('without the table the flush still sends; the store failure is logged, not thrown')]
    public function testMissingTableDoesNotBreakTheFlush(): void
    {
        (new Post(['slug' => 'untabled']))->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/untabled'], $this->sentUrls());
        self::assertNotSame([], $this->logger->messages('error'));
    }
}
