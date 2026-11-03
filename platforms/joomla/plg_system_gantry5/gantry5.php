<?php

/**
 * @package   Gantry 5
 * @author    Tiger12 http://tiger12.com
 * @originalCreator  RocketTheme (Gantry Framework)
 * @currentDeveloper  Tiger12, LLC
 * @copyright Copyright (C) 2007 - 2022 Tiger12, LLC
 * @license   GNU/GPLv2 and later
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */
defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeDeleteEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\AbstractEvent;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Joomla\Router\Route as ApiRoute;
use Gantry5\Loader;
use Gantry\Component\Config\Config;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\FileSystem\Folder;
use Gantry\Component\Theme\ThemeDetails;
use Gantry\Debugger;
use Gantry\Framework\Assignments;
use Gantry\Framework\Document;
use Gantry\Framework\Gantry;
use Gantry\Framework\Menu;
use Gantry\Framework\Outlines;
use Gantry\Framework\Platform;
use Gantry\Framework\Theme;
use Gantry\Joomla\CacheHelper;
use Gantry\Joomla\StyleHelper;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

// Quick check to prevent fatal error in unsupported Joomla admin.
if (!class_exists(CMSPlugin::class)) {
    return;
}

/**
 * Class plgSystemGantry5
 */
class plgSystemGantry5 extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    protected $styles;
    protected $modules;

    public static function getSubscribedEvents(): array
    {
        return [
            'onGantryGlobalConfig' => 'handleGlobalConfig',
            'onBeforeApiRoute' => 'onBeforeApiRoute',
            'onAfterRoute' => 'onAfterRoute',
            'onAfterDispatch' => 'onAfterDispatch',
            'onAfterRender' => 'onAfterRender',
            'onRenderModule' => 'handleRenderModule',
            'onAjaxParticle' => 'handleAjaxParticle',
            'onAfterDispatchSiteAdmin' => 'onAfterDispatchSiteAdmin',
            'onGantry5SaveConfig' => 'handleSaveConfig',
            'onContentBeforeSave' => 'handleContentBeforeSave',
            'onExtensionBeforeSave' => 'handleExtensionBeforeSave',
            'onExtensionAfterSave' => 'handleExtensionAfterSave',
            'onExtensionBeforeDelete' => 'handleExtensionBeforeDelete',
            'onContentPrepareData' => 'handleContentPrepareData',
            'onContentPrepareForm' => 'handleContentPrepareForm',
        ];
    }

    public function handleGlobalConfig(AbstractEvent $event): void
    {
        // The existing Gantry emitter passes the global configuration by reference.
        $arguments = $event->getArguments();
        unset($arguments['result']);
        $this->onGantryGlobalConfig(...array_values($arguments));
    }

    public function handleRenderModule(AbstractEvent $event): void
    {
        // Preserve reference arguments for Gantry's legacy module dispatcher.
        $arguments = $event->getArguments();
        unset($arguments['result']);
        $this->onRenderModule(...array_values($arguments));
    }

    public function handleAjaxParticle(AjaxEvent $event): void
    {
        $result = $this->onAjaxParticle();
        if ($result !== null) {
            $event->addResult($result);
        }
    }

    public function handleSaveConfig(Event $event): void
    {
        $arguments = $event->getArguments();
        $results = $arguments['result'] ?? [];
        unset($arguments['result']);
        $result = $this->onGantry5SaveConfig(...array_values($arguments));
        if ($result !== null) {
            $results[] = $result;
            $event->setArgument('result', $results);
        }
    }

    public function handleContentBeforeSave(BeforeSaveEvent $event): void
    {
        $this->onContentBeforeSave($event->getContext(), $event->getItem(), $event->getIsNew(), $event->getArguments()['data'] ?? []);
    }

    public function handleExtensionBeforeSave(BeforeSaveEvent $event): void
    {
        $this->onExtensionBeforeSave($event->getContext(), $event->getItem(), $event->getIsNew());
    }

    public function handleExtensionAfterSave(AfterSaveEvent $event): void
    {
        $this->onExtensionAfterSave($event->getContext(), $event->getItem(), $event->getIsNew());
    }

    public function handleExtensionBeforeDelete(BeforeDeleteEvent $event): void
    {
        $result = $this->onExtensionBeforeDelete($event->getContext(), $event->getItem());
        if ($result !== null) {
            $event->addResult($result);
        }
    }

    public function handleContentPrepareData(PrepareDataEvent $event): void
    {
        $this->onContentPrepareData($event->getContext(), $event->getData());
    }

    public function handleContentPrepareForm(PrepareFormEvent $event): void
    {
        $this->onContentPrepareForm($event->getForm(), $event->getData());
    }

    /**
     * Return global configuration for Gantry5.
     *
     * @param array $global
     */
    public function onGantryGlobalConfig(&$global)
    {
        $global = $this->params->toArray();
    }

    /**
     * Register Gantry's narrow API in Joomla's router. Core API authentication
     * runs after routing; no route is public and no token is handled here.
     */
    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $base = 'v1/gantry/themes/:theme/outlines';
        $defaults = ['component' => 'com_gantry5', 'public' => false];
        $theme = ['theme' => '[A-Za-z0-9_-]{1,64}'];
        $outline = $theme + ['outline' => '[A-Za-z0-9_-]{1,80}'];

        $event->getRouter()->addRoutes([
            new ApiRoute(['GET'], 'v1/gantry/themes/:theme/changes', 'theme.history', $theme, $defaults),
            new ApiRoute(['POST'], 'v1/gantry/themes/:theme/changes/preview', 'theme.preview', $theme, $defaults),
            new ApiRoute(['POST'], 'v1/gantry/themes/:theme/changes/undo', 'theme.undo', $theme, $defaults),
            new ApiRoute(['GET'], $base, 'theme.listOutlines', $theme, $defaults),
            new ApiRoute(['POST'], $base, 'theme.createOutline', $theme, $defaults),
            new ApiRoute(['GET'], $base . '/:outline', 'theme.getOutline', $outline, $defaults),
            new ApiRoute(['PATCH'], $base . '/:outline', 'theme.updateOutline', $outline, $defaults),
            new ApiRoute(['DELETE'], $base . '/:outline', 'theme.deleteOutline', $outline, $defaults),
            new ApiRoute(['GET'], $base . '/:outline/layout', 'theme.getLayout', $outline, $defaults),
            new ApiRoute(['PUT'], $base . '/:outline/layout', 'theme.updateLayout', $outline, $defaults),
            new ApiRoute(['GET'], $base . '/:outline/settings', 'theme.getSettings', $outline, $defaults),
            new ApiRoute(['PATCH'], $base . '/:outline/settings', 'theme.updateSettings', $outline, $defaults),
        ]);
    }

    public function onAfterRoute()
    {
        if ($this->getApplication()->isClient('site')) {
            $this->onAfterRouteSite();

        } elseif ($this->getApplication()->isClient('administrator')) {
            $this->onAfterRouteAdmin();
        }
    }

    /**
     * Document gets set during dispatch, we need language and direction.
     */
    public function onAfterDispatch()
    {
        if (class_exists('Gantry\Framework\Gantry')) {
            $this->onAfterDispatchSiteAdmin();
        }
    }

    public function onAfterRender()
    {
        if ($this->getApplication()->isClient('site') && class_exists('Gantry\Framework\Gantry')) {
            $this->onAfterRenderSite();

        } elseif ($this->getApplication()->isClient('administrator')) {
            $this->onAfterRenderAdmin();
        }
    }

    /**
     * @param object $module
     * @param array $attribs
     */
    public function onRenderModule(&$module, &$attribs)
    {
        if (!$this->getApplication()->isClient('site') || !class_exists('Gantry\Framework\Gantry')) {
            return;
        }

        $gantry = Gantry::instance();
        $outline = $gantry['configuration'];

        // Do not render modules assigned to menu items in error and offline page.
        if (isset($module->menuid) && $module->menuid > 0 && in_array($outline, array('_error', '_offline'), true)) {
            $module = null;
        }

        // TODO: This event allows more diverse module assignment conditions.
    }

    /**
     * Serve particle AJAX requests in 'index.php?option=com_ajax&plugin=particle&format=json'.
     *
     * @return array|string|null
     * @throws RuntimeException
     */
    public function onAjaxParticle()
    {
        if (!$this->getApplication()->isClient('site') || !class_exists('Gantry\Framework\Gantry')) {
            return null;
        }

        $input = $this->getApplication()->getInput();
        $format = strtolower($input->getCmd('format', 'html'));

        if (!in_array($format, ['json', 'raw', 'debug'], true)) {
            throw new RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $props = $_GET;
        unset($props['option'], $props['plugin'], $props['format'], $props['id'], $props['Itemid']);

        $identifier = $input->getCmd('id');

        if (strpos($identifier, 'module-') === 0) {
            preg_match('`-([\d]+)$`', $input->getCmd('id'), $matches);

            if (!isset($matches[1])) {
                throw new RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }

            $id = $matches[1];

            require_once JPATH_ROOT . '/modules/mod_gantry5_particle/helper.php';

            return ModGantry5ParticleHelper::ajax($id, $props, $format);
        }

        $gantry = Gantry::instance();

        /** @var Theme $theme */
        $theme = $gantry['theme'];
        $layout = $theme->loadLayout();
        $html = '';

        if ($identifier === 'main-particle') {
            $type = $identifier;
            $menu = $this->getApplication()->getMenu();
            $menuItem = $menu ? $menu->getActive() : null;
            $params = $menuItem ? $menuItem->getParams() : new Registry;

            /** @var object $params */
            $data = json_decode($params->get('particle'), true);
            if ($data && $theme->hasContent()) {
                $context = [
                    'gantry' => $gantry,
                    'noConfig' => true,
                    'inContent' => true,
                    'ajax' => $props,
                    'segment' => [
                        'id' => $identifier,
                        'type' => $data['type'],
                        'classes' => $params->get('pageclass_sfx'),
                        'subtype' => $data['particle'],
                        'attributes' => $data['options']['particle'],
                    ]
                ];

                $html = trim($theme->render('@nucleus/content/particle.html.twig', $context));
            }
        } else {
            $particle = $layout->find($identifier);
            if (!isset($particle->type) || $particle->type !== 'particle') {
                throw new RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }

            $context = array(
                'gantry' => $gantry,
                'inContent' => false,
                'ajax' => $props,
            );

            $block = $theme->getContent($particle, $context);
            $type = $particle->type . '.' . $particle->subtype;
            $html = (string) $block;
        }

        if ($format === 'raw') {
            return $html;
        }

        return ['code' => 200, 'type' => $type, 'id' => $identifier, 'props' => (object) $props, 'html' => $html];
    }

    /**
     * Load Gantry framework before dispatching to the component.
     *
     * @throws \RuntimeException
     */
    private function onAfterRouteSite()
    {
        $templateName = $this->getApplication()->getTemplate();

        if (!$this->isGantryTemplate($templateName)) {
            return;
        }

        $gantryPath = JPATH_THEMES . "/{$templateName}/custom/includes/gantry.php";
        if (!is_file($gantryPath)) {
            $gantryPath = JPATH_THEMES . "/{$templateName}/includes/gantry.php";
        }
        if (is_file($gantryPath)) {
            // Manually setup Gantry 5 Framework from the template.
            $gantry = include $gantryPath;

            if (!$gantry) {
                throw new \RuntimeException(
                    Text::sprintf('GANTRY5_THEME_LOADING_FAILED', $templateName, Text::_('GANTRY5_THEME_INCLUDE_FAILED')),
                    500
                );
            }

        } else {

            // Setup Gantry 5 Framework or throw exception.
            Loader::setup();

            // Get Gantry instance.
            $gantry = Gantry::instance();

            // Initialize the template.
            $gantry['theme.path'] = JPATH_THEMES . "/{$templateName}";
            $gantry['theme.name'] = $templateName;

            $classPath = $gantry['theme.path'] . '/custom/includes/theme.php';
            if (!is_file($classPath)) {
                $classPath = $gantry['theme.path'] . '/includes/theme.php';
            }

            include_once $classPath;
        }

        if (\GANTRY_DEBUGGER) {
            Debugger::addMessage("Using Gantry 5 template {$templateName}");
        }

        /** @var Theme $theme */
        $theme = $gantry['theme'];

        $assignments = new Assignments();

        if (\GANTRY_DEBUGGER) {
            Debugger::addMessage('Selecting outline (rules, matches, scores):', 'debug');
            Debugger::addMessage($assignments->getPage(), 'debug');
            Debugger::addMessage($assignments->loadAssignments(), 'debug');
            Debugger::addMessage($assignments->matches(), 'debug');
            Debugger::addMessage($assignments->scores(), 'debug');
        }

        $theme->setLayout($assignments->select());

        if ($this->params->get('asset_timestamps', 1)) {
            $age = (int)($this->params->get('asset_timestamps_period', 7) * 86400);
            Document::$timestamp_age = $age > 0 ? $age : PHP_INT_MAX;
        } else {
            Document::$timestamp_age = 0;
        }
    }

    /**
     * Re-route Gantry templates to Gantry Administration component.
     */
    private function onAfterRouteAdmin()
    {
        $input = $this->getApplication()->getInput();

        $option = $input->getCmd('option');
        $task   = $input->getCmd('task');

        if (in_array($option, array('com_templates', 'com_advancedtemplates'), true)) {

            if ($task && strpos($task, 'style') === 0 && $this->params->get('use_assignments', true)) {
                // Get all ids.
                $cid = $input->post->get('cid', (array)$input->getInt('id'), 'array');

                if ($cid) {
                    $styles = $this->getStyles();
                    $selected = array_intersect_key($styles, array_flip($cid));

                    // If no Gantry templates were selected, just let com_templates deal with the request.
                    if (!$selected) {
                        return;
                    }

                    // Special handling for tasks coming from com_template.
                    if ($task === 'style.edit') {
                        $theme = reset($selected);
                        $id = key($selected);
                    $session = $this->getApplication()->getSession();
                    $token = $session::getFormToken();
                        $this->getApplication()->redirect("index.php?option=com_gantry5&view=configurations/{$id}/layout&theme={$theme}&{$token}=1");
                    }
                }
            }
        }
    }

    /**
     * Document gets set during dispatch, we need language and direction.
     */
    public function onAfterDispatchSiteAdmin()
    {
        $gantry = Gantry::instance();
        if (!isset($gantry['theme'])) {
            return;
        }

        $theme = $gantry['theme'];

        $document = $this->getApplication()->getDocument();
        if ($document instanceof HtmlDocument) {
            $document->setHtml5(true);
        }
        $theme->language = $document->language;
        $theme->direction = $document->direction;
    }

    /**
     * Convert all stream uris into proper links.
     */
    private function onAfterRenderSite()
    {
        $gantry = Gantry::instance();

        $html = $this->getApplication()->getBody();

        /** @var Document $document */
        $document = $gantry['document'];

        // Only filter our streams. If there's an error (bad UTF8), fallback with original output.
        $this->getApplication()->setBody($document::urlFilter($html, false, 0, true) ?: $html);
    }

    /**
     * Convert links in com_templates to point into Gantry Administrator component.
     */
    private function onAfterRenderAdmin()
    {
        $document = $this->getApplication()->getDocument();
        $type   = $document->getType();

        $option = $this->getApplication()->getInput()->getString('option');
        $view   = $this->getApplication()->getInput()->getString('view', 'g5');
        $task   = $this->getApplication()->getInput()->getString('task');

        if (($option === 'com_templates' || $option === 'com_advancedtemplates') && ($view === 'g5' || $view === 'styles') && !$task && $type === 'html') {
            $this->styles = $this->getStyles();

            $body = preg_replace_callback('/(<a\s[^>]*href=")([^"]*)("[^>]*>)(.*)(<\/a>)/siU', array($this, 'appendHtml'), $this->getApplication()->getBody());

            $this->getApplication()->setBody($body);
        }

        if (($option === 'com_modules' || $option === 'com_advancedmodules') && (($view === 'g5' || $view === 'modules') || empty($view)) && $type === 'html') {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true);
            $query->select('id, title, params');
            $query->from('#__modules');
            $query->where('module = ' . $db->quote('mod_gantry5_particle'));
            $db->setQuery($query);
            $data = $db->loadObjectList();

            if (count($data) > 0) {
                $this->modules = array();
                $body = $this->getApplication()->getBody();

                foreach ($data as $module) {
                    $params   = json_decode($module->params, false);
                    $particle = isset($params->particle) ? json_decode($params->particle, false) : '';
                    $title = isset($particle->title) ? $particle->title : (isset($particle->particle) ? $particle->particle : '');
                    $type = isset($particle->particle) ? $particle->particle : '';

                    $this->modules[$module->id] = $particle;

                    $body = preg_replace_callback('/(<a\s[^>]*href=")([^"]*)("[^>]*>)(.*)(<\/a>)/siU', function($matches) use ($title, $type) {
                        return $this->appendHtml($matches, $title, $type);
                    }, $body);
                }


                $this->getApplication()->setBody($body);
            }
        }
    }

    /**
     * Save plugin parameters and trigger the save events.
     *
     * @param array $data
     * @return bool
     * @throws RuntimeException
     * @see JModelAdmin::save()
     */
    public function onGantry5SaveConfig(array $data)
    {
        $name = 'plg_' . $this->_type . '_' . $this->_name;

        // Initialise variables;
        $table = Table::getInstance('Extension');

        // Include the content plugins for the on save events.
        PluginHelper::importPlugin('extension');

        // Load the row if saving an existing record.
        $table->load(array('type'=>'plugin', 'folder'=>$this->_type, 'element'=>$this->_name));

        $params = new Registry($table->params);
        $params->loadArray($data);

        $table->params = $params->toString();

        // Check the data.
        if (!$table->check()) {
            throw new RuntimeException($table->getError());
        }

        // Trigger the onContentBeforeSave event.
        $result = $this->getApplication()->triggerEvent('onExtensionBeforeSave', array($name, $table, false));
        if (in_array(false, $result, true)) {
            throw new RuntimeException($table->getError());
        }

        // Store the data.
        if (!$table->store()) {
            throw new RuntimeException($table->getError());
        }

        // Clean the cache.
        CacheHelper::cleanPlugin();

        // Update plugin settings.
        $this->params = $params;

        // Trigger the onExtensionAfterSave event.
        $this->getApplication()->triggerEvent('onExtensionAfterSave', array($name, $table, false));

        return true;
    }

    /**
     * @param string $context
     * @param JTable $table
     * @param bool $isNew
     * @param array $data
     * @return void
     */
    public function onContentBeforeSave($context, $table, $isNew, $data = array())
    {
        switch ($context) {
            case 'com_menus.item':
                Loader::setup();

                $params = new Registry($table->params ?: '{}');
                $isGantry = !empty($params['gantry']);
                if ($isGantry and class_exists(Menu::class)) {
                    // Remove default Gantry params.
                    $gantryParams = Menu::decodeJParams($params);
                    Menu::updateJParams($params, $gantryParams);

                    $table->params = $params->toString();
                }

                break;
        }
    }

    /**
     * @param string $context
     * @param object $table
     * @param bool $isNew
     */
    public function onExtensionBeforeSave($context, $table, $isNew)
    {
        if ($context === 'com_config.component' && $table && $table->type === 'component' && $table->name === 'com_gantry5') {
            $name = 'plg_' . $this->_type . '_' . $this->_name;

            $params = new Registry($table->params);

            $data = (array) $params->get($name);

            Loader::setup();

            $this->onGantry5SaveConfig($data);

            // Do not save anything into the component itself (Joomla cannot handle it).
            $table->params = '';

            return;
        }
    }

    /**
     * @param string $context
     * @param object $table
     * @param bool $isNew
     */
    public function onExtensionAfterSave($context, $table, $isNew)
    {
        if ($context === 'com_config.component' && $table && $table->type === 'component' && $table->name === 'com_gantry5') {

        }

        if ($context !== 'com_templates.style' || $table->client_id || !$this->isGantryTemplate($table->template)) {
            return;
        }

        if (!$isNew) {
            return;
        }

        $template = $table->template;

        $this->load($template);
        $registry = new Registry($table->params);
        $old = (int) $registry->get('configuration', 0);
        $new = (int) $table->id;

        if ($old && $old !== $new) {
            StyleHelper::copy($table, $old, $new);
        }
    }

    /**
     * @param string $context
     * @param object $table
     */
    public function onExtensionBeforeDelete($context, $table)
    {
        if ($context !== 'com_templates.style' || $table->client_id || !$this->isGantryTemplate($table->template)) {
            return true;
        }

        $template = $table->template;

        $gantry = $this->load($template);

        /** @var Outlines $outlines */
        $outlines = $gantry['outlines'];

        try {
            $outlines->delete($table->id, false);
        } catch (Exception $e) {
            $this->getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    /**
     * @param string $context
     * @param object $data
     * @return bool
     */
    public function onContentPrepareData($context, $data)
    {
        $name = 'plg_' . $this->_type . '_' . $this->_name;

        // Check that we are manipulating a valid form.
        switch ($context) {
            case 'com_menus.item':
                Loader::setup();

                $isNew = $data->parent_id === null;
                $menuParams = Menu::decodeJParams($data->params);
                $isGantry = !empty($data->params['gantry']) || is_array($menuParams);
                if ($isNew || $isGantry) {
                    // Add default Gantry params to menu item.
                    if (null === $menuParams) {
                        $menuParams = [];
                    }
                    $data->params = array_merge($data->params, Menu::encodeJParams($menuParams, false));
                }
                break;
        }

        return true;
    }

    /**
     * @param Form $form
     * @param object $data
     * @return bool
     */
    public function onContentPrepareForm($form, $data)
    {
        // Check that we are manipulating a valid form.
        if (!($form instanceof \Joomla\CMS\Form\Form)) {
            $this->_subject->setError('JERROR_NOT_A_FORM');

            return false;
        }

        $name = 'plg_' . $this->_type . '_' . $this->_name;

        switch ($form->getName()) {
            case 'com_config.component':
                // If we are editing configuration from Gantry component, add missing fields from system plugin.
                $rules = $form->getField('rules');
                if ($rules && $rules->getAttribute('component') === 'com_gantry5') {
                    $this->loadLanguage("{$name}.sys");
                    // Add plugin fields to the form under plg_type_name.
                    $file = file_get_contents(__DIR__."/{$this->_name}.xml");
                    $file = preg_replace('/ name="params"/', " name=\"{$name}\"", $file);
                    $form->load($file, false, '/extension/config');

                    // Joomla seems to be missing support for component data manipulation so do it manually here.
                    $form->bind([$name => $this->params->toArray()]);
                }
                break;

            case 'com_menus.items.filter':
                break;

            case 'com_menus.item':
                Loader::setup();

                $isNew = $data->parent_id === null;
                $isGantry = !empty($data->params['gantry']) || is_array(Menu::decodeJParams($data->params));
                if ($isNew || $isGantry) {
                    // Add Gantry Menu tab to the form.
                    \Joomla\CMS\Form\Form::addFormPath(__DIR__ . '/forms');
                    $form->loadFile('menu_item', false);
                }

                break;
        }

        return true;
    }

    /**
     * @param array  $matches
     * @param string $content
     * @param string $type
     * @return string
     */
    private function appendHtml(array $matches, $content = 'Gantry 5', $type = '')
    {
        $html = $matches[0];

        if (strpos($matches[2], 'task=style.edit') || strpos($matches[2], 'task=module.edit')) {
            $uri = new Uri($matches[2]);
            $id = (int) $uri->getVar('id');

            if ($id && (isset($this->styles[$id]) || isset($this->modules[$id])) && in_array($uri->getVar('option'), array('com_templates', 'com_advancedtemplates', 'com_modules', 'com_advancedmodules'), true)) {
                $html = $matches[1] . $uri . $matches[3] . $matches[4] . $matches[5];
                $colors = $content ? 'background:#439a86;' : 'background:#f17f48;';
                $content = $content ?: 'No Particle Selected';
                $title = $type ? ' title="Particle Type: ' . $type . '"' : '';

                // TODO: remove label when dropping Joomla 3 support.
                $html .= ' <span class="label badge bagde-info" ' . $title . ' style="' . $colors . 'color:#fff;">' . $content . '</span>';

                if (isset($this->modules[$id])) {
                    unset($this->modules[$id]);
                } else {
                    unset($this->styles[$id]);
                }
            }
        }

        return $html;
    }

    /**
     * @return array
     */
    private function getStyles()
    {
        static $list;

        if ($list === null) {
            // Load styles
            $db = Factory::getDbo();
            $query = $db
                ->getQuery(true)
                ->select('s.id, s.template')
                ->from('#__template_styles as s')
                ->where('s.client_id = 0')
                ->where('e.enabled = 1')
                ->leftJoin('#__extensions as e ON e.element=s.template AND e.type=' . $db->quote('template') . ' AND e.client_id=s.client_id');

            $db->setQuery($query);
            $templates = (array)$db->loadObjectList();

            $list = array();

            foreach ($templates as $template) {
                if ($this->isGantryTemplate($template->template)) {
                    $list[$template->id] = $template->template;
                }
            }
        }

        return $list;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isGantryTemplate($name)
    {
        if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $name)) { return false; }
        // The native router must still select Gantry assignments when source
        // formats are unavailable. A registered compiled theme remains strict:
        // missing/corrupt generations are rejected by the registry, not silently
        // replaced with another outline. Source metadata serves editor installs.
        Loader::setup();
        $directory = JPATH_SITE . '/' . \Gantry\Component\Render\RenderRegistry::DIRECTORY . '/' . $name;
        if (is_dir($directory) && !is_link($directory)) { return true; }
        return file_exists(JPATH_SITE . "/templates/{$name}/gantry/theme.yaml");
    }

    /**
     * @param string $name
     * @return \Gantry\Framework\Gantry
     */
    protected function load($name)
    {
        Loader::setup();

        $gantry = Gantry::instance();

        if (!isset($gantry['theme.name']) || $name !== $gantry['theme.name']) {
            // Restart Gantry and initialize it.
            $gantry = Gantry::restart();
            $gantry['theme.name'] = $name;

            $streams = $gantry['streams'];
            $streams->register();

            /** @var Platform $patform */
            $patform = $gantry['platform'];
            /** @var UniformResourceLocator $locator */
            $locator = $gantry['locator'];
            /** @var Config $global */
            $global = $gantry['global'];

            // Initialize theme stream.
            $details = new ThemeDetails($name);
            $locator->addPath('gantry-theme', '', $details->getPaths(), false, true);

            // Initialize theme cache stream.
            $cachePath = $patform->getCachePath() . '/' . $name;
            Folder::create($cachePath);
            $locator->addPath('gantry-cache', 'theme', array($cachePath), true, true);

            CompiledYamlFile::$defaultCachePath = $locator->findResource('gantry-cache://theme/compiled/yaml', true, true);
            CompiledYamlFile::$defaultCaching = $global->get('compile_yaml', 1);
        }

        return $gantry;
    }
}
