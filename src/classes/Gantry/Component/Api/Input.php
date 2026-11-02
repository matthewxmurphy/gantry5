<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Api;

/** Bounded JSON data, never filenames, executable templates or arbitrary assets. */
final class Input
{
    public const MAX_BYTES = 262144;
    public const MAX_NODES = 512;
    public const MAX_DEPTH = 16;

    public static function shape(array $data, array $allowed, array $required = []): void
    {
        if (array_diff(array_keys($data), $allowed) || array_diff($required, array_keys($data))) {
            throw new ApiException('The request contains unknown fields or is missing required fields.', 400, 'invalid_fields');
        }
        try {
            $bytes = json_encode($data, JSON_THROW_ON_ERROR, 32);
        } catch (\JsonException $error) {
            throw new ApiException('Use valid bounded JSON data.', 400, 'invalid_json', $error);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ApiException('The request exceeds the supported size.', 400, 'request_too_large');
        }
    }

    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_-]{0,127}\z/D', $id)) {
            throw new ApiException('Use a valid installed identifier.', 400, 'invalid_identifier');
        }
        return $id;
    }

    public static function title(mixed $title): string
    {
        if (!is_string($title) || $title !== trim($title) || $title === '' || strlen($title) > 160
            || !preg_match('//u', $title) || preg_match('/[\x00-\x1f\x7f<>]/u', $title)) {
            throw new ApiException('Use a plain-text title of 1 to 160 bytes.', 422, 'invalid_title');
        }
        return $title;
    }

    public static function revision(mixed $revision): string
    {
        if (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
            throw new ApiException('Send the revision from the latest GET response.', 400, 'revision_required');
        }
        return $revision;
    }

    /** Native preset metadata is immutable; JSON object property order is immaterial. */
    public static function preset(mixed $value, array $current): void
    {
        if (!is_array($value)) {
            throw new ApiException('Preserve the preset metadata returned by GET.', 422, 'invalid_preset');
        }
        ksort($value);
        ksort($current);
        if ($value !== $current) {
            throw new ApiException('Preserve the preset metadata returned by GET.', 422, 'invalid_preset');
        }
    }

    /** Merge objects recursively; JSON lists replace whole lists. Null never deletes files. */
    public static function merge(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $current[$key] = is_array($value) && !array_is_list($value) && isset($current[$key]) && is_array($current[$key])
                ? self::merge($current[$key], $value) : $value;
        }
        return $current;
    }

    public static function data(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ApiException('The data is nested too deeply.', 422, 'invalid_data');
        }
        if (is_array($value)) {
            if (count($value) > 512) {
                throw new ApiException('The data contains too many values.', 422, 'invalid_data');
            }
            foreach ($value as $key => $item) {
                if (is_string($key) && (!preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/D', $key) || str_contains($key, '..'))) {
                    throw new ApiException('The data contains an invalid key.', 422, 'invalid_data');
                }
                self::data($item, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > 16384 || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
                throw new ApiException('The data contains invalid text.', 422, 'invalid_data');
            }
        } elseif (!is_int($value) && !is_bool($value) && !is_null($value) && !(is_float($value) && is_finite($value))) {
            throw new ApiException('Use JSON data values.', 422, 'invalid_data');
        }
    }

    /** Safe scalar controls; intentionally excludes custom HTML, Twig, JS, SCSS and executable URLs. */
    public static function safeValue(mixed $value, bool $style = false): void
    {
        self::data($value);
        if (is_array($value)) {
            foreach ($value as $item) {
                self::safeValue($item, $style);
            }
        } elseif (is_string($value)) {
            if (preg_match('/(?:[\t\r\n]|<|>|\{\{|\{%|\{#|\}\}|\?\>|(?:javascript|vbscript|data|file|php|phar|expect):|(?:^|[\/\\\\])\.\.(?:[\/\\\\]|$))/i', $value)
                || ($style && preg_match('/[;{}$@<>\\\\]|(?:url|expression)\s*\(/i', $value))) {
                throw new ApiException('Code, template expressions and unsafe URLs are outside this API.', 422, 'unsafe_value');
            }
        }
    }

    /** Strict native-editor node envelope; blueprint checks happen in ThemeApi. */
    public static function layout(mixed $nodes): array
    {
        if (!is_array($nodes) || !array_is_list($nodes)) {
            throw new ApiException('Layout must be a native editor node list.', 422, 'invalid_layout');
        }
        $ids = [];
        $count = 0;
        self::nodes($nodes, $ids, $count, 0);
        return $nodes;
    }

    private static function nodes(array $nodes, array &$ids, int &$count, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ApiException('The layout is nested too deeply.', 422, 'invalid_layout');
        }
        foreach ($nodes as $node) {
            if (!is_array($node) || array_is_list($node)) {
                throw new ApiException('Each layout node must be an object.', 422, 'invalid_layout');
            }
            self::shape($node, ['id', 'type', 'subtype', 'title', 'attributes', 'children', 'layout', 'inherit', 'block'], ['id', 'type', 'subtype', 'attributes']);
            $id = self::id($node['id']);
            if (++$count > self::MAX_NODES || isset($ids[$id])) {
                throw new ApiException('The layout contains too many nodes or duplicate IDs.', 422, 'invalid_layout');
            }
            $ids[$id] = true;
            if (!in_array($node['type'], ['wrapper', 'container', 'section', 'grid', 'block', 'offcanvas', 'particle', 'position', 'system'], true)) {
                throw new ApiException('The layout contains an unsupported node type.', 422, 'invalid_layout');
            }
            self::id($node['subtype']);
            if (isset($node['title'])) {
                self::title($node['title']);
            }
            if (!is_array($node['attributes']) || (isset($node['layout']) && !is_bool($node['layout']))
                || (isset($node['block']) && !is_array($node['block']))) {
                throw new ApiException('The layout contains invalid node attributes.', 422, 'invalid_layout');
            }
            self::data($node['attributes']);
            if (isset($node['inherit'])) {
                if (!is_array($node['inherit'])) {
                    throw new ApiException('The layout contains invalid inheritance.', 422, 'invalid_layout');
                }
                self::shape($node['inherit'], ['outline', 'particle', 'include']);
                if (isset($node['inherit']['outline'])) { self::id($node['inherit']['outline']); }
                if (isset($node['inherit']['particle'])) { self::id($node['inherit']['particle']); }
                $include = $node['inherit']['include'] ?? [];
                if (!is_array($include) || !array_is_list($include)) {
                    throw new ApiException('The layout contains invalid inheritance.', 422, 'invalid_layout');
                }
                foreach ($include as $selector) {
                    if (!is_string($selector) || !in_array($selector, ['attributes', 'block', 'children'], true)) {
                        throw new ApiException('The layout contains invalid inheritance.', 422, 'invalid_layout');
                    }
                }
            }
            if (isset($node['children'])) {
                if (!is_array($node['children']) || !array_is_list($node['children'])
                    || in_array($node['type'], ['particle', 'position', 'system'], true)) {
                    throw new ApiException('The layout contains invalid children.', 422, 'invalid_layout');
                }
                self::nodes($node['children'], $ids, $count, $depth + 1);
            }
        }
    }
}
