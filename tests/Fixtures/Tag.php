<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $name
 */
final class Tag extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'tags';
    }
}
