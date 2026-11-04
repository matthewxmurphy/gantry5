<?php

use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (): PluginInterface {
            $config = (array) PluginHelper::getPlugin('system', 'gantry5');
            $loader = JPATH_LIBRARIES . '/gantry5/src/Loader.php';

            if (is_file($loader)) {
                require_once $loader;
            }

            if (class_exists(\Gantry5\Loader::class)) {
                require_once dirname(__DIR__) . '/gantry5.php';
                $plugin = new plgSystemGantry5($config);
            } else {
                $plugin = new class ($config) extends CMSPlugin implements SubscriberInterface {
                    public static function getSubscribedEvents(): array
                    {
                        return ['onAfterInitialise' => 'warnAboutMissingLibrary'];
                    }

                    public function warnAboutMissingLibrary(AfterInitialiseEvent $event): void
                    {
                        $app = $event->getApplication();
                        if ($app->isClient('administrator')) {
                            $this->loadLanguage('plg_system_gantry5.sys');
                            $language = $app->getLanguage();
                            $app->enqueueMessage(
                                sprintf($language->_('PLG_SYSTEM_GANTRY5_LIBRARY_MISSING'), $language->_('PLG_SYSTEM_GANTRY5')),
                                'warning'
                            );
                        }
                    }
                };
            }

            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        });
    }
};
