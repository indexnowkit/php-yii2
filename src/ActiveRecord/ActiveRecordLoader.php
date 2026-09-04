<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use yii\db\ActiveRecord;

/**
 * Resolves the class argument of `indexnow/submit-record` and `indexnow/explain` (FQCN or a short name under the
 * configured namespaces, `app\models` by default) and loads records by primary key. Replace it through the
 * console controller's `loader` property for tenant scoping or another id format.
 */
final class ActiveRecordLoader implements SubjectLoaderInterface
{
    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(private readonly array $namespaces = ['app\\models']) {}

    /**
     * @return class-string<ActiveRecord>
     */
    public function resolveClass(string $class): string
    {
        $candidate = ltrim($class, '\\');
        if (!class_exists($candidate)) {
            foreach ($this->namespaces as $namespace) {
                if (class_exists($namespace . '\\' . $candidate)) {
                    $candidate = $namespace . '\\' . $candidate;
                    break;
                }
            }
        }
        if (!class_exists($candidate)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }
        if (!is_subclass_of($candidate, ActiveRecord::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an ActiveRecord class.', $candidate));
        }

        return $candidate;
    }

    public function byIds(string $class, array $ids, Event $event): array
    {
        $class = self::activeRecordClass($class);
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $record = $class::findOne($id);
            if ($record instanceof ActiveRecord) {
                $found[] = $record;
            } else {
                $missing[] = $id;
            }
        }

        return [$found, $missing];
    }

    public function all(string $class, int $limit, Event $event): iterable
    {
        $class = self::activeRecordClass($class);

        return $class::find()->limit(max(1, $limit))->all();
    }

    /**
     * @param class-string $class
     *
     * @return class-string<ActiveRecord>
     */
    private static function activeRecordClass(string $class): string
    {
        if (!is_subclass_of($class, ActiveRecord::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an ActiveRecord class.', $class));
        }

        return $class;
    }
}
