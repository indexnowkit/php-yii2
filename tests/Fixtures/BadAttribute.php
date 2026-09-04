<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/** #[IndexNow] without route or resolver: reading the rules throws. The save must survive. */
#[IndexNow(events: ['created'])]
final class BadAttribute extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'bad_attribute';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
