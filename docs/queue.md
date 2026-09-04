# Queue, retries, failures

`dispatch: auto` (the default) is `queue` when the `queue` component exists. Every batch the collector flushes (end
of a request, a console command or a handled job) becomes one `IndexNowKit\Yii2\Queue\SubmitUrlsJob` carrying the
normalized URLs, pushed with `queue.ttr`, `queue.delay` and `queue.priority`.

```php
'dispatch' => 'queue',
'queue' => ['component' => 'queue', 'ttr' => 300, 'delay' => 0, 'priority' => null],
'retry' => ['max_attempts' => 3, 'base_delay' => 60, 'multiplier' => 2.0, 'max_delay' => 3600, 'server_error_delay' => 5],
```

## What the worker does

1. `SubmitterInterface::submit($urls)`: debounce, group by host, split by `batch.max_urls`, one request per engine.
2. Retryable outcomes (429, 5xx, network failure): the job **re-pushes the rejected URLs itself** as a new
   `SubmitUrlsJob` with the same `id` (the log lines stay connected), `attempt + 1`, the same `ttr`, and a delay, then
   ends successfully. The delay is `Retry-After` when the engine sent one, else `base_delay × multiplier^(attempt-1)`
   for 429 and `server_error_delay × multiplier^(attempt-1)` for 5xx/network, capped at `max_delay` (the core's
   `Retry\RetryPolicy`, from the `retry.*` options of the component). At `retry.max_attempts` the job logs
   `indexnow: giving up on N URL(s) of job <id> after N attempt(s)` at error and ends.
3. Final rejections (400, 403, 422): `indexnow: N URL(s) of job <id> rejected permanently (...); run "php yii
   indexnow/check"` at error, the job ends. 403 means the key file is not reachable under `https://<host>/<key>.txt`.
4. Anything else ends the job. URLs that were accepted are recorded in the debounce store, so a re-pushed job only
   resends what was rejected.

The queue's own retry settings (`attempts` of the driver, `ttr`) do not apply to 429/5xx: the job never throws for
them. `SubmitUrlsJob::canRetry()` (`RetryableJobInterface`) only concerns exceptions: the core's client never throws
on HTTP or network failures, but a custom transport or submitter may throw `Queue\RetryableSubmissionException` to
get the driver's retry within `maxAttempts`; any other exception is not retried.

## The sync driver

`yii\queue\sync\Queue` stores the pushes and runs them in `run()` (with `handle: true`, at the end of the request),
ignoring the delay: the re-pushed attempts run back-to-back in the same `run()`, up to `retry.max_attempts`. Fine
for development; `php yii indexnow/check` warns about it. Use db, redis, amqp or another real driver in production.

## Workers

Long-running workers flush the collector after every handled job (`Queue::EVENT_AFTER_EXEC` and `EVENT_AFTER_ERROR`),
so a job that saves records submits their URLs in a follow-up batch on the same queue.

## Synchronous delivery

`dispatch: sync` sends after the response has been sent (or when the command ends). No retries: a 429 is logged and
the URLs are lost until the next change.

## Checking the wiring

`php yii indexnow/check` prints the dispatch mode and the queue component the job goes to, warns when the component
is the sync driver, and fails when `dispatch: queue` names a component that does not exist.
