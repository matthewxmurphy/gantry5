<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * @package   Gantry5
 * @author    Tiger12 http://tiger12.com
 * @originalCreator  RocketTheme (Gantry Framework) 
 * @currentDeveloper  Tiger12, LLC 
 * @copyright Copyright (C) 2007 - 2021 Tiger12, LLC
 * @license   GNU/GPLv2 and later
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Gantry\Framework;

use Gantry\Debugger;
use Pimple\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Timber\Timber;

/**
 * Class Gantry
 * @package Gantry\Framework
 */
class Gantry extends Base\Gantry
{
    /**
     * @return boolean
     */
    public function admin()
    {
        // is_admin() is also true for anonymous admin-ajax.php requests, so require a real
        // administrator capability before treating the request as an admin request.
        return \is_admin() && \function_exists('current_user_can') && \current_user_can('manage_options');
    }

    /**
     * @param string $location
     * @param bool   $force
     * @return array
     */
    public function styles($location = 'head', $force = false)
    {
        // Do not display head, WordPress will take care of it (most of the time).
        return !$force && $location === 'head' ? Document::$wp_styles : parent::styles($location);
    }

    /**
     * @param string $location
     * @param bool $force
     * @return array
     */
    public function scripts($location = 'head', $force = false)
    {
        // Do not display head and footer, WordPress will take care of it (most of the time).
        return !$force && in_array($location, ['head', 'footer']) ? Document::$wp_scripts[$location] : parent::scripts($location);
    }

    /**
     * @return static
     * @throws \LogicException
     */
    protected static function init()
    {
        // Timber 2 is a Composer library; a preloaded Timber 1 plugin cannot be mixed in.
        if (!defined(Timber::class . '::VERSION') || version_compare(Timber::VERSION, '2.5.1', '<')
            || (class_exists('Timber', false) && !is_a('Timber', Timber::class, true))) {
            throw new \LogicException('This Gantry candidate requires its Composer Timber 2.5 library. Disable the legacy Timber plugin before using it.');
        }

        $container = parent::init();

        Timber::init();
        if (\GANTRY_DEBUGGER) {
            Debugger::addMessage('Using Timber Library v' . Timber::VERSION);
        }

        $lookup = $container['loader']->getPrefixesPsr4()['Gantry\\'];
        $iterator = new \FilesystemIterator($lookup[0] . '/WordPress/Integration', \FilesystemIterator::SKIP_DOTS & \FilesystemIterator::UNIX_PATHS);

        /** @var \FilesystemIterator $file */
        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                continue;
            }
            $class = "Gantry\\WordPress\\Integration\\{$file->getBasename()}\\{$file->getBasename()}";
            if (class_exists($class) && call_user_func([$class, 'enabled'])) {
                $integration = new $class;
                if ($integration instanceof ServiceProviderInterface) {
                    $container->register($integration);
                }
                if ($integration instanceof EventSubscriberInterface) {
                    $container['events']->addSubscriber($integration);
                }
            }
        }

        return $container;
    }

    /**
     * @return array
     */
    protected function loadGlobal()
    {
        return (array) \get_option('gantry5_plugin');
    }
}
