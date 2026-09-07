<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\ActiveRecord;

use Closure;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\Transaction\VerifyingStaging;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SplObjectStorage;
use Throwable;
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
 * every hand-off is guarded by `Hook\ObserverHelper`, and the helper itself is built inside a try/catch on the first
 * hook — a graph that cannot be built (a router the container does not know) is one error line, not a broken save.
 * The hook resolves URLs over `Adapter\Services::changes()`, so the first `save()` of a model builds neither the
 * client nor the transport; only a URL that was actually resolved builds the collector. What is Yii's: the change set
 * from `changedAttributes`, the previous state, the verify-on-commit staging over the connection's events.
 */
final class IndexNowObserver
{
    /** ActiveRecord events the observer handles. */
    public const EVENTS = [BaseActiveRecord::EVENT_AFTER_INSERT, BaseActiveRecord::EVENT_AFTER_UPDATE, BaseActiveRecord::EVENT_BEFORE_DELETE, BaseActiveRecord::EVENT_AFTER_DELETE];

    private ?ObserverHelper $helper = null;

    /** Whether building the helper failed once: the graph is not going to appear, and one line per save is noise. */
    private bool $helperFailed = false;

    /** @var SplObjectStorage<Connection, true> connections whose commit/rollback events are already hooked (application-long objects) */
    private SplObjectStorage $hooked;

    /** @var array<class-string, true> classes hooked through class-level events (observe()/models) */
    private array $attached = [];

    /**
     * @param Closure(): Services $services the graph of the component, asked for on the first hook that resolves URLs
     */
    public function __construct(
        private readonly Closure $services,
        private readonly VerifyingStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $enabled = true,
    ) {
        $this->hooked = new SplObjectStorage();
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
        // Columns left null get their database default: only what the record wrote is compared, and of that the core
        // compares only the values every driver spells the same way (a DECIMAL, a date or a JSON document is skipped,
        // which is what used to drop the announcement of every new page of such a class). The row's mere presence is
        // not enough here: a savepoint rollback frees the primary key and the next insert takes it back, so an insert
        // is told from its successor by the values, and it is staged unkeyed — a reused key must not merge.
        $written = array_filter($record->getAttributes(), static fn(mixed $v): bool => $v !== null);
        $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->created($record), fn(): bool => $this->rowMatches($record, $written), false);
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
        $this->guard($record, fn(ObjectChangeHandler $changes): array => [
            ...$changes->renamed($record, $changeSet, $this->previousState($record, $changeSet), self::primaryKeyFields($record)),
            ...$changes->updated($record, array_keys($changeSet), $changeSet),
        ], fn(): bool => $this->rowMatches($record, $expected));
    }

    /** Before the row disappears: resolve now, deliver in afterDelete(). */
    public function beforeDelete(YiiEvent $event): void
    {
        $record = self::record($event);
        $helper = $record === null || !$this->enabled ? null : $this->helper();
        if ($record === null || $helper === null) {
            return;
        }
        $urls = $helper->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record));
        if ($urls !== null) {
            $helper->rememberDeletion($record, $urls);
        }
    }

    public function afterDelete(YiiEvent $event): void
    {
        $record = self::record($event);
        $helper = $record === null || !$this->enabled ? null : $this->helper();
        if ($record === null || $helper === null) {
            return;
        }
        $pk = self::primaryKey($record);
        $urls = $helper->takeDeletion($record);
        $verifier = fn(): bool => $this->rowByPrimaryKey($record, $pk) === null;
        if ($urls === null) {
            // beforeDelete() was not seen; the record still carries its attributes after deleteInternal().
            $this->guard($record, static fn(ObjectChangeHandler $changes): array => $changes->deleted($record), $verifier);

            return;
        }
        $this->handOff($record, $urls, $verifier);
    }

    /**
     * The change handler and the collector of the component's graph, built on the first hook that resolves URLs and
     * never after a failure: `Services::changes()` is the resolver, the rules and the extractor and nothing else, so
     * the client, the transport and the debounce store stay unbuilt until a URL is actually collected. A graph that
     * cannot be built at all (an `http.client` id the container does not know, a router that throws) is one error
     * line here instead of an exception out of `save()`.
     */
    private function helper(): ?ObserverHelper
    {
        if ($this->helper === null && !$this->helperFailed) {
            try {
                $services = ($this->services)();
                $this->helper = ObserverHelper::forChanges($services->changes(), static function (array $urls) use ($services): void {
                    $services->kit()->collect($urls);
                }, $this->logger);
            } catch (Throwable $e) {
                $this->helperFailed = true;
                $this->logger->error('indexnow: the graph cannot be built, ActiveRecord changes are not submitted: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }

        return $this->helper;
    }

    /**
     * @param callable(ObjectChangeHandler): list<ResolvedUrl> $resolve
     * @param callable(): bool                                 $verifier
     * @param bool                                             $merge    whether a later change of the same record joins this one ({@see handOff()})
     */
    private function guard(BaseActiveRecord $record, callable $resolve, callable $verifier, bool $merge = true): void
    {
        $helper = $this->enabled ? $this->helper() : null;
        if ($helper === null) {
            return;
        }
        $urls = $helper->guard($record, $resolve);
        if ($urls !== null) {
            $this->handOff($record, $urls, $verifier, $merge);
        }
    }

    /**
     * Inside a transaction the URLs wait for COMMIT (and its verification); outside they go to the collector now.
     *
     * An update or a delete is staged under the subject key (`class#id`), so a second change of the same record in
     * the same transaction joins the first instead of discarding it: the verifiers run once, at the end, against the
     * row as the last change left it, and the first change's expected values are no longer there. An insert is staged
     * unkeyed, because the key is not stable across a savepoint rollback — the rolled-back row's primary key is free
     * again and the next insert takes it, so merging under it would join the URLs of a row that never survived.
     *
     * @param list<string>     $urls
     * @param callable(): bool $verifier
     */
    private function handOff(BaseActiveRecord $record, array $urls, callable $verifier, bool $merge = true): void
    {
        if ($urls === []) {
            return;
        }
        try {
            $db = $record::getDb();
            \assert($db instanceof Connection);
            if ($db->getTransaction() !== null) {
                $this->hookConnection($db);
                $subject = self::describe($record);
                $this->staging->stage($db, $verifier, $urls, $subject, $merge && self::primaryKey($record) !== [] ? $subject : null);

                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot inspect the transaction state of {class}: {error}', ['class' => $record::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
        $this->deliver($urls);
    }

    private function hookConnection(Connection $db): void
    {
        if ($this->hooked->offsetExists($db)) {
            return;
        }
        $this->hooked->offsetSet($db, true);
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
        $this->helper()?->deliver($urls);
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
     * delete conditions) and the identity of the record. Only a change made inside a transaction is ever re-read;
     * without a primary key the throw is caught by the staging, which warns and submits the change unverified.
     *
     * @param array<string, mixed> $pk
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException when the record has no primary key to re-read the row by
     */
    private function rowByPrimaryKey(BaseActiveRecord $record, array $pk): ?array
    {
        if ($pk === [] || !$record instanceof ActiveRecord) {
            throw new RuntimeException(\sprintf('%s has no primary key to verify the change by, and verify-on-commit re-reads the row by its primary key. Declare primaryKey() on the record class. Until then a change made inside a transaction is submitted unverified (one warning per change, the URLs still go out), and a change made outside a transaction is not verified at all — it goes to the collector as it happens.', $record::class));
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
