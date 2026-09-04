<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Queue;

use RuntimeException;

/**
 * Thrown by {@see SubmitUrlsJob} when an engine answered 429/5xx or the network failed: the queue retries the job.
 */
final class RetryableSubmissionException extends RuntimeException {}
