<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Outline;

/** One safe native identity for outline folders and subsequent layout writes. */
final class OutlineName
{
    public static function slug(string $name, int $maximumBytes = 224): string
    {
        if (!preg_match('//u', $name)) {
            throw new \RuntimeException('Outline names must be valid UTF-8.', 400);
        }

        // Modern HTML identifiers and native resource paths support Unicode.
        // Keep letters/numbers/combining marks; exclude path separators, dots,
        // controls and whitespace without locale-dependent transliteration.
        $slug = mb_strtolower(preg_replace('/[^\p{L}\p{N}\p{M}_-]/u', '_', $name), 'UTF-8');
        // A generated base leaves room for native duplicate suffixes within a
        // 255-byte filesystem component. Existing allocated IDs use the full cap.
        $slug = mb_strcut($slug, 0, $maximumBytes, 'UTF-8');

        return preg_match('/[\p{L}\p{N}]/u', $slug) ? $slug : '';
    }
}
