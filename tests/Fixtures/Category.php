<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $slug
 */
#[IndexNow(route: 'category/view', params: ['slug' => 'slug'])]
final class Category extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'categories';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
