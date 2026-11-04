<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Admin\Controller\Html;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Response\HtmlResponse;

/** Native administrator view over the same journal used by REST and later MCP. */
final class Changes extends HtmlController
{
    protected $httpVerbs = ['GET' => ['/' => 'index'], 'POST' => ['/undo' => 'undo']];

    public function index()
    {
        if (!$this->authorize('platform.settings.manage')) { $this->forbidden(); }
        $this->params['changes'] = (new ChangeEngine($this->container))->history();
        $response = new HtmlResponse($this->render('@gantry-admin/pages/changes.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function undo()
    {
        if (!$this->authorize('platform.settings.manage')) { $this->forbidden(); }
        if ($this->request->post['undo_confirm'] !== 'yes') { throw new \RuntimeException('Confirm undo of the latest theme change.', 400); }
        $revision = $this->request->post['g6_revision'];
        $id = $this->request->post['change_id'];
        if (!is_string($revision) || !is_string($id)) { throw new \RuntimeException('Reload change history before undoing.', 409); }
        (new ChangeEngine($this->container))->undo($revision, $id);
        $this->params['change_status'] = 'The latest theme change was undone. Reload other open editors before making further changes.';
        return $this->index();
    }
}
