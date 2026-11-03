<?php
/**
 * Native Joomla Web Asset Manager adapter for Gantry's rendered asset records.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Joomla\CMS\WebAsset\Exception\UnknownAssetException;
use Joomla\CMS\WebAsset\WebAssetManager;

/** WAM owns registration, dependencies and sorting; only Gantry footer tags are materialized here. */
final class JoomlaAssets
{
    public static function head(WebAssetManager $manager, string $kind, array $records, string $critical = ''): void
    {
        $handles = self::register($manager, $kind, $records, 'head');
        if ($critical !== '' && $kind === 'style') {
            $first = null;
            foreach ($handles as $handle => $record) { if (($record[':type'] ?? '') === 'file') { $first = $handle; break; } }
            $manager->addInlineStyle($critical, ['name' => 'gantry.compiled.critical.' . hash('sha256', $critical), 'position' => 'before'], [], $first ? [$first] : []);
        }
        // A footer dependency cannot execute before an active head consumer.
        self::assertHead($manager, $kind);
    }

    public static function assertHead(WebAssetManager $manager, string $kind): void
    {
        foreach ($manager->getAssets($kind, true) as $asset) {
            if ($asset->getOption('gantry.location') === 'footer') { throw new \RuntimeException('A head asset depends on a Gantry footer asset; an explicit execution order is required.'); }
        }
    }

    /** Footer files are sorted by the real WAM graph, then removed from the native head queue. */
    public static function footer(WebAssetManager $manager, array $records, ?string $nonce = null): array
    {
        if (!$records) { return []; }
        // Check before adding footer handles: an existing head cannot acquire one.
        $head = $manager->getAssets('script', true);
        $handles = self::register($manager, 'script', $records, 'footer');
        try {
            $assets = $manager->getAssets('script', true);
            foreach ($head as $asset) {
                foreach ($asset->getDependencies() as $dependency) {
                    if (isset($handles[explode('#', $dependency, 2)[0]])) { throw new \RuntimeException('A head script cannot depend on a Gantry footer script.'); }
                }
            }
            $inline = $manager->filterOutInlineAssets($assets);
            $relations = $manager->getInlineRelation($inline);
            $output = [];
            $emit = static function ($asset) use (&$output, $handles, $nonce): void {
                $name = $asset->getName();
                if (!isset($handles[$name])) { return; }
                $attributes = $asset->getAttributes();
                if ($nonce !== null && $nonce !== '' && !isset($attributes['nonce'])) { $attributes['nonce'] = $nonce; }
                if ($asset->getOption('inline')) {
                    $output[] = '<script' . self::attributes($attributes) . '>' . $asset->getOption('content') . '</script>';
                } else {
                    // URI is already Gantry-resolved; no native path probing or version rewriting.
                    $output[] = '<script src="' . self::escape($asset->getUri(false)) . '"' . self::attributes($attributes) . '></script>';
                }
            };
            foreach ($assets as $name => $asset) {
                foreach ($relations[$name]['before'] ?? [] as $entry) { $emit($entry); unset($inline[$entry->getName()]); }
                $emit($asset);
                foreach ($relations[$name]['after'] ?? [] as $entry) { $emit($entry); unset($inline[$entry->getName()]); }
            }
            foreach ($inline as $entry) { $emit($entry); }
            return $output;
        } finally {
            foreach (array_keys($handles) as $handle) { $manager->disableScript($handle); }
        }
    }

    private static function register(WebAssetManager $manager, string $kind, array $records, string $location): array
    {
        if (!$records) { return []; }
        self::assertMutable($manager, $kind);
        $handles = [];
        $files = [];
        foreach (array_values($records) as $index => $record) {
            $handle = is_string($record['handle'] ?? null) && $record['handle'] !== '' ? $record['handle'] : 'gantry.compiled.' . $location . '.' . $kind . '.' . hash('sha256', $index . ':' . json_encode($record, JSON_THROW_ON_ERROR));
            if (isset($handles[$handle])) { throw new \RuntimeException('Duplicate Gantry Web Asset handle.'); }
            $handles[$handle] = $record;
            if (($record[':type'] ?? '') === 'file') { $files[$index] = $handle; }
        }
        $previous = null;
        foreach (array_values($records) as $index => $record) {
            $handle = array_keys($handles)[$index];
            $dependencies = array_values((array) ($record['dependencies'] ?? []));
            $attributes = (array) ($record['element'] ?? []);
            $attributes['type'] = $record['type'] ?? ($kind === 'style' ? 'text/css' : 'text/javascript');
            if (!empty($record['id'])) { $attributes['id'] = $record['id']; }
            if ($kind === 'style') { $attributes['media'] = $record['media'] ?? null; }
            else { $attributes['defer'] = $record['defer'] ?? false; $attributes['async'] = $record['async'] ?? false; }
            // Native renderers stringify null attributes as the literal 'null'.
            $attributes = array_filter($attributes, static fn (mixed $value): bool => $value !== null);
            $options = ['version' => false, 'gantry.location' => $location];
            if (($record[':type'] ?? '') === 'file') {
                if ($previous !== null) { $dependencies[] = $previous; }
                if ($manager->assetExists($kind, $handle) && $manager->getAsset($kind, $handle)->getOption('gantry.location') !== $location) { throw new \RuntimeException('Gantry must not replace a native Web Asset handle.'); }
                $asset = new JoomlaResolvedAsset($handle, (string) $record[$kind === 'style' ? 'href' : 'src'],
                    $options + ['type' => $kind], $attributes, array_values(array_unique($dependencies)));
                $manager->registerAsset($kind, $asset);
                $manager->useAsset($kind, $handle);
                $previous = $handle;
            } else {
                $anchor = $previous ?? (reset($files) ?: null);
                if ($anchor !== null) { $dependencies[] = $anchor; $options['position'] = $previous === null ? 'before' : 'after'; }
                $options['name'] = $handle;
                $manager->addInline($kind, (string) ($record['content'] ?? ''), $options, $attributes, array_values(array_unique($dependencies)));
            }
        }
        return $handles;
    }

    private static function assertMutable(WebAssetManager $manager, string $kind): void
    {
        // Joomla has no public isLocked(). A missing-handle disable is read-only:
        // the native lock check runs before registry lookup and before any mutation.
        $probe = 'gantry.lock.probe.' . bin2hex(random_bytes(8));
        try { $manager->disableAsset($kind, $probe); }
        catch (UnknownAssetException) { return; }
        throw new \RuntimeException('Unexpected Web Asset probe collision.');
    }

    private static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $name => $value) {
            if (!preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/D', (string) $name) || $value === null || $value === false) { continue; }
            if (!is_scalar($value)) { throw new \RuntimeException('Invalid Web Asset attribute.'); }
            $result .= ' ' . $name . ($value === true ? '' : '="' . self::escape((string) $value) . '"');
        }
        return $result;
    }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
