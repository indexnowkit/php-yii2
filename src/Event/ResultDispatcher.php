<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Event;

use IndexNowKit\Result;
use IndexNowKit\Yii2\IndexNowComponent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The PSR-14 dispatcher the submitter publishes to, raising `IndexNowComponent::EVENT_RESULT` on the component with a
 * {@see ResultEvent} for every `Result`; other event objects pass through untouched.
 */
final class ResultDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly IndexNowComponent $component) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof Result) {
            $this->component->trigger(IndexNowComponent::EVENT_RESULT, new ResultEvent($event));
        }

        return $event;
    }
}
