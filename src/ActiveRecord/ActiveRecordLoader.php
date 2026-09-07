<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use IndexNowKit\Console\AbstractSubjectLoader;
use IndexNowKit\Event;
use yii\db\ActiveRecord;

/**
 * Resolves the class argument of `indexnow/submit-record` and `indexnow/explain` (FQCN or a short name under the
 * configured namespaces, `app\models` by default) and loads records by primary key. Replace it through the
 * console controller's `loader` property for tenant scoping or another id format. The skeleton is
 * `Console\AbstractSubjectLoader` of `indexnowkit/console`; what is here is Active Record: the marker, `findOne()`
 * and `find()->limit()`.
 */
final class ActiveRecordLoader extends AbstractSubjectLoader
{
    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(array $namespaces = ['app\\models'])
    {
        parent::__construct($namespaces, ActiveRecord::class, 'an ActiveRecord class');
    }

    protected function findOne(string $class, string $id, Event $event): ?object
    {
        \assert(is_subclass_of($class, ActiveRecord::class));
        $record = $class::findOne($id);

        return $record instanceof ActiveRecord ? $record : null;
    }

    protected function findMany(string $class, int $limit, Event $event): iterable
    {
        \assert(is_subclass_of($class, ActiveRecord::class));

        return $class::find()->limit($limit)->all();
    }
}
