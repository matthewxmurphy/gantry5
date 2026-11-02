<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/** A closed logical-name map: no filesystem loader or source-template access. */
final class CompiledLoader implements LoaderInterface
{
    private int $sourceAttempts = 0;

    public function __construct(private readonly array $templates, private readonly string $generation)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $generation)) { CompiledEnvironment::unavailable('Invalid compiled Twig generation.'); }
        foreach ($templates as $name => $entry) {
            if (!is_string($name) || self::normalize($name) !== $name || !is_array($entry)
                || !is_string($entry['key'] ?? null) || !str_starts_with($entry['key'], 'gantry:' . $generation . ':')
                || !is_string($entry['class'] ?? null) || !preg_match('/\A__TwigTemplate_[a-f0-9]{32}\z/D', $entry['class'])) {
                CompiledEnvironment::unavailable('Invalid compiled Twig template map.');
            }
        }
    }

    public static function normalize(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) { throw new LoaderError('Invalid compiled template name.'); }
        $name = preg_replace('#/{2,}#', '/', str_replace('\\', '/', $name));
        $prefix = '';
        if ($name[0] === '@') {
            $slash = strpos($name, '/');
            if ($slash === false || $slash === 1) { throw new LoaderError('Invalid compiled template namespace.'); }
            $prefix = substr($name, 0, $slash + 1);
            $name = substr($name, $slash + 1);
        }
        $parts = [];
        foreach (explode('/', ltrim($name, '/')) as $part) {
            if ($part === '.' || $part === '') { continue; }
            if ($part === '..') {
                if (!$parts) { throw new LoaderError('Compiled template traversal is refused.'); }
                array_pop($parts);
            } else { $parts[] = $part; }
        }
        if (!$parts) { throw new LoaderError('Invalid compiled template name.'); }
        return $prefix . implode('/', $parts);
    }

    public function entry(string $name): array
    {
        // The immutable map was checked for canonical names at construction.
        if (isset($this->templates[$name])) { return $this->templates[$name]; }
        $name = self::normalize($name);
        if (!isset($this->templates[$name])) { throw new LoaderError('Template is absent from the active render bundle: ' . $name); }
        return $this->templates[$name];
    }

    public function getCacheKey(string $name): string { return $this->entry($name)['key']; }
    public function getSourceContext(string $name): Source
    {
        ++$this->sourceAttempts;
        CompiledEnvironment::unavailable('Source templates cannot be read by the compiled renderer.');
    }
    public function exists(string $name): bool
    {
        if (isset($this->templates[$name])) { return true; }
        try { return isset($this->templates[self::normalize($name)]); }
        catch (LoaderError) { return false; }
    }
    public function isFresh(string $name, int $time): bool { return $this->exists($name); }
    public function sourceAttempts(): int { return $this->sourceAttempts; }
}
