<?php

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

namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Admin\Events\StylesEvent;
use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Config\Config;
use Gantry\Component\Response\JsonResponse;
use Gantry\Framework\Services\ConfigServiceProvider;
use Gantry\Framework\Theme;
use RocketTheme\Toolbox\File\YamlFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Class Styles
 * @package Gantry\Admin\Controller\Html\Configurations
 */
class Styles extends HtmlController
{

    protected $httpVerbs = [
        'GET' => [
            '/'              => 'index',
            '/sections'      => 'undefined',
            '/sections/*'    => 'displaySection',
            '/blocks'        => 'undefined',
            '/blocks/*'      => 'display',
            '/blocks/*/**'   => 'formfield'
        ],
        'POST' => [
            '/'          => 'save',
            '/sections'  => 'forbidden',
            '/sections/*' => 'saveSection',
            '/blocks'    => 'forbidden',
            '/blocks/*'  => 'save',
            '/compile'   => 'compile'
        ],
        'PUT' => [
            '/'         => 'save',
            '/blocks'   => 'forbidden',
            '/blocks/*' => 'save'
        ],
        'PATCH' => [
            '/'         => 'save',
            '/blocks'   => 'forbidden',
            '/blocks/*' => 'save'
        ],
        'DELETE' => [
            '/'         => 'forbidden',
            '/blocks'   => 'forbidden',
            '/blocks/*' => 'reset'
        ]
    ];

    /**
     * @return string
     */
    public function index()
    {
        $outline = $this->params['outline'];

        if($outline === 'default') {
            $this->params['overrideable'] = false;
            $this->params['data'] = $this->container['config'];
        } else {
            $this->params['overrideable'] = true;
            $this->params['defaults'] = $this->container['defaults'];
            $this->params['data'] = ConfigServiceProvider::load($this->container, $outline, false, false);
        }

        $this->params['blocks'] = $this->container['styles']->group();
        $this->params['route']  = "configurations.{$outline}.styles";

        return $this->render('@gantry-admin/pages/configurations/styles/styles.html.twig', $this->params);
    }

    /**
     * @param string $id
     * @return string
     */
    public function display($id)
    {
        $this->params = array_replace($this->params, $this->blockContext($id));
        return $this->render('@gantry-admin/pages/configurations/styles/item.html.twig', $this->params);
    }

    protected function blockContext($id)
    {
        $outline = $this->params['outline'];
        $blueprints = $this->container['styles']->getBlueprintForm($id);
        $prefix = 'styles.' . $id;
        $context = [];

        if($outline === 'default') {
            $context['overrideable'] = false;
            $context['data'] = $this->container['config']->get($prefix);
        } else {
            $context['overrideable'] = true;
            $context['defaults'] = $this->container['defaults']->get($prefix);
            $context['data'] = ConfigServiceProvider::load($this->container, $outline, false, false)->get($prefix);
        }

        return $context + [
            'block' => $blueprints,
            'id' => $id,
            'parent' => "configurations/{$outline}/styles",
            'route'  => "configurations.{$outline}.styles.{$prefix}",
            'skip' => ['enabled']
        ];

    }

    private function sectionBlueprint($id)
    {
        if (!$this->authorize('platform.settings.manage')) {
            $this->forbidden();
        }
        $styles = $this->container['styles']->all();
        if (!is_string($id) || !isset($styles[$id]) || ($styles[$id]['type'] ?? null) !== 'section') {
            throw new \RuntimeException('No section style blueprint matches this section.', 404);
        }
    }

    public function displaySection($id)
    {
        $this->sectionBlueprint($id);
        return $this->display($id);
    }

    public function saveSection($id)
    {
        $this->sectionBlueprint($id);
        return $this->save($id);
    }

