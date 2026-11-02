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

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Extension as ExtensionTable;
use Joomla\CMS\Table\Table;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

/**
 * Gantry 5 package installer script.
 */
class Pkg_Gantry5InstallerScript
{
    /**
     * List of supported versions. Newest version first!
     * @var array
     */
    protected $versions = array(
        'PHP' => array (
            '8.5' => '8.5.0',
            '0' => '8.5.0' // Preferred version
        ),
        'Joomla!' => array (
            // Gantry6.0.2-rc.1 uses the Joomla6.2 core API application.
            '6.2' => '6.2.0',
            '0' => '6.2.0' // Preferred version
        )
    );
    /**
     * List of required PHP extensions.
     * @var array
     */
    protected $extensions = array('pcre');

    /**
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function install($parent)
    {
        return true;
    }

    /**
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function discover_install($parent)
    {
        return self::install($parent);
    }

    /**
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function update($parent)
    {
        return self::install($parent);
    }

    /**
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function uninstall($parent)
    {
        // Hack.. Joomla really doesn't give any information from the extension that's being uninstalled..
        $manifestFile = JPATH_MANIFESTS . '/packages/pkg_gantry5.xml';
        if (is_file($manifestFile)) {
            $manifest = simplexml_load_file($manifestFile);
            $this->prepareExtensions($manifest, 0);
        }

        // Clear cached files.
        if (is_dir(JPATH_CACHE . '/gantry5')) {
            Folder::delete(JPATH_CACHE . '/gantry5');
        }
        if (is_dir(JPATH_SITE . '/cache/gantry5')) {
            Folder::delete(JPATH_SITE . '/cache/gantry5');
        }

        return true;
    }

    /**
     * @param string $type
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function preflight($type, $parent)
    {
        $manifest = $parent->getManifest();

        if ($type !== 'uninstall') {
            // Prevent installation if requirements are not met.
            $errors = $this->checkRequirements($manifest->version);
            if ($errors) {
                /** @var CMSApplication $app */
                $app = Factory::getApplication();

