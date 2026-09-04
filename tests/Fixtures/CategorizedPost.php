<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * A post that resubmits its category's page (`via`). Tags live in a junction table: link()/unlink() fire no events
 * on the owner, so the conformance driver bumps `updated_at` afterwards (the documented recipe).
 *
 * @property int      $id
 * @property string   $slug
 * @property int      $views
 * @property ?int     $category_id
 * @property ?int     $updated_at
 * @property Category $category
 */
#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]
#[IndexNow(via: 'category')]
final class CategorizedPost extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'categorized_posts';
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

    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    public function getTags(): ActiveQuery
    {
        return $this->hasMany(Tag::class, ['id' => 'tag_id'])->viaTable('categorized_post_tags', ['post_id' => 'id']);
    }
}
