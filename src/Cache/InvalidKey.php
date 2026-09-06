<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Cache;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/** A key {@see Psr16Cache} refuses: PSR-16 requires this exception for reserved characters and over-long keys. */
final class InvalidKey extends InvalidArgumentException implements Psr16InvalidArgument {}
