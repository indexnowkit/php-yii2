<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Queue;

use RuntimeException;

/**
 * The one exception {@see SubmitUrlsJob::canRetry()} lets the queue driver retry (within `maxAttempts`). The job
 * itself no longer throws it: a 429/5xx or network failure re-pushes the rejected URLs with a delay (yii2 0.5.0).
 * A custom transport or submitter may throw it to get the driver's retry instead.
 */
final class RetryableSubmissionException extends RuntimeException {}
