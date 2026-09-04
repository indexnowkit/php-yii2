<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\ResolvedUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use WeakMap;
use yii\base\Event as YiiEvent;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\db\Connection;
use yii\db\Query;

/**
 * ActiveRecord hooks. URLs are resolved in the event, while the old state is still live (`AfterSaveEvent::$changedAttributes`
 * carries the old values, `EVENT_BEFORE_DELETE` still sees the row and its relations). Outside a transaction they
 * go to the collector right away; inside one they are staged with a verifier and handed over on the connection's
 * EVENT_COMMIT_TRANSACTION, after a primary-key re-read confirmed the change (a savepoint rollback has no event in
 * Yii2, the re-read catches it); EVENT_ROLLBACK_TRANSACTION drops them.
 *
 * Nothing here throws into the application: the core's ObjectChangeHandler logs and yields nothing on a bad rule,
 * and every hand-off is guarded.
 */
final class IndexNowObserver
{
    /** ActiveRecord events the observer handles. */
    public const EVENTS = [BaseActiveRecord::EVENT_AFTER_INSERT, BaseActiveRecord::EVENT_AFTER_UPDATE, BaseActiveRecord::EVENT_BEFORE_DELETE, BaseActiveRecord::EVENT_AFTER_DELETE];

    /** @var WeakMap<BaseActiveRecord, list<string>> URLs resolved in beforeDelete, delivered in afterDelete */
    private WeakMap $pendingDeletions;

    /** @var WeakMap<Connection, true> connections whose commit/rollback events are already hooked */
    private WeakMap $hooked;

