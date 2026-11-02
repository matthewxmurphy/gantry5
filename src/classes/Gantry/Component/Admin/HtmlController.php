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

namespace Gantry\Component\Admin;

use Gantry\Admin\Theme;
use Gantry\Component\Controller\HtmlController as BaseController;
use Gantry\Framework\Platform;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Response\JsonResponse;

/**
 * Class HtmlController
 * @package Gantry\Component\Admin
 */
abstract class HtmlController extends BaseController
{
    /**
     * @param string|array $file
     * @param array $context
     * @return string
     */
    public function render($file, array $context = [])
    {
        /** @var Theme $theme */
        $theme = $this->container['admin.theme'];

        // The theme chooser and about pages can render before a theme is selected.
        $context['g6_revision'] = isset($this->container['theme.name'])
            ? (new ChangeEngine($this->container))->revision() : null;
        return $theme->render($file, $context);
    }

    /** Native adapters retain their authorization and pass all persistence through one engine. */
    protected function changeSave(string $label, callable $writer, ?callable $planner = null): array
    {
        // General native writes use the same settings-administrator boundary as
        // REST. Module-manager JSON admission must not bypass that boundary.
        // Client::save separately enforces the explicit approved-text policy.
        if ($label !== 'client.text' && !$this->authorize('platform.settings.manage')) { $this->forbidden(); }
        $engine = new ChangeEngine($this->container);
        $revision = $this->request->post['g6_revision'];
        if (!is_string($revision)) { throw new \RuntimeException('Reload this editor before saving; its revision is missing.', 409); }
        if ($this->request->post['g6_mode'] === 'preview') {
            if ($planner === null) { throw new \RuntimeException('This operation has no no-write preview.', 400); }
            return $engine->preview($revision, $planner) + ['preview' => true];
        }
        return $engine->save($revision, $label, $writer);
    }

    protected function changeResponse(array $change, array $content = []): JsonResponse
    {
        return new JsonResponse($content + array_intersect_key($change, array_flip(['revision', 'change_id', 'preview', 'result', 'plan'])));
    }

    /**
     * @param string $action
     * @param string $id
     * @return boolean
     */
    public function authorize($action, $id = null)
    {
        /** @var Platform $platform */
        $platform = $this->container['platform'];

        return $platform->authorize($action, $id);
    }
}
