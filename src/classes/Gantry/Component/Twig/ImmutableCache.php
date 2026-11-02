<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Twig\Cache\CacheInterface;

/** Loads only verified compiled PHP artifacts; a miss never becomes a compiler write. */
final class ImmutableCache implements CacheInterface
{
    private readonly string $directory;
    private array $paths = [];
    private array $verified = [];
    private int $loads = 0;

    public function __construct(string $directory, private readonly CompiledLoader $loader, array $templates)
    {
        $path = realpath($directory);
        if ($path === false || !is_dir($path) || is_link($path . '/twig')) { CompiledEnvironment::unavailable('Compiled Twig directory is unavailable.'); }
        $this->directory = rtrim($path, '/') . '/';
        foreach ($templates as $entry) {
            if (!is_string($entry['file'] ?? null) || !preg_match('/\Atwig\/[a-f0-9]{64}\.php\z/D', $entry['file'])
                || !is_string($entry['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])) {
                CompiledEnvironment::unavailable('Invalid compiled Twig artifact map.');
            }
            $full = $this->directory . $entry['file'];
            if (isset($this->paths[$full]) && $this->paths[$full] !== $entry['sha256']) { CompiledEnvironment::unavailable('Conflicting compiled Twig artifact.'); }
            $this->paths[$full] = $entry['sha256'];
        }
    }

    public function generateKey(string $name, string $className): string
    {
        $entry = $this->loader->entry($name);
        if ($entry['class'] !== $className) { CompiledEnvironment::unavailable('Compiled Twig environment differs from its bundle.'); }
        return $this->directory . $entry['file'];
    }

    public function verifyTemplate(string $name): void
    {
        $entry = $this->loader->entry($name);
        $this->verify($this->directory . $entry['file']);
    }

    private function verify(string $key): void
    {
        if (isset($this->verified[$key])) { return; }
        if (!isset($this->paths[$key]) || is_link($key) || !is_file($key) || !is_readable($key)
            || !hash_equals($this->paths[$key], (string) hash_file('sha256', $key))) {
            CompiledEnvironment::unavailable('Compiled Twig artifact is missing or corrupt. Rebuild the render bundle.');
        }
        $this->verified[$key] = true;
    }

    public function load(string $key): void
    {
        $this->verify($key);
        ++$this->loads;
        require_once $key;
    }
    public function write(string $key, string $content): void { CompiledEnvironment::unavailable('The compiled Twig cache is immutable.'); }
    public function getTimestamp(string $key): int { $this->verify($key); return 1; }
    public function loads(): int { return $this->loads; }
}