    public function sectionFields($id, $formId)
    {
        $this->sectionBlueprint($id);
        $context = $this->blockContext($id);
        $html = $this->render('@gantry-admin/forms/fields.html.twig', array_replace($context, [
            'blueprints' => $context['block']['form'], 'scope' => '', 'prefix' => ''
        ]));
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div id="g-section-style-fields">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach (['input', 'select', 'textarea', 'button'] as $tag) {
                foreach ($document->getElementsByTagName($tag) as $control) {
                    $control->setAttribute('form', $formId);
                    if (strtolower($control->getAttribute('type')) === 'submit') {
                        $control->setAttribute('type', 'button');
                    }
                }
            }
            $root = $document->getElementById('g-section-style-fields');
            $result = '';
            foreach ($root->childNodes as $node) {
                $result .= $document->saveHTML($node);
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param string $id
     * @return string
     */
    public function formfield($id)
    {
        $path = func_get_args();

        $outline = $this->params['outline'];

        // Load blueprints.
        $blueprints = $this->container['styles']->getBlueprintForm($id);

        list($fields, $path, $value) = $blueprints->resolve(array_slice($path, 1), '/');

        if (!$fields) {
            throw new \RuntimeException('Page Not Found', 404);
        }

        $fields['is_current'] = true;

        // Get the prefix.
        $prefix = "styles.{$id}." . implode('.', $path);
        if ($value !== null) {
            $parent = $fields;
            $fields = ['fields' => $fields['fields']];
            $prefix .= '.' . $value;
        }
        array_pop($path);

        if($outline === 'default') {
            $this->params['overrideable'] = false;
            $this->params['data'] = $this->container['config']->get($prefix);
        } else {
            $this->params['overrideable'] = true;
            $this->params['defaults'] = $this->container['defaults']->get($prefix);
            $this->params['data'] = ConfigServiceProvider::load($this->container, $outline, false, false)->get($prefix);
        }

        $this->params = [
                'blueprints' => $fields,
                'parent' => $path
                    ? "configurations/{$outline}/styles/blocks/{$id}/" . implode('/', $path)
                    : "configurations/{$outline}/styles/blocks/{$id}",
                'route' => 'styles.' . $prefix
            ] + $this->params;

        if (isset($parent['key'])) {
            $this->params['key'] = $parent['key'];
        }

        return $this->render('@gantry-admin/pages/configurations/styles/field.html.twig', $this->params);
    }

    /**
     * @param string $id
     * @return string
     */
    public function reset($id)
    {
        $this->params += [
            'data' => [],
        ];

        return $this->display($id);
    }

    /**
     * @return JsonResponse
     */
    public function compile()
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        $warnings = $this->compileSettings();

        if ($warnings) {
            $title = isset($warnings['__TITLE__']) ? $warnings['__TITLE__'] : 'CSS Compiled With Warnings';
            unset($warnings['__TITLE__']);
            $this->params += ['warnings' => $warnings];
            return new JsonResponse(
                [
                    'html'    => $this->render('@gantry-admin/layouts/css-warnings.html.twig', $this->params),
                    'warning' => true,
                    'title'   => $title,
                ]
            );
        }

        return new JsonResponse(['html' => 'The CSS was successfully compiled', 'title' => 'CSS Compiled']);
    }

    /**
     * @param string|null $id
     * @return JsonResponse|string
     */
    public function save($id = null)
    {
        /** @var Config $config */
        $config = $this->container['config'];

        if ($id) {
            $data = (array) $config->get('styles');
            $block = $this->request->post->getArray();
            unset($block['g6_revision'], $block['g6_mode']);
            $data[$id] = $block;
        } else {
            $data = $this->request->post->getArray('styles');
        }

        $outline = (string) $this->params['outline'];
        $writer = new \Gantry\Component\Changes\NativeWriter($this->container);
        $change = $this->changeSave('admin.styles',
            fn() => $writer->saveSettings($outline, ['styles' => $data], $this),
            fn() => $writer->previewSettings($outline, ['styles' => $data]));
        if (!empty($change['preview'])) { return $this->changeResponse($change); }
        $warnings = $change['result']['warnings'] ?? [];

        if (empty($this->params['ajax'])) {
            // FIXME: HTML request: Output compiler warnings!!
            return $id ? $this->display($id) : $this->index();
        }

        if ($warnings) {
            $this->params += ['warnings' => $warnings];
            return $this->changeResponse($change,
                [
                    'html'    => $this->render('@gantry-admin/layouts/css-warnings.html.twig', $this->params),
                    'warning' => true,
                    'title'   => 'CSS Compiled With Warnings',
                ]
            );
        }

        return $this->changeResponse($change, ['html' => 'The CSS was successfully compiled', 'title' => 'CSS Compiled']);
    }

    /**
     * @returns array
     */
    protected function compileSettings()
    {
        /** @var Theme $theme */
        $theme = $this->container['theme'];
        $outline = $this->params['outline'];

        return $theme->updateCss($outline !== 'default' ? [$outline => ucfirst($outline)] : null);
    }
}