    /** @var array<class-string, true> classes hooked through class-level events (observe()/models) */
    private array $attached = [];

    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly VerifyingStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $enabled = true,
    ) {
        $this->pendingDeletions = new WeakMap();
        $this->hooked = new WeakMap();
    }

    /**
     * Class-level hook for a class without IndexNowBehavior (`active_record.models`, `observe()`).
     *
     * @param class-string<BaseActiveRecord> $class
     */
    public function attachTo(string $class): void
    {
        if (isset($this->attached[$class])) {
            return;
        }
        $this->attached[$class] = true;
        YiiEvent::on($class, BaseActiveRecord::EVENT_AFTER_INSERT, $this->afterInsert(...));
        YiiEvent::on($class, BaseActiveRecord::EVENT_AFTER_UPDATE, $this->afterUpdate(...));
        YiiEvent::on($class, BaseActiveRecord::EVENT_BEFORE_DELETE, $this->beforeDelete(...));
        YiiEvent::on($class, BaseActiveRecord::EVENT_AFTER_DELETE, $this->afterDelete(...));
    }

    /**
     * @param class-string $class
     */
    public function isAttachedTo(string $class): bool
    {
        return isset($this->attached[$class]);
    }

    public function afterInsert(YiiEvent $event): void
    {
        $record = self::record($event);
        if ($record === null) {
            return;
        }
        // columns left null get their database default: only what the record set is compared
        $written = array_filter($record->getAttributes(), static fn(mixed $v): bool => $v !== null);
        $this->guard($record, fn(): array => $this->indexNow->changes()->created($record), fn(): bool => $this->rowMatches($record, $written));
    }

    public function afterUpdate(YiiEvent $event): void
    {
        $record = self::record($event);
        if ($record === null) {
            return;
        }
        $old = $event instanceof AfterSaveEvent && \is_array($event->changedAttributes) ? $event->changedAttributes : [];
        if ($old === []) {
            return; // save() without a change
        }
        $changeSet = [];
        $expected = [];
        foreach ($old as $field => $previous) {
            $changeSet[(string) $field] = [$previous, $record->getAttribute((string) $field)];
            $expected[(string) $field] = $record->getAttribute((string) $field);
        }
        $this->guard($record, function () use ($record, $changeSet): array {
            $changes = $this->indexNow->changes();

            return [
                ...$changes->renamed($record, $changeSet, $this->previousState($record, $changeSet), self::primaryKeyFields($record)),
                ...$changes->updated($record, array_keys($changeSet), $changeSet),
            ];
        }, fn(): bool => $this->rowMatches($record, $expected));
    }

    /** Before the row disappears: resolve now, deliver in afterDelete(). */
    public function beforeDelete(YiiEvent $event): void
    {
        $record = self::record($event);
        if ($record === null || !$this->enabled) {
            return;
        }
        try {
            $this->pendingDeletions[$record] = ResolvedUrl::urls($this->indexNow->changes()->deleted($record));
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve the URLs of {class} before deletion: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    public function afterDelete(YiiEvent $event): void
    {
        $record = self::record($event);
        if ($record === null || !$this->enabled) {
            return;
        }
        $pk = self::primaryKey($record);
        $urls = $this->pendingDeletions[$record] ?? null;
        unset($this->pendingDeletions[$record]);
        $verifier = fn(): bool => $this->rowByPrimaryKey($record, $pk) === null;
        if ($urls === null) {
            // beforeDelete() was not seen; the record still carries its attributes after deleteInternal().
            $this->guard($record, fn(): array => $this->indexNow->changes()->deleted($record), $verifier);

            return;
        }
        $this->handOff($record, $urls, $verifier);
    }

    /**
     * @param callable(): list<ResolvedUrl> $resolve
     * @param callable(): bool              $verifier
     */
    private function guard(BaseActiveRecord $record, callable $resolve, callable $verifier): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $resolved = $resolve();
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve the URLs of {class}: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        foreach ($resolved as $item) {
            $this->logger->debug('indexnow: {source} ({event}) -> {url}', ['source' => $item->source(), 'event' => $item->event->value, 'url' => $item->url]);
        }
        $this->handOff($record, ResolvedUrl::urls($resolved), $verifier);
    }

    /**
     * Inside a transaction the URLs wait for COMMIT (and its verification); outside they go to the collector now.
     *
     * @param list<string>     $urls
     * @param callable(): bool $verifier
     */
    private function handOff(BaseActiveRecord $record, array $urls, callable $verifier): void
    {
        if ($urls === []) {
            return;
        }
        try {
            $db = $record::getDb();
            \assert($db instanceof Connection);
            if ($db->getTransaction() !== null) {
                $this->hookConnection($db);
                $this->staging->stage($db, $verifier, $urls, self::describe($record));

                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot inspect the transaction state of {class}: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
        $this->deliver($urls);
    }

    private function hookConnection(Connection $db): void
    {
        if (isset($this->hooked[$db])) {
            return;
        }
        $this->hooked[$db] = true;
        $db->on(Connection::EVENT_COMMIT_TRANSACTION, function () use ($db): void {
            $this->deliver($this->staging->flush($db));
        });
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, function () use ($db): void {
            $this->staging->discard($db);
        });
    }

    /**
     * @param list<string> $urls
     */
    private function deliver(array $urls): void
    {
        if ($urls === []) {
            return;
        }
        try {
            $this->indexNow->collect($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot collect {count} URL(s): {error}', ['count' => \count($urls), 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function rowMatches(BaseActiveRecord $record, array $expected): bool
    {
        return VerifyingStaging::rowMatches($this->rowByPrimaryKey($record, self::primaryKey($record)), $expected);
    }

    /**
     * The row as it is in the database now: a plain query on the table, bypassing find() (default scopes, soft
     * delete conditions) and the identity of the record.
     *
     * @param array<string, mixed> $pk
     *
     * @return array<string, mixed>|null
     */
    private function rowByPrimaryKey(BaseActiveRecord $record, array $pk): ?array
    {
        if ($pk === [] || !$record instanceof ActiveRecord) {
            throw new RuntimeException(\sprintf('%s has no primary key to verify the change by.', $record::class));
        }
        $row = (new Query())->from($record::tableName())->where($pk)->one($record::getDb());

        return \is_array($row) ? $row : null;
    }

    /**
     * A copy of the record as it was before the update (old attribute values, relations dropped so they reload for
     * the old foreign keys), used to resolve the URLs a renamed page had.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function previousState(BaseActiveRecord $record, array $changeSet): BaseActiveRecord
    {
        $previous = clone $record;
        foreach ($changeSet as $field => [$old]) {
            $previous->setAttribute($field, $old);
        }
        foreach (array_keys($record->getRelatedRecords()) as $relation) {
            $previous->__unset((string) $relation);
        }

        return $previous;
    }

    /**
     * Fields a `self` route parameter depends on: Yii has no route model binding, so `self` is the primary key.
     *
     * @return list<string>
     */
    private static function primaryKeyFields(BaseActiveRecord $record): array
    {
        // any rule with a `self` param depends on the key; without such rules the list is harmless
        return $record instanceof ActiveRecord ? array_values(array_map(strval(...), $record::getTableSchema()->primaryKey)) : [];
    }

    private static function record(YiiEvent $event): ?BaseActiveRecord
    {
        return $event->sender instanceof BaseActiveRecord ? $event->sender : null;
    }

    private static function describe(BaseActiveRecord $record): string
    {
        return $record::class . '#' . implode(',', array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : get_debug_type($v), self::primaryKey($record)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function primaryKey(BaseActiveRecord $record): array
    {
        $pk = $record->getPrimaryKey(true);
        $keys = [];
        foreach (\is_array($pk) ? $pk : [] as $column => $value) {
            $keys[(string) $column] = $value;
        }

        return $keys;
    }
}
