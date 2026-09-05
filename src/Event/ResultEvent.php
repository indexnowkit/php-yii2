<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Event;

use IndexNowKit\Result;
use yii\base\Event;

/**
 * The Yii event the component triggers for every `Result` the submitter produces
 * (`IndexNowComponent::EVENT_RESULT`): `Yii::$app->indexnow->on(IndexNowComponent::EVENT_RESULT, fn (ResultEvent $e) => ...)`.
 */
final class ResultEvent extends Event
{
    public function __construct(public readonly Result $result, array $config = [])
    {
        parent::__construct($config);
    }
}
