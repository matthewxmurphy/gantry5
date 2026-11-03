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

        $inventory = [];
        foreach ($database->loadAssocList() as $extension) {
            $manifest = json_decode((string) $extension['manifest_cache'], true);
            $version = $manifest['version'] ?? null;
            $plugin = $extension['type'] === 'plugin';
            $enabled = (bool) $extension['enabled'];
            $documented = $plugin && $extension['folder'] === 'system' && $extension['element'] === 'gantry5';
            $detected = $plugin && $extension['folder'] === 'webservices' && $enabled;

            $inventory[] = [
                'id' => (int) $extension['extension_id'],
                'name' => $extension['name'],
                'type' => $extension['type'],
                'folder' => $extension['folder'],
                'element' => $extension['element'],
                'enabled' => $enabled,
                'version' => is_string($version) || is_int($version) ? (string) $version : null,
                'state' => $documented ? 'documented' : ($detected ? 'discovered' : 'none'),
                'operations' => $documented ? $operations : [],
            ];
        }

        return $inventory;
    }
}
