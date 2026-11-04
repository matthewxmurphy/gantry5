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
use Gantry\Component\Response\JsonResponse;

class Api extends HtmlController
{
    public const JSON_CACHE_CONTROL = 'private, no-store';
    public const JSON_CONTENT_TYPE_OPTIONS = 'nosniff';

    protected $httpVerbs = ['GET' => ['/' => 'index', '/openapi' => 'document', '/inventory' => 'inventory']];

    private const TEMPLATE_LIBRARY_REVIEW = [
        'report' => 'TEMPLATE-PACKS-PRECHECK-2026-11-03',
        'source' => 'owner_relay',
        'reconciled_packs' => 670,
        'render_checks' => ['passed' => 669, 'total' => 669],
        'authenticated_acceptance' => 'pending',
        'partial_merge_scope' => 'feature_ports_only',
        'lineages' => [
            'tc-faq' => 'drop',
            'tc-gallery' => 'merge_partial',
            'tc-hero' => 'merge_partial',
            'tc-pricing' => 'drop',
            'tc-testimonials' => 'merge_partial',
            'tc-text-block' => 'merge_partial',
        ],
        'remainders' => [
            'R1' => 'Supply admin/images/preset1.png during packaging. Every pack references it and none contains it.',
            'R5' => 'Preserve the Hydrogen base names.',
            'R6' => 'Resolve the missing image basename in each of the six affected packs.',
        ],
    ];

    public function index()
    {
        $this->requireAdministrator();
        $platform = $this->container['platform']->getName();
        $this->params['api_available'] = $platform === 'joomla';
        $specification = $this->specification();
        $this->params['api_spec_json'] = json_encode($specification, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($platform === 'joomla') {
            $this->params['api_extension_inventory'] = $this->readInventory($specification);
            $this->params['template_library_review'] = self::TEMPLATE_LIBRARY_REVIEW;
        }
        $response = new HtmlResponse($this->render('@gantry-admin/pages/api.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function document()
    {
        $this->requireAdministrator();
        $response = new OpenApiResponse($this->specification());
        $response->setHeader('Cache-Control', self::JSON_CACHE_CONTROL);
        $response->setHeader('X-Content-Type-Options', self::JSON_CONTENT_TYPE_OPTIONS);
        return $response;
    }

    public function inventory(): JsonResponse
    {
        $this->requireAdministrator();
        if ($this->container['platform']->getName() !== 'joomla') {
            throw new \RuntimeException('Extension API inventory is available on Joomla.', 404);
        }
        $specification = $this->specification();
        $response = new JsonResponse([
            'schema_version' => 1,
            'extensions' => $this->readInventory($specification),
            'template_library_review' => self::TEMPLATE_LIBRARY_REVIEW,
        ]);
        $response->setHeader('Cache-Control', self::JSON_CACHE_CONTROL);
        $response->setHeader('X-Content-Type-Options', self::JSON_CONTENT_TYPE_OPTIONS);
        return $response;
    }

    private function readInventory(array $specification): array
    {
        $database = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        return \Gantry\Joomla\ExtensionApiInventory::read($database, $specification);
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
