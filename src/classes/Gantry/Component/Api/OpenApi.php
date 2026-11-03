<?php
/**
 * One shipped API contract for the administrator Swagger page and JSON export.
 * Copyright (C) 2026 Matthew X Murphy https://MatthewxMurphy.com
 * SPDX-License-Identifier: MIT
 */
namespace Gantry\Component\Api;

final class OpenApi
{
    public static function document(string $server = '/api/index.php', string $theme = ''): array
    {
        $string = ['type' => 'string'];
        $object = ['type' => 'object', 'additionalProperties' => true];
        $revision = $string + ['description' => 'Opaque theme-wide content and generation revision returned by GET. Required for every update and delete. Stale revisions return 409.'];
        $schemas = [
            'CreateOutline' => self::input(['title' => $string + ['minLength' => 1], 'preset' => $string, 'revision' => array_replace($revision, ['description' => 'Optional for backward-compatible creation; send the preview revision to detect intervening theme edits.'])], ['title']),
            'UpdateOutline' => self::input(['title' => $string + ['minLength' => 1], 'revision' => $revision], ['title', 'revision']),
            'DeleteOutline' => self::input(['revision' => $revision], ['revision']),
            'UpdateLayout' => self::input(['layout' => ['type' => 'array', 'items' => $object, 'description' => 'Native Gantry layout editor node list returned by GET.'], 'preset' => $object, 'revision' => $revision], ['layout', 'revision']),
            'UpdateSettings' => self::input(['styles' => $object, 'page' => $object, 'particles' => $object, 'revision' => $revision], ['revision']),
            'PreviewChange' => self::input(['revision' => $revision, 'operation' => ['type' => 'string', 'enum' => ['create', 'rename', 'delete', 'layout', 'settings']], 'outline' => $string, 'data' => $object], ['revision', 'operation', 'data']),
            'UndoChange' => self::input(['revision' => $revision, 'change_id' => $string + ['pattern' => '^[a-f0-9]{64}$']], ['revision', 'change_id']),
            'Resource' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/ResourceData']]],
            'ResourceData' => ['type' => 'object', 'required' => ['type', 'id', 'attributes'], 'properties' => ['type' => $string, 'id' => $string, 'attributes' => $object + ['description' => 'Resource fields including theme, revision and native outline/layout/settings data.']]],
            'Collection' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ResourceData']]]],
            'Error' => ['type' => 'object', 'properties' => ['errors' => ['type' => 'array', 'items' => $object]]],
        ];
        $base = '/v1/gantry/themes/{theme}/outlines';
        $item = $base . '/{outline}';
        $paths = [
            '/v1/gantry/themes/{theme}/changes' => ['get' => self::operation('history', 'Changes', 'Read private-safe change summaries and current theme revision')],
            '/v1/gantry/themes/{theme}/changes/preview' => ['post' => self::operation('preview', 'Changes', 'Validate and preview a change without writing configuration, firing events or compiling CSS', 'PreviewChange')],
            '/v1/gantry/themes/{theme}/changes/undo' => ['post' => self::operation('undo', 'Changes', 'Undo the current latest change using its current theme revision', 'UndoChange')],
            $base => [
                'get' => self::operation('listOutlines', 'Outlines', 'List outlines for an installed Gantry theme', null, 'Collection'),
                'post' => self::operation('createOutline', 'Outlines', 'Create a new unassigned outline from an installed preset', 'CreateOutline', 'Resource', '201'),
            ],
            $item => [
                'get' => self::operation('getOutline', 'Outlines', 'Read outline metadata and its revision'),
                'patch' => self::operation('updateOutline', 'Outlines', 'Rename an outline using its current revision', 'UpdateOutline'),
                'delete' => self::operation('deleteOutline', 'Outlines', 'Delete an unassigned non-system outline using its current revision', 'DeleteOutline', null, '204'),
            ],
            $item . '/layout' => [
                'get' => self::operation('getLayout', 'Layouts', 'Read the native layout document and its revision'),
                'put' => self::operation('updateLayout', 'Layouts', 'Save a validated native layout document', 'UpdateLayout'),
            ],
            $item . '/settings' => [
                'get' => self::operation('getSettings', 'Theme settings', 'Read this outline’s theme styles, page and particle settings'),
                'patch' => self::operation('updateSettings', 'Theme settings', 'Save selected blueprint-validated theme setting groups', 'UpdateSettings'),
            ],
        ];
        foreach ($paths as $path => &$operations) {
            $operations['parameters'] = [['name' => 'theme', 'in' => 'path', 'required' => true, 'schema' => $string, 'example' => $theme ?: 'g6_helium']];
            if (str_contains($path, '{outline}')) {
                $operations['parameters'][] = ['name' => 'outline', 'in' => 'path', 'required' => true, 'schema' => $string, 'example' => '20'];
            }
        }
        unset($operations);
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Gantry 6 core API', 'version' => '6.0.2-rc.2',
                'description' => 'Joomla 6.2 core Web Services and existing Joomla core token authentication. PHP 8.5 only. No bridge, extra component or custom token. Use the existing Joomla user API token with X-Joomla-Token; basic authentication stays disabled. Gantry settings-administrator permissions are required. Writes use the native outline, layout and blueprint configuration stores. New outlines are unassigned; assigned/default/system outlines cannot be deleted. The API does not edit files, users, menus, template code or credentials. No separate application rate limiter is installed; existing host/Joomla policies apply. Admin and REST share one change engine, native writers, theme lock, exact-state recovery and a maximum of 20 undo snapshots. Preview is no-write validation, not a rendered staging URL. Undo only the latest change with a matching current revision. Clearing derived caches retains history. An interrupted change blocks further edits pending operator recovery. Requests are synchronous and writes are revision checked; retrieve the resource again after 409. Normal CMS/host access logs may record method, path, status and user identity. Never include a token in URLs or reports.',
                'contact' => ['name' => 'Matthew X Murphy', 'url' => 'https://builtnotbegged.com'],
                'license' => ['name' => 'Existing Gantry MIT or GPL-2.0-or-later terms; Joomla extensions GPL-2.0-or-later'],
            ],
            'servers' => [['url' => $server, 'description' => 'This CMS Joomla Web Services entrypoint']],
            'tags' => [['name' => 'Outlines'], ['name' => 'Layouts'], ['name' => 'Theme settings'], ['name' => 'Changes']],
            'security' => [['JoomlaToken' => []]],
            'paths' => $paths,
            'components' => ['securitySchemes' => ['JoomlaToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Joomla-Token', 'description' => 'Your existing Joomla core API token; it is never generated by Gantry.']], 'schemas' => $schemas],
        ];
    }

    private static function input(array $properties, array $required): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    }

    private static function operation(string $id, string $tag, string $summary, ?string $input = null, ?string $output = 'Resource', string $status = '200'): array
    {
        $success = ['description' => $status === '204' ? 'Outline deleted; empty response.' : 'Successful native Gantry operation.'];
        if ($output !== null) {
            $success['content'] = ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/' . $output]]];
        }
        $operation = ['operationId' => $id, 'tags' => [$tag], 'summary' => $summary, 'responses' => [$status => $success]];
        foreach (['400' => 'Malformed request.', '401' => 'Joomla token missing or invalid.', '403' => 'Insufficient native Joomla/Gantry permissions.', '404' => 'Installed theme or outline not found.', '409' => 'Stale revision or protected outline conflict.', '413' => 'Request body exceeds the transport limit.', '415' => 'Send an application/json object.', '422' => 'Blueprint or input validation failed.', '503' => 'The required runtime is unavailable.', '500' => 'Operation failed; consult the private server logs.'] as $code => $description) {
            $operation['responses'][$code] = ['description' => $description, 'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        if ($input !== null) {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $input]]]];
        }
        return $operation;
    }
}
