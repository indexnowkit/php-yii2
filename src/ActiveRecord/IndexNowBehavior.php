<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use IndexNowKit\Yii2\App;
use yii\base\Behavior;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * Attaches the IndexNow observer to an ActiveRecord class: changes of the record are submitted according to its
 * #[IndexNow] rules, after the surrounding transaction commits.
 *
 *   #[IndexNow(route: 'post/view', params: ['slug' => 'slug'], when: 'published')]
 *   final class Post extends ActiveRecord
 *   {
 *       public function behaviors(): array { return [IndexNowBehavior::class]; }
 *   }
 *
 * The observer is the `indexnow` component's (one for every class); the behavior only forwards the four events.
 */
final class IndexNowBehavior extends Behavior
{
    /** Component id of {@see IndexNowComponent}. */
    public string $component = 'indexnow';

    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            BaseActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterInsert(Event $event): void
    {
        $this->observer()?->afterInsert($event);
    }

    public function afterUpdate(Event $event): void
    {
        $this->observer()?->afterUpdate($event);
    }

    public function beforeDelete(Event $event): void
    {
        $this->observer()?->beforeDelete($event);
    }

    public function afterDelete(Event $event): void
    {
        $this->observer()?->afterDelete($event);
    }

    /** Null when the component is not configured (the behavior stays inert instead of breaking the save). */
    private function observer(): ?IndexNowObserver
    {
        return App::indexNow($this->component)?->observer();
    }
}
