<?php

/**
 * @package   Gantry 5
 * @author    Tiger12 http://tiger12.com
 * @originalCreator  RocketTheme (Gantry Framework) 
 * @currentDeveloper  Tiger12, LLC 
 * @copyright Copyright (C) 2007 - 2021 Tiger12, LLC
 * @license   GNU/GPLv2 and later
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */
defined('_JEXEC') or die;

use Gantry\Admin\Router;
use Gantry\Framework\Gantry;
use Gantry5\Loader;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/** @var AdministratorApplication $app */
$app = Factory::getApplication();
$user = $app->getIdentity();

// An explicitly enabled client policy admits only its narrow resource, never a legacy action.
$clientEntry = false;
if ($user && !$user->guest && $user->authorise('core.edit', 'com_content') && class_exists(Loader::class)) {
    $clientView = $app->input->getString('view', '');
    if (in_array(trim($clientView, '/'), ['client', 'client/save'], true)) {
        try {
            Loader::setup();
            $clientGantry = Gantry::instance();
            if (!defined('GANTRYADMIN_PATH')) {
                define('GANTRYADMIN_PATH', JPATH_COMPONENT_ADMINISTRATOR);
            }
            // Resolve the requested native theme before checking its persistent policy.
            // This is preparation only: no controller is dispatched before admission.
            $clientRouter = new Router($clientGantry);
            $clientRouter->boot()->load();
            $clientEntry = \Gantry\Component\ClientEditing\Access::mayEnter($clientGantry);
            if ($clientEntry) { \Gantry\Component\ClientEditing\Access::restrictNative($clientGantry); }
        } catch (\Throwable $error) {
            $clientEntry = false;
        }
    }
}

// ACL for Gantry admin access.
if (!$user || (
    !$user->authorise('core.manage', 'com_gantry5')
    && !$user->authorise('core.manage', 'com_templates')
    // Editing particle module makes AJAX call to Gantry component, but has restricted access to json only.
    && !($user->authorise('core.manage', 'com_modules') && strtolower($app->input->getCmd('format', 'html')) === 'json')
    && !$clientEntry
)) {
    $input = $app->getInput();
    if ($user && !$user->guest
        && strtolower($input->getCmd('format', 'html')) === 'json'
        && array_values(array_filter(explode('/', $input->getString('view', '')), 'strlen')) === ['api', 'inventory']
    ) {
        $app->setHeader('Status', '403 Forbidden', true);
    }

    $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');

    return false;
}

if (!defined('GANTRYADMIN_PATH')) {
    define('GANTRYADMIN_PATH', JPATH_COMPONENT_ADMINISTRATOR);
}

// Detect Gantry Framework or fail gracefully.
if (!class_exists('Gantry5\Loader')) {
    $app->enqueueMessage(
        Text::sprintf('COM_GANTRY5_PLUGIN_MISSING', Text::_('COM_GANTRY5')),
        'error'
    );
    return;
}

// Initialize administrator or fail gracefully.
try {
    Loader::setup();

    $gantry = Gantry::instance();
    \Gantry\Component\ClientEditing\Access::restrictNative($gantry);
    $gantry['router'] = function ($c) {
        return new Router($c);
    };

} catch (Exception $e) {
    $app->enqueueMessage(Text::sprintf($e->getMessage()), 'error');

    return;
}

// Dispatch to the controller.
/** @var Router $router */
$router = $gantry['router'];
$router->dispatch();
