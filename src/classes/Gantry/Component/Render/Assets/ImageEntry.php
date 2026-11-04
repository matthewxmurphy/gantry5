<?php
/**
 * Responsive-image manifest schema shared by build cache and runtime readers.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render\Assets;
final class ImageEntry
{
    public static function valid(mixed $entry): bool
    {
        if (!is_array($entry) || !self::dimension($entry['width'] ?? null) || !self::dimension($entry['height'] ?? null) || $entry['width'] * $entry['height'] > 40000000 || !self::url($entry['fallback'] ?? null)) { return false; }
        foreach (['avif', 'webp'] as $format) {
            if (!isset($entry[$format]) || !is_array($entry[$format]) || !$entry[$format] || !array_is_list($entry[$format])) { return false; }
            $last = 0;
            foreach ($entry[$format] as $variant) {
                if (!is_array($variant) || !self::dimension($variant['width'] ?? null) || !self::dimension($variant['height'] ?? null) || $variant['width'] <= $last || $variant['width'] > $entry['width'] || $variant['height'] > $entry['height'] || !self::url($variant['url'] ?? null) || !is_int($variant['bytes'] ?? null) || $variant['bytes'] < 1) { return false; }
                if (abs($variant['height'] - $entry['height'] * $variant['width'] / $entry['width']) > 1) { return false; }
                $last = $variant['width'];
            }
        }
        return true;
    }
    private static function dimension(mixed $value): bool { return is_int($value) && $value > 0 && $value <= 40000000; }
    private static function url(mixed $value): bool { return is_string($value) && str_starts_with($value, '/') && !str_starts_with($value, '//') && !preg_match('/[\x00-\x20]/', $value); }
}
