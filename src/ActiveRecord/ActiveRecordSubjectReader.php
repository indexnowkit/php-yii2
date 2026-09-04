<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use IndexNowKit\Attribute\SubjectReaderInterface;
use Throwable;
use yii\db\BaseActiveRecord;

/**
 * Reads #[IndexNow] accessors off ActiveRecord instances, whose attributes are not PHP properties. Claims an
 * accessor when it is an attribute of the record or a relation (populated, or declared through a getter that
 * returns an ActiveQuery); everything else (a helper such as `isPublished()`, a real property) stays with the core
 * DSL, so a typo still raises the core's "no property, getter or method" error instead of a silent null.
 */
final class ActiveRecordSubjectReader implements SubjectReaderInterface
{
    public function supports(object $subject): bool
    {
        return $subject instanceof BaseActiveRecord;
    }

    public function has(object $subject, string $accessor): bool
    {
        if (!$subject instanceof BaseActiveRecord) {
            return false;
        }
        if ($subject->hasAttribute($accessor) || $subject->isRelationPopulated($accessor)) {
            return true;
        }
        try {
            return $subject->getRelation($accessor, false) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function read(object $subject, string $accessor): mixed
    {
        \assert($subject instanceof BaseActiveRecord);
        if ($subject->hasAttribute($accessor)) {
            return $subject->getAttribute($accessor);
        }

        // a relation: populated or lazily loaded through __get()
        return $subject->__get($accessor);
    }
}
