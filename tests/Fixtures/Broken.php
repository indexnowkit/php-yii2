<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/** The rule reads an attribute the record does not have: the resolver must fail without breaking the save. */
#[IndexNow(route: 'page/view', params: ['slug' => 'missingProperty'])]
final class Broken extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'broken';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
