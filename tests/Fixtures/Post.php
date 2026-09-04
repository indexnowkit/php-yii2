<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $slug
 * @property string $title
 * @property bool   $published
 * @property int    $views
 */
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'], when: 'published', fields: ['slug', 'title', 'published'])]
final class Post extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'posts';
    }

    /** Eloquent-style lesson applies here too: a `when` column with only a database default is null on a fresh record. */
    public function init(): void
    {
        parent::init();
        $this->loadDefaultValues();
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }
}
