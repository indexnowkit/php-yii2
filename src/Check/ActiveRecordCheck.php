<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * Whether ActiveRecord changes reach IndexNow on their own.
 */
final class ActiveRecordCheck implements CheckInterface
{
    /**
     * @param list<class-string> $models classes hooked through `active_record.models`
     */
    public function __construct(private readonly bool $enabled, private readonly array $models) {}

    public function check(CheckReport $report): void
    {
        if (!$this->enabled) {
            $report->warning('active record: hooks are NOT active (active_record.enabled or enabled is false); use indexnow/submit or Yii::$app->indexnow->submit()', 'active_record.enabled');

            return;
        }
        $report->ok(\sprintf('active record: records using IndexNowBehavior%s are submitted automatically after commit (changes inside a transaction are verified on COMMIT)', $this->models !== [] ? ' and ' . implode(', ', $this->models) : ''), 'active_record.enabled');
    }
}
