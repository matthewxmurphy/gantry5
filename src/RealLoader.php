<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

/**
 * @package   Gantry5
 * @author    Tiger12 http://tiger12.com
 * @originalCreator  RocketTheme (Gantry Framework)
 * @currentDeveloper  Tiger12, LLC
 * @copyright Copyright (C) 2007 - 2022 Tiger12, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry5;

if (!defined('ABSPATH')) {
    if (!defined('_JEXEC') && !defined('GRAV_ROOT')) {
        exit;
    }
}

use Composer\Autoload\ClassLoader;

/**
 * Use \Gantry5\Loader::setup() or \Gantry5\Loader::get() instead.
 *
 * This class separates Loader logic from the \Gantry5\Loader class. By adding this extra class we are able to upgrade
 * Gantry5 and initializing the new version during a single request -- as long as Gantry5 has not been initialized.
 *
 * @internal
 */
abstract class RealLoader
{
    /** @var string */
    protected static $errorMessagePhpMin = 'You are running PHP %s, but Gantry 5 Framework needs at least PHP %s to run.';
    /** @var string */
    protected static $errorMessageGantryLoaded = 'Attempting to load Gantry 5 Framework multiple times.';

    /**
     * Initializes Gantry5 and returns Composer ClassLoader.
     *
     * @return \Composer\Autoload\ClassLoader
     * @throws \RuntimeException
     * @throws \LogicException
     */
    public static function getClassLoader()
    {
        if (version_compare($phpVersion = PHP_VERSION, '8.5.0', '<')) {
            throw new \RuntimeException(sprintf(self::$errorMessagePhpMin, $phpVersion, '8.5.0'));
        }
        if (PHP_VERSION_ID >= 80600) {
            throw new \RuntimeException('This Gantry candidate supports PHP 8.5 only. A newer PHP version is not yet supported.');
        }

        if (defined('GANTRY5_VERSION')) {
            throw new \LogicException(self::$errorMessageGantryLoaded);
        }

        define('GANTRY5_VERSION', '6.0.2-rc.1');
        define('GANTRY5_VERSION_DATE', '2026-11-02');

        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        define('GANTRY_DEBUGGER', class_exists('Gantry\\Debugger'));

        return self::autoload();
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     * @throws \LogicException
     * @internal
     */
    protected static function autoload()
    {
        // Register platform specific overrides.
        if (defined('JVERSION') && defined('JPATH_ROOT')) {
            define('GANTRY5_PLATFORM', 'joomla');
            define('GANTRY5_ROOT', JPATH_ROOT);
            define('GANTRY5_LIBRARY', JPATH_ROOT . '/libraries/gantry5');
        } elseif (defined('WP_DEBUG') && defined('ABSPATH') && defined('WP_CONTENT_DIR')) {
            define('GANTRY5_PLATFORM', 'wordpress');
            if (defined('CONTENT_DIR') && class_exists('Env')) {
                // Bedrock support.
                define('GANTRY5_ROOT', preg_replace('|' . preg_quote(CONTENT_DIR, '|'). '$|', '', WP_CONTENT_DIR));
            } else {
                // Plain WP support.
                define('GANTRY5_ROOT', dirname(WP_CONTENT_DIR));
            }
            define('GANTRY5_LIBRARY', WP_CONTENT_DIR . '/plugins/gantry5');
        } elseif (defined('GRAV_VERSION') && defined('ROOT_DIR')) {
            if (version_compare(GRAV_VERSION, '2.0.0', '<')) {
                throw new \RuntimeException('This Gantry candidate requires Grav 2 and its modern dependencies. Keep Grav 1.7 on the Gantry 5.6.5 line.');
            }
            /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
            $locator = \Grav\Common\Grav::instance()['locator'];
            define('GANTRY5_PLATFORM', 'grav');
            define('GANTRY5_ROOT', rtrim(ROOT_DIR, '/'));
            define('GANTRY5_LIBRARY', $locator('plugin://gantry5'));
        } else {
            throw new \RuntimeException('Gantry: CMS not detected!');
        }

        $lib = GANTRY5_LIBRARY;
        $autoload = "{$lib}/vendor/autoload.php";

        // Initialize auto-loading.
        if (!file_exists($autoload)) {
            throw new \LogicException('Please run composer in Gantry 5 Library!');
        }

        /** @var ClassLoader $loader */
        $loader = require $autoload;
        // Joomla/WordPress use their modern package vendor; Grav keeps its host vendor.
        // Never mix the old compat Twig autoloader into a Twig 3 environment.
        if (\Twig\Environment::VERSION_ID < 32100 || \Twig\Environment::VERSION_ID >= 40000) {
            throw new \LogicException('This Gantry candidate requires Twig 3.21 or later in the Twig 3 series. Update conflicting extensions before loading it.');
        }

        // Skip registering SCSS compiler until it's needed.
        $loader->setPsr4('ScssPhp\\ScssPhp\\', '');
        $loader->setPsr4('Leafo\\ScssPhp\\', '');

        // Support for development environments.
        if (file_exists($lib . '/src/platforms')) {
            $loader->addPsr4('Gantry\\', "{$lib}/src/platforms/" . GANTRY5_PLATFORM . '/classes/Gantry', true);
        }

        return $loader;
    }
}
