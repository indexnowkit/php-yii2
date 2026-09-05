# Testing your integration

Configure the component with the core's test doubles and read what left:

```php
use IndexNowKit\Testing\{ArrayLogger, FakeTransport};

$transport = new FakeTransport();
$logger = new ArrayLogger();
$config['components']['indexnow'] = [
    'class' => \IndexNowKit\Yii2\IndexNowComponent::class,
    'options' => ['key' => str_repeat('a', 32), 'base_url' => 'https://www.example.com', 'dispatch' => 'sync', 'debounce' => ['per_url' => 0, 'store' => 'memory']],
    'transport' => $transport,
    'logger' => $logger,
];

$post = new Post(['slug' => 'hello']);
$post->save(false);
Yii::$app->indexnow->flush();                 // in a request this happens after the response is sent

$transport->posts[0]['body']['urlList'];      // ['https://www.example.com/posts/hello']
$logger->messages('error');                   // []
```

`FakeTransport::willRespond(new Response(429))` and `onGet($url, new Response(200, $key))` script the engines and the
key file; `ArrayLogger::messages($level)` returns interpolated lines.

The package's own suite (`tests/`) runs the core conformance kits (`OrmConformanceTestCase`, `CoreConformanceTestCase`)
through a web and a console application in memory; `Yii2TestCase` is a template for an application test case:
`Fixtures::webApp()` / `consoleApp()` build the application, `Fixtures::destroy()` unregisters Yii's error handler and
class-level events between tests.

## Without HTTP at all

`Yii::$app->indexnow->kit()->urlsFor($post)` (or `explain($post)`) returns the URLs a record would announce, with
the rule that produced each — the assertion for a rule test that should not build a transport.

## Transactions and verify-on-commit

Inside `Yii::$app->db->transaction()` (or an explicit `beginTransaction()`), URLs are held with a verifier and
re-read by primary key when the transaction commits; a rollback drops everything, an inner rollback the rows it
undid. In a test:

```php
$tx = Yii::$app->db->beginTransaction();
$post = new Post(['slug' => 'held']);
$post->save(false);
Yii::$app->indexnow->flush();
self::assertSame([], $transport->posts);        // nothing until the commit
$tx->commit();
Yii::$app->indexnow->flush();
self::assertCount(1, $transport->posts);        // now it left
```

A test that wraps every case in a transaction it rolls back at the end therefore never sees a submission: assert on
`urlsFor()` there, or commit. Details: [commit-safety.md](commit-safety.md).

## Queue

With `dispatch: queue` (or `auto` with a queue component) the batch becomes a `SubmitUrlsJob`; run it inline with
`yii\queue\sync\Queue` (`'queue' => ['class' => \yii\queue\sync\Queue::class, 'handle' => true]`) and assert on the
transport after `flush()`, or assert on the pushed job with a driver that records (`handle => false` and read
`Yii::$app->queue`). 429/5xx re-pushes are described in [queue.md](queue.md).

## dry_run

Outside `production_environments` a missing key enables `dry_run`: the whole pipeline runs — rules, guards, URL
generation, host and key selection — and the request is logged (`ArrayLogger::messages('info')` contains the body)
instead of sent. Set `dry_run: false` for a test that must reach the transport.

## Conformance

`tests/Conformance/` runs the core kits against this package (`CoreConformanceTestCase`: C01–C22 through the
component; `OrmConformanceTestCase`: A01–A21 through `IndexNowBehavior`; the H01–H06 assertions `KeyFileAssertions` /
`CheckOutputAssertions` through the key file action and the console). An application that replaces a piece (`urlResolver`, `dispatcher`) can extend
the same abstract cases to prove nothing regressed.
