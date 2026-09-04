# Commit safety

A URL must not reach the engines before the transaction that changed the row commits, or a rolled-back write is
announced. Yii2 makes this harder than Laravel or Doctrine:

- `yii\db\Connection` fires `EVENT_COMMIT_TRANSACTION` / `EVENT_ROLLBACK_TRANSACTION` **only for the outermost**
  transaction. Nested `beginTransaction()` calls are savepoints and fire nothing.
- The classes involved (`Transaction`, `Schema::createSavepoint()`) are not designed for interception without
  replacing the connection's command classes in your configuration.

## What the package does

1. In the ActiveRecord event the URLs are resolved while the old state is live (that is why the observer is
   synchronous, see the core's [adapters guide](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md)).
2. `$db->getTransaction()` is `null`: the change is autocommitted, the URLs go to the collector right away.
3. A transaction is open: the URLs are staged (core `Transaction\VerifyingStaging`) together with a **verifier**, a
   closure that re-reads the row by primary key with a plain `Query` (bypassing `find()` scopes) and answers whether
   the change landed:
   - insert / update / rename: the row exists and carries the written values (loose comparison, driver strings vs
     typed values);
   - delete: no row.
4. `EVENT_COMMIT_TRANSACTION`: the verifiers run, the URLs of the changes that landed go to the collector, the rest
   is dropped with a `debug` line (`discarding N staged URL(s) of Post#7, change not committed`). A change that did
   not land drops **every** URL it produced, including `via` pages and the old URL of a renamed page: announcing
   "deleted" for a page that still exists is the one outcome to avoid.
5. `EVENT_ROLLBACK_TRANSACTION`: everything staged on that connection is dropped without a query.

Cost: one `SELECT ... WHERE <pk> = ?` per changed record, only for changes made inside an explicit transaction.
Conformance A02, A05, A05b, A05c pass without touching the connection configuration.

## What it cannot tell

- An update inside a rolled-back savepoint whose written values happen to equal the row's current values
  (`UPDATE ... SET title = title`) passes verification and is sent as a harmless refresh of an existing page.
- A verifier that throws (connection gone) counts as landed and is logged at `warning`: a stale URL costs one
  crawl, a lost one costs the update.
- Records without a primary key cannot be verified; their URLs are sent.

## Long-running commands

Staged URLs are delivered on the commit event, so an import that commits every 1000 rows sends as it goes. Without
transactions the collector fills until the command ends (`collector.max_urls` caps it); call
`Yii::$app->indexnow->flush()` between batches if you prefer smaller requests.
