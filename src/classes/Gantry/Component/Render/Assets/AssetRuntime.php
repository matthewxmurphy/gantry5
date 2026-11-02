<?php
/**
 * Lookup-only asset substitution for the pinned immutable render generation.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Gantry\Component\Render\BundleRuntime;
use Gantry\Framework\Gantry;

final class AssetRuntime
{
    private static array $validated = [];

    public static function manifest(?Gantry $gantry = null): ?array
    {
        $gantry ??= Gantry::instance();
        if (!class_exists(BundleRuntime::class) || !($bundle = BundleRuntime::current($gantry))) { return null; }
        $outline = isset($gantry['configuration']) ? (string) $gantry['configuration'] : 'default';
        $manifest = $bundle->outlineData($outline, 'assets');
        if (!isset(self::$validated[$bundle->id()][$outline])) { self::validate($manifest); self::$validated[$bundle->id()][$outline] = true; }
        return $manifest;
    }

    /** Validate once per pinned generation, before any runtime indexing. */
    public static function validate(array $manifest): void
    {
        $bad = static function (): never { throw new \RuntimeException('The compiled asset manifest is invalid.'); };
        if (($manifest['schema'] ?? null) !== 1 || !is_array($manifest['options'] ?? null) || !is_array($manifest['files'] ?? null) || !is_array($manifest['pairs'] ?? null) || !is_array($manifest['css'] ?? null) || !is_array($manifest['particles'] ?? null) || !is_array($manifest['images'] ?? null) || !is_string($manifest['critical'] ?? null) || !is_string($manifest['main_image'] ?? null)) { $bad(); }
        foreach (['version', 'minify', 'combine'] as $option) { if (!is_bool($manifest['options'][$option] ?? null)) { $bad(); } }
        foreach ($manifest['css'] as $url) { if (!self::localUrl($url)) { $bad(); } }
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['id']) || !self::localUrl($entry['url'] ?? null) || !self::localUrl($entry['source_url'] ?? null) || !in_array($entry['kind'] ?? null, ['style', 'script'], true) || !is_bool($entry['combine'] ?? null) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0) { $bad(); }
        }
        foreach ($manifest['pairs'] as $entry) { if (!is_array($entry) || !self::localUrl($entry['url'] ?? null) || !is_string($entry['id'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['id']) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0) { $bad(); } }
        foreach ($manifest['images'] as $entry) { if (!ImageEntry::valid($entry)) { $bad(); } }
        foreach ($manifest['particles'] as $entry) { if (!is_array($entry) || !is_string($entry['type'] ?? null) || !is_array($entry['assets'] ?? null)) { $bad(); } foreach ($entry['assets'] as $uri) { if (!is_string($uri)) { $bad(); } } }
    }

    private static function localUrl(mixed $url): bool { return is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\x00-\x20]/', $url); }

    /** Resolve known stream aliases without touching original asset/image files. */
    public static function sourceUrl(string $uri, ?Gantry $gantry = null): ?string
    {
        if (!str_starts_with($uri, 'gantry-') || strpbrk($uri, '?#') !== false) { return null; }
        $manifest = self::manifest($gantry);
        $entry = $manifest['files'][$uri] ?? $manifest['images'][$uri] ?? null;
        return is_array($entry) && self::localUrl($entry['source_url'] ?? null) ? $entry['source_url'] : null;
    }

    /** No source lookup, filesystem access, minification or concatenation here. */
    public static function records(array $records, string $kind, ?Gantry $gantry = null): array
    {
        $manifest = self::manifest($gantry);
        return $manifest === null ? $records : self::substitute($records, $kind, $manifest);
    }

    /** A verified immutable replacement needs no native URL parsing or source probe. */
    public static function preparedUrl(array $record, string $kind, array $manifest): ?string
    {
        if (!($manifest['options']['version'] ?? true) || !self::eligible($record, $kind)) { return null; }
        $entry = $manifest['files'][$record[$kind === 'style' ? 'href' : 'src'] ?? ''] ?? null;
        return is_array($entry) ? $entry['url'] : null;
    }

    public static function substitute(array $records, string $kind, array $manifest): array
    {
        $key = $kind === 'style' ? 'href' : 'src';
        $records = array_is_list($records) ? $records : array_values($records);
        $output = [];
        for ($i = 0, $length = count($records); $i < $length; ++$i) {
            $record = $records[$i];
            $asset = self::eligible($record, $kind) ? ($manifest['files'][$record[$key] ?? ''] ?? null) : null;
            if (!$asset || !($manifest['options']['version'] ?? true)) { $output[] = $record; continue; }
            $next = $records[$i + 1] ?? null;
            $nextAsset = $next && self::eligible($next, $kind) ? ($manifest['files'][$next[$key] ?? ''] ?? null) : null;
            $pair = $nextAsset ? ($manifest['pairs'][self::pairKey($kind, $asset['id'], $nextAsset['id'])] ?? null) : null;
            if (($manifest['options']['combine'] ?? false) && $pair && ($record[':priority'] ?? 0) === ($next[':priority'] ?? 0)
                && ($record['media'] ?? null) === ($next['media'] ?? null)
                && ($record['defer'] ?? false) === ($next['defer'] ?? false)) {
                $record[$key] = $pair['url'];
                ++$i;
            } else { $record[$key] = $asset['url']; }
            $output[] = $record;
        }
        return $output;
    }

    /** Shared build/runtime identity; frontend never autoloads the offline compiler. */
    public static function pairKey(string $kind, string $first, string $second): string
    {
        return hash('sha256', "$kind\0$first\0$second");
    }

    /** Attribute, integrity, identity, module and dependency barriers stay native. */
    private static function eligible(array $record, string $kind): bool
    {
        if (($record[':type'] ?? '') !== 'file' || !empty($record['element']) || !empty($record['id']) || !empty($record['dependencies']) || !empty($record['handle'])) { return false; }
        if ($kind === 'script') {
            return in_array($record['type'] ?? 'text/javascript', ['text/javascript', 'application/javascript'], true) && empty($record['async']);
        }
        return ($record['type'] ?? 'text/css') === 'text/css';
    }

    public static function critical(?Gantry $gantry = null): string
    {
        return (string) (self::manifest($gantry)['critical'] ?? '');
    }

    public static function image(string $uri, ?Gantry $gantry = null): ?array
    {
        return self::manifest($gantry)['images'][$uri] ?? null;
    }

    public static function html(string $html, ?Gantry $gantry = null): string
    {
        $manifest = self::manifest($gantry);
        return $manifest === null ? $html : ImagesRuntime::html($html, $manifest);
    }
}
