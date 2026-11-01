<?php
/**
 * Read-only saved-frontend viewport preview.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 */
namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Response\HtmlResponse;

class Preview extends HtmlController
{
    protected $httpVerbs = ['GET' => ['/' => 'index']];

    public function index()
    {
        if (!$this->authorize('platform.settings.manage')) {
            $this->forbidden();
        }
        $this->params['saved_site_url'] = $this->container['platform']->getSavedSiteUrl();
        $response = new HtmlResponse($this->render('@gantry-admin/pages/configurations/preview.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }
}