                foreach ($errors as $error) {
                    $app->enqueueMessage($error, 'error');
                }
                return false;
            }
        }

        // Disable and unlock existing extensions to prevent fatal errors (in the site).
        $this->prepareExtensions($manifest, 0);

        return true;
    }

    /**
     * @param string $type
     * @param InstallerAdapter $parent
     * @return bool
     */
    public function postflight($type, $parent)
    {
        // Clear Joomla system cache.
        $cache = Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
            ->createCacheController('callback');
        $cache->clean('_system');

        // Clear Gantry5 cache.
        $path = Factory::getApplication()->getConfig()->get('cache_path', JPATH_SITE . '/cache') . '/gantry5';
        if (is_dir($path)) {
            Folder::delete($path);
        }

        // Make sure that PHP has the latest data of the files.
        clearstatcache();

        // Remove all compiled files from opcode cache.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        } elseif (function_exists('apc_clear_cache')) {
            @apc_clear_cache();
        }

        if ($type === 'uninstall') {
            return true;
        }

        $manifest = $parent->getManifest();

        // Enable and lock extensions to prevent uninstalling them individually.
        $this->prepareExtensions($manifest, 1);

        $this->removeAlphaPackageUpdateSites($manifest);

        // Repair missing update-site registrations for installed Gantry templates.
        $this->registerTemplateUpdateSites();

        // Make sure that all file formats used by Gantry 5 are editable from template manager.
        $this->adjustTemplateSettings();

        // Install sample data on first install.
        if (in_array($type, array('install', 'discover_install'))) {
            $this->renderSplash('install', $manifest);
        } else {
            $this->renderSplash('update', $manifest);
        }

        return true;
    }

    // Internal functions

        /**
     * @param string $template
     * @param \SimpleXMLElement $manifest
     */
    public function renderSplash($template, $manifest)
    {
        // Define variables for the template file.
        $name = Text::sprintf($manifest->name);
        $version = $manifest->version;
        $date = $manifest->creationDate;
        $edit_url = Route::_('index.php?option=com_gantry5', false);

        include JPATH_ADMINISTRATOR . "/components/com_gantry5/install/templates/{$template}.php";
    }

    /**
     * @param $manifest
     * @param int $state
     */
    protected function prepareExtensions($manifest, $state = 1)
    {
        foreach ($manifest->files->children() as $file) {
            $attributes = $file->attributes();

            $search = array('type' => (string) $attributes->type, 'element' => (string) $attributes->id);

            $clientName = (string) $attributes->client;
            if (!empty($clientName)) {
                $client = ApplicationHelper::getClientInfo($clientName, true);
                $search +=  array('client_id' => $client->id);
            }

            $group = (string) $attributes->group;
            if (!empty($group)) {
                $search +=  array('folder' => $group);
            }

            $extension = new ExtensionTable(Factory::getContainer()->get(DatabaseInterface::class));

            if (!$extension->load($search)) {
                continue;
            }

            $extension->protected = 0;

            if (isset($attributes->enabled)) {
                $extension->enabled = $state ? (int) $attributes->enabled : 0;
            }

            $extension->store();
        }
    }

    protected function adjustTemplateSettings()
    {
        $extension = new ExtensionTable(Factory::getContainer()->get(DatabaseInterface::class));
        if (!$extension->load(array('type' => 'component', 'element' => 'com_templates'))) {
            return;
        }

        $params = new Registry($extension->params);
        $params->set('source_formats', $this->addParam($params->get('source_formats'), array('scss', 'yaml', 'twig')));
        $params->set('font_formats', $this->addParam($params->get('font_formats'), array('eot', 'svg')));

        $extension->params = $params->toString();
        $extension->store();
    }

    /**
     * @param string $string
     * @param array $options
     * @return string
     */
    protected function addParam($string, array $options)
    {
        $items = array_flip(explode(',', $string)) + array_flip($options);

        return implode(',', array_keys($items));
    }

    protected function registerTemplateUpdateSites()
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('extension_id, element')
            ->from('#__extensions')
            ->where('type=' . $db->quote('template'))
            ->where('client_id=0')
            ->where('element IN (' . $db->quote('g5_hydrogen') . ',' . $db->quote('g5_helium') . ')');
        $db->setQuery($query);

        $templates = (array) $db->loadObjectList();

        foreach ($templates as $template) {
            $this->registerTemplateUpdateSite((int) $template->extension_id, (string) $template->element);
        }
    }

    /**
     * @param int $extensionId
     * @param string $template
     */
    protected function registerTemplateUpdateSite($extensionId, $template)
    {
        if (!$extensionId || !$template) {
            return;
        }

        $manifestFile = JPATH_SITE . "/templates/{$template}/templateDetails.xml";
        if (!is_file($manifestFile)) {
            return;
        }

        $manifest = simplexml_load_file($manifestFile);
        if ($manifest && (string) $manifest->gantryUpdateChannel === 'manual-alpha') {
            $this->removeOfficialAlphaUpdateSites($extensionId, [
                "http://updates.gantry.org/5.0/joomla/tpl_{$template}.xml",
                "https://updates.gantry.org/5.0/joomla/tpl_{$template}.xml",
                'http://updates.gantry.org/5.0/joomla/list.xml',
                'https://updates.gantry.org/5.0/joomla/list.xml'
            ]);
            return;
        }
        if (!$manifest || empty($manifest->updateservers->server)) {
            return;
        }

        $server = $manifest->updateservers->server[0];
        $location = trim((string) $server);
        if ($location === '') {
            return;
        }

        $name = trim((string) $server['name']) ?: $template;
        $type = trim((string) $server['type']) ?: 'extension';
        $enabled = isset($server['enabled']) ? (int) $server['enabled'] : 1;

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('us.update_site_id')
            ->from($db->quoteName('#__update_sites', 'us'))
            ->innerJoin(
                $db->quoteName('#__update_sites_extensions', 'usex') .
                ' ON ' . $db->quoteName('usex.update_site_id') . ' = ' . $db->quoteName('us.update_site_id')
            )
            ->where($db->quoteName('usex.extension_id') . ' = ' . (int) $extensionId)
            ->where($db->quoteName('us.location') . ' = ' . $db->quote($location));
        $db->setQuery($query);
        $updateSiteId = (int) $db->loadResult();

        if ($updateSiteId) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__update_sites'))
                ->set($db->quoteName('name') . ' = ' . $db->quote($name))
                ->set($db->quoteName('type') . ' = ' . $db->quote($type))
                ->set($db->quoteName('location') . ' = ' . $db->quote($location))
                ->set($db->quoteName('enabled') . ' = ' . (int) $enabled)
                ->where($db->quoteName('update_site_id') . ' = ' . $updateSiteId);
            $db->setQuery($query);
            $db->execute();

            return;
        }

        $query = $db->getQuery(true)
            ->select('update_site_id')
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = ' . $db->quote($location));
        $db->setQuery($query);
        $updateSiteId = (int) $db->loadResult();

        if ($updateSiteId) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__update_sites'))
                ->set($db->quoteName('name') . ' = ' . $db->quote($name))
                ->set($db->quoteName('type') . ' = ' . $db->quote($type))
                ->set($db->quoteName('enabled') . ' = ' . (int) $enabled)
                ->where($db->quoteName('update_site_id') . ' = ' . $updateSiteId);
            $db->setQuery($query);
            $db->execute();
        } else {
            $updateSite = (object) array(
                'name' => $name,
                'type' => $type,
                'location' => $location,
                'enabled' => $enabled,
                'extra_query' => ''
            );
            $db->insertObject('#__update_sites', $updateSite, 'update_site_id');
            $updateSiteId = (int) $updateSite->update_site_id;
        }

        if (!$updateSiteId) {
            return;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__update_sites_extensions'))
            ->where($db->quoteName('update_site_id') . ' = ' . $updateSiteId)
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
        $db->setQuery($query);
        $exists = (int) $db->loadResult();

        if (!$exists) {
            $link = (object) array(
                'update_site_id' => $updateSiteId,
                'extension_id' => (int) $extensionId
            );
            $db->insertObject('#__update_sites_extensions', $link);
        }
    }

    /** Detach only this alpha package from its inherited official Gantry 5 feeds. */
    protected function removeAlphaPackageUpdateSites($manifest)
    {
        if ((string) $manifest->gantryUpdateChannel !== 'manual-alpha') {
            return;
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_gantry5'));
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();
        $this->removeOfficialAlphaUpdateSites($extensionId, [
            'http://updates.gantry.org/5.0/joomla/pkg_gantry5.xml',
            'https://updates.gantry.org/5.0/joomla/pkg_gantry5.xml',
            'http://updates.gantry.org/5.0/joomla/list.xml',
            'https://updates.gantry.org/5.0/joomla/list.xml'
        ]);
    }

    /**
     * Remove scoped associations/cached offers, never the shared feed records.
     * Unrelated extension links and custom feed locations are preserved.
     *
     * @param int $extensionId Installed package/template ID owned by this alpha.
     * @param string[] $locations Exact inherited official Gantry 5 feed URLs.
     */
    protected function removeOfficialAlphaUpdateSites($extensionId, array $locations)
    {
        if (!$extensionId || !$locations) {
            return;
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $quoted = array_map([$db, 'quote'], $locations);
        // Cached offers can outlive their association; delete only this extension's rows below.
        $query = $db->getQuery(true)
            ->select($db->quoteName('us.update_site_id'))
            ->from($db->quoteName('#__update_sites', 'us'))
            ->where($db->quoteName('us.location') . ' IN (' . implode(',', $quoted) . ')');
        $db->setQuery($query);
        $siteIds = array_map('intval', (array) $db->loadColumn());
        if (!$siteIds) {
            return;
        }
        foreach (['#__update_sites_extensions', '#__updates'] as $table) {
            $query = $db->getQuery(true)
                ->delete($db->quoteName($table))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId)
                ->where($db->quoteName('update_site_id') . ' IN (' . implode(',', $siteIds) . ')');
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * @param string $gantryVersion
     * @return array
     */
    protected function checkRequirements($gantryVersion)
    {
        $results = array();
        $this->checkVersion($results, 'PHP', PHP_VERSION);
        $this->checkVersion($results, 'Joomla!', JVERSION);
        $this->checkExtensions($results, $this->extensions);

        return $results;
    }

    /**
     * @param array $results
     * @param string $name
     * @param string $version
     */
    protected function checkVersion(array &$results, $name, $version)
    {
        if ($name === 'PHP' && (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600)) {
            $results[] = 'Gantry 6.0.2-rc.1 requires PHP 8.5.x.';
            return;
        }
        $major = $minor = 0;
        foreach ($this->versions[$name] as $major => $minor) {
            if (!$major || version_compare($version, $major, '<')) {
                continue;
            }

            if (version_compare($version, $minor, '>=')) {
                return;
            }
            break;
        }

        if (!$major) {
            $minor = reset($this->versions[$name]);
        }

        $recommended = end($this->versions[$name]);

        if (version_compare($recommended, $minor, '>')) {
            $results[] = sprintf(
                '%s %s is not supported. Minimum required version is %s %s, but it is highly recommended to use %s %s or later version.',
                $name,
                $version,
                $name,
                $minor,
                $name,
                $recommended
            );
        } else {
            $results[] = sprintf(
                '%s %s is not supported. Please update to %s %s or later version.',
                $name,
                $version,
                $name,
                $minor
            );
        }
    }

    /**
     * @param array $results
     * @param array $extensions
     */
    protected function checkExtensions(array &$results, $extensions)
    {
        foreach ($extensions as $name) {
            if (!extension_loaded($name)) {
                $results[] = sprintf("Required PHP extension '%s' is missing. Please install it into your system.", $name);
            }
        }
    }
}
