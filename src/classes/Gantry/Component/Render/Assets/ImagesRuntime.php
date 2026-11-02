<?php
/**
 * Frozen responsive-image markup. This processes rendered HTML, never sources.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

final class ImagesRuntime
{
    /** Unknown/external/SVG/authored-picture markup is returned byte-for-byte. */
    public static function html(string $html, array $manifest): string
    {
        $images = $manifest['images'] ?? [];
        if (!$images || !str_contains(strtolower($html), '<img')) { return $html; }
        $main = $manifest['main_image'] ?? '';
        $mainEntry = is_string($main) ? ($images[$main] ?? null) : null;
        if ($mainEntry !== null && !ImageEntry::valid($mainEntry)) { throw new \RuntimeException('The compiled primary-image manifest is invalid.'); }
        $primaryUsed = false;
        $pattern = '#<!--[\s\S]*?-->|<(script|style|pre|code|textarea|noscript|picture|template)\b(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>[\s\S]*?</\1\s*>|<(?:[^>"\']+|"[^"]*"|\'[^\']*\')+>#i';
        return preg_replace_callback($pattern, static function (array $match) use ($images, $mainEntry, &$primaryUsed): string {
            $tag = $match[0];
            if (!preg_match('/^<img\b/i', $tag)) { return $tag; }
            $doc = \Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $tag . '</body></html>', LIBXML_NOERROR);
            $image = $doc->getElementsByTagName('img')->item(0);
            if (!$image || $image->hasAttribute('srcset') || $image->hasAttribute('data-gantry-image')) { return $tag; }
            $source = $image->getAttribute('src') ?? '';
            $entry = $images[$source] ?? null;
            if (!$entry) { return $tag; }
            if (!ImageEntry::valid($entry)) { throw new \RuntimeException('The compiled responsive-image manifest is invalid.'); }
            $width = $image->getAttribute('width') ?? ''; $height = $image->getAttribute('height') ?? '';
            if ($width === '') { $image->setAttribute('width', ctype_digit($height) && (int) $height > 0 ? (string) max(1, (int) round((int) $height * $entry['width'] / $entry['height'])) : (string) $entry['width']); }
            if ($height === '') { $image->setAttribute('height', ctype_digit($width) && (int) $width > 0 ? (string) max(1, (int) round((int) $width * $entry['height'] / $entry['width'])) : (string) $entry['height']); }
            $primary = !$primaryUsed && ($mainEntry === null || $mainEntry['fallback'] === $entry['fallback']);
            if ($primary) {
                $primaryUsed = true;
                $image->setAttribute('loading', 'eager');
                $image->setAttribute('fetchpriority', 'high');
            } elseif (!$image->hasAttribute('loading')) { $image->setAttribute('loading', 'lazy'); }
            if (!$image->hasAttribute('decoding')) { $image->setAttribute('decoding', 'async'); }
            $image->setAttribute('src', $entry['fallback']);
            $image->setAttribute('data-gantry-image', 'compiled');
            $sources = '';
            foreach (['avif', 'webp'] as $format) {
                $set = implode(', ', array_map(static fn (array $variant): string => $variant['url'] . ' ' . $variant['width'] . 'w', $entry[$format] ?? []));
                if ($set !== '') { $sources .= '<source type="image/' . $format . '" srcset="' . self::escape($set) . '" sizes="' . self::escape($image->getAttribute('sizes') ?: '100vw') . '">'; }
            }
            return '<picture>' . $sources . $doc->saveHtml($image) . '</picture>';
        }, $html);
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
