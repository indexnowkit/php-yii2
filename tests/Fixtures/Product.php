<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeInterface;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Yii2\ActiveRecord\IndexNowBehavior;
use yii\db\ActiveRecord;

/**
 * A record whose columns the database spells its own way: a DECIMAL the driver pads to the scale of the column and a
 * timestamp it may hand back with a zone suffix. The record keeps the typed values (a float and a DateTimeImmutable)
 * the way `AttributeTypecastBehavior` would, so verify-on-commit sees exactly what an application holds.
 *
 * @property int                    $id
 * @property string                 $slug
 * @property float                  $price
 * @property DateTimeImmutable|null $released_at
 */
#[IndexNow(route: 'product/view', params: ['slug' => 'slug'])]
final class Product extends ActiveRecord
{
    private ?DateTimeInterface $released = null;

    public static function tableName(): string
    {
        return 'products';
    }

    public function behaviors(): array
    {
        return [IndexNowBehavior::class];
    }

    /**
     * @param bool $insert
     */
    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $released = $this->getAttribute('released_at');
        if ($released instanceof DateTimeInterface) {
            $this->released = $released;
            $this->setAttribute('released_at', $released->format('Y-m-d H:i:s'));
        }

        return true;
    }

    /**
     * @param bool                 $insert
     * @param array<string, mixed> $changedAttributes
     */
    public function afterSave($insert, $changedAttributes): void
    {
        if ($this->released !== null) {
            $this->setAttribute('released_at', $this->released);
        }
        parent::afterSave($insert, $changedAttributes);
    }
}
