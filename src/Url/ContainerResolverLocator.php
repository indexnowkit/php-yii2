<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Url;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\App;
use Throwable;
use Yii;

/**
 * #[IndexNow(resolver: ...)] values are resolved through Yii: an application component id, a DI container
 * definition, or any class `Yii::createObject()` can build.
 */
final class ContainerResolverLocator implements ResolverLocatorInterface
{
    public function get(string $id): UrlResolverInterface
    {
        try {
            $resolver = App::component($id);
            if ($resolver === null && (Yii::$container->has($id) || class_exists($id))) {
                $resolver = Yii::$container->get($id);
            } elseif ($resolver === null) {
                throw new ConfigurationException(\sprintf('IndexNow resolver "%s" is neither a component, a container definition nor a class. Implement %s and reference the class or its id.', $id, UrlResolverInterface::class));
            }
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('IndexNow resolver "%s" cannot be built: %s', $id, $e->getMessage()), 0, $e);
        }
        if (!$resolver instanceof UrlResolverInterface) {
            throw new ConfigurationException(\sprintf('IndexNow resolver "%s" resolves to %s, which does not implement %s.', $id, get_debug_type($resolver), UrlResolverInterface::class));
        }

        return $resolver;
    }
}
