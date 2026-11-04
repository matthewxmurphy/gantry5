<?php
/**
 * Runtime admission for an immutable native Twig map.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Twig\Environment;

/** Runtime admission has no offline source-discovery or compiler implementation. */
final class TwigManifest
{
    public static function validate(array $map): void
    {
        if (($map['schema'] ?? null) !== 1 || !is_string($map['generation'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $map['generation'])
            || ($map['php'] ?? null) !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            || ($map['twig'] ?? null) !== Environment::VERSION || !is_array($map['templates'] ?? null)
            || !is_array($map['strings'] ?? null) || !is_array($map['options'] ?? null)) {
            CompiledEnvironment::unavailable('The compiled Twig bundle is incompatible or incomplete.');
        }
        foreach (['debug', 'strict_variables', 'use_yield'] as $option) {
            if (!is_bool($map['options'][$option] ?? null)) { CompiledEnvironment::unavailable('Invalid compiled Twig options.'); }
        }
        if (($map['options']['charset'] ?? null) !== 'UTF-8' || ($map['options']['autoescape'] ?? null) !== 'html') { CompiledEnvironment::unavailable('Invalid compiled Twig charset or escaping.'); }
        foreach ($map['strings'] as $hash => $name) {
            if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash) || !is_string($name)
                || $name !== '__g6_inline_' . $hash . '.twig' || !isset($map['templates'][$name])) {
                CompiledEnvironment::unavailable('Invalid compiled inline Twig map.');
            }
        }
    }

}
