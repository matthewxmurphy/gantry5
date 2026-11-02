<?php
/**
 * Copyright (c) 2026 Matthew X Murphy
 * https://MatthewxMurphy.com | https://builtnotbegged.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        // The existing Gantry administrator/site dispatch is left with Joomla's
        // existing registration. Only the API client receives this MVC factory.
        if (!Factory::getApplication()->isClient('api')) {
            return;
        }

        $namespace = '\\Gantry\\Component\\Gantry';
        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));
        $container->set(ComponentInterface::class, static function (Container $container): MVCComponent {
            $component = new MVCComponent($container->get(ComponentDispatcherFactoryInterface::class));
            $component->setMVCFactory($container->get(MVCFactoryInterface::class));

            return $component;
        });
    }
};
