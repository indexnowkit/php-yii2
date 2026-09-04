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
