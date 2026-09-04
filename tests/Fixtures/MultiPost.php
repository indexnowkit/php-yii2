<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/**
 * Two rules on one record (article page and AMP page) plus the homepage, each classified separately.
 *
 * @property int    $id
 * @property string $slug
 * @property bool   $published
 * @property bool   $amp
 */
#[IndexNowDefaults(when: 'published')]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp', name: 'amp')]
#[IndexNow(urls: ['/'])]
final class MultiPost extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'multi_posts';
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
