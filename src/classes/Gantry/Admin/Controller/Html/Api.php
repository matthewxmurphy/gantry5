<?php
/**
 * In-CMS API discovery. No credentials are read, generated or embedded here.
 * Copyright (C) 2026 Matthew X Murphy https://MatthewxMurphy.com
 * SPDX-License-Identifier: MIT
 */
namespace Gantry\Admin\Controller\Html;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Api\OpenApi;
use Gantry\Component\Api\OpenApiResponse;
use Gantry\Component\Response\HtmlResponse;

class Api extends HtmlController
{
    protected $httpVerbs = ['GET' => ['/' => 'index', '/openapi' => 'document']];

    public function index()
    {
        $this->requireAdministrator();
        $platform = $this->container['platform']->getName();
        $this->params['api_available'] = $platform === 'joomla';
        $specification = $this->specification();
        $this->params['api_spec_json'] = json_encode($specification, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($platform === 'joomla') {
            $database = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $this->params['api_extension_inventory'] = \Gantry\Joomla\ExtensionApiInventory::read($database, $specification);
        }
        $response = new HtmlResponse($this->render('@gantry-admin/pages/api.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function document()
    {
        $this->requireAdministrator();
        $response = new OpenApiResponse($this->specification());
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function requireAdministrator(): void
    {
        if (!$this->authorize('platform.settings.manage')) {
            throw new \RuntimeException('API documentation requires Gantry settings administration.', 403);
        }
    }

    private function specification(): array
    {
        $server = $this->container['platform']->getName() === 'joomla'
            ? \Joomla\CMS\Uri\Uri::root(true) . '/api/index.php' : '/api/index.php';
        return OpenApi::document($server, (string) $this->container['theme.name']);
    }
}
