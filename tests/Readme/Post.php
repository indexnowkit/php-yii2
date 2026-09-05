<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Readme;

use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

#[IndexNowDefaults(when: 'published', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post/amp', params: ['slug' => 'slug'], when: 'amp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
final class Post extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public function init(): void
    {
        parent::init();
        $this->loadDefaultValues();   // `published` has a database default: make it visible before the first save
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }

    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
