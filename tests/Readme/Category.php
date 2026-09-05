<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Readme;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/** The related record of the README model's `via: 'category'` rule; not part of the README text. */
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
