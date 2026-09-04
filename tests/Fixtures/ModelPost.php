<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use yii\db\ActiveRecord;

/**
 * No behavior: hooked through the `active_record.models` list (class-level events).
 *
 * @property int    $id
 * @property string $name
 */
#[IndexNow(route: 'page/view', params: ['slug' => 'name'])]
final class ModelPost extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'model_posts';
    }
}
