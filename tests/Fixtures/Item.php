<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/**
 * `self` route parameter: the primary key value.
 *
 * @property int    $id
 * @property string $name
 */
#[IndexNow(route: 'item/view', params: ['id' => 'self'])]
final class Item extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'items';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
