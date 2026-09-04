<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/** Behavior attached, no #[IndexNow] rule: saving it must be a no-op for IndexNow. */
final class Untracked extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'untracked';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
