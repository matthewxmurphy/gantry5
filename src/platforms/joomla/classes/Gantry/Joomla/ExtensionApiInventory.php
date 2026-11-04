<?php
/**
 * Read-only Joomla extension inventory for Gantry's API administration page.
 * Copyright (C) 2026 Matthew X Murphy https://MatthewxMurphy.com
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Gantry\Joomla;

use Joomla\Database\DatabaseInterface;

final class ExtensionApiInventory
{
    private const MAX_DECLARATION_BYTES = 262144;
    private const MAX_DECLARED_OPERATIONS = 256;
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public static function read(DatabaseInterface $database, array $specification): array
    {
        $query = $database->createQuery()
            ->select($database->quoteName(['extension_id', 'name', 'type', 'folder', 'element', 'enabled', 'manifest_cache']))
            ->from($database->quoteName('#__extensions'))
            ->order($database->quoteName('extension_id'));

        $database->setQuery($query);
        $operations = [];
        foreach ($specification['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (isset($operation['operationId'])) {
                    $operations[] = strtoupper($method) . ' ' . $path;
                }
            }
        }

        $plugins = defined('JPATH_PLUGINS') ? realpath(JPATH_PLUGINS) : false;
        $inventory = [];
        foreach ($database->loadAssocList() as $extension) {
            $manifest = json_decode((string) $extension['manifest_cache'], true);
            $version = $manifest['version'] ?? null;
            $plugin = $extension['type'] === 'plugin';
            $enabled = (bool) $extension['enabled'];
            $documented = $plugin && $extension['folder'] === 'system' && $extension['element'] === 'gantry5';
            $detected = $plugin && $extension['folder'] === 'webservices' && $enabled;
            $declaration = $detected && $plugins !== false
                ? self::readDeclaration($plugins, $extension['element'])
                : null;

            $inventory[] = [
                'id' => (int) $extension['extension_id'],
                'name' => $extension['name'],
                'type' => $extension['type'],
                'folder' => $extension['folder'],
                'element' => $extension['element'],
                'enabled' => $enabled,
                'version' => is_string($version) || is_int($version) ? (string) $version : null,
                'state' => $documented || $declaration !== null ? 'documented' : ($detected ? 'discovered' : 'none'),
                'operations' => $documented ? $operations : ($declaration['operations'] ?? []),
                'contract' => $documented ? [
                    'format' => 'openapi',
                    'version' => $specification['openapi'],
                    'server' => $specification['servers'][0]['url'],
                    'security' => $specification['security'],
                    'security_schemes' => $specification['components']['securitySchemes'],
                    'permission' => 'platform.settings.manage',
                ] : ($declaration['contract'] ?? null),
            ];
        }

        return $inventory;
    }

    private static function readDeclaration(string $plugins, string $element): ?array
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $element)) {
            return null;
        }

        $file = $plugins . DIRECTORY_SEPARATOR . 'webservices' . DIRECTORY_SEPARATOR . $element
            . DIRECTORY_SEPARATOR . 'openapi.json';
        if (realpath($file) !== $file || !is_file($file) || !is_readable($file)) {
            return null;
        }

        // An extension can be uninstalled or updated while the inventory is being read.
        $contents = @file_get_contents($file, length: self::MAX_DECLARATION_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_DECLARATION_BYTES) {
            return null;
        }

        $document = json_decode($contents, depth: 64);
        if (!$document instanceof \stdClass || !is_string($document->openapi ?? null)
            || !preg_match('/\A3\.[0-9]{1,3}\.[0-9]{1,3}\z/', $document->openapi)
            || !($document->info ?? null) instanceof \stdClass
            || !is_string($document->info->title ?? null) || trim($document->info->title) === ''
            || !is_string($document->info->version ?? null) || trim($document->info->version) === ''
            || !($document->paths ?? null) instanceof \stdClass
        ) {
            return null;
        }

        $operations = self::declaredOperations($document->paths, str_starts_with($document->openapi, '3.0.'));
        if (!$operations) {
            return null;
        }

        return [
            'operations' => $operations,
            'contract' => ['format' => 'openapi', 'version' => $document->openapi, 'source' => 'installed-plugin'],
        ];
    }

    private static function declaredOperations(\stdClass $paths, bool $requireResponses): ?array
    {
        $operations = [];
        foreach ($paths as $path => $item) {
            if (str_starts_with($path, 'x-')) {
                continue;
            }
            if (!str_starts_with($path, '/') || str_starts_with($path, '//') || strlen($path) > 512
                || preg_match('/[\x00-\x20\x7f?#\\\\]/', $path) || !$item instanceof \stdClass
            ) {
                return null;
            }

            foreach ($item as $method => $operation) {
                if (!in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }
                if (!$operation instanceof \stdClass
                    || (($requireResponses || property_exists($operation, 'responses'))
                        && !self::hasResponses($operation->responses ?? null))
                    || count($operations) === self::MAX_DECLARED_OPERATIONS
                ) {
                    return null;
                }
                $operations[] = strtoupper($method) . ' ' . $path;
            }
        }

        return $operations;
    }

    private static function hasResponses(mixed $responses): bool
    {
        if (!$responses instanceof \stdClass) {
            return false;
        }

        $hasResponse = false;
        foreach ($responses as $code => $response) {
            if (str_starts_with($code, 'x-')) {
                continue;
            }
            if (!preg_match('/\A(?:default|[1-5](?:[0-9]{2}|XX))\z/', $code) || !$response instanceof \stdClass
                || (!is_string($response->description ?? null) && !is_string($response->{'$ref'} ?? null))
            ) {
                return false;
            }
            $hasResponse = true;
        }

        return $hasResponse;
    }
}
