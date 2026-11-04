<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Twig\Loader\FilesystemLoader;
use Twig\Source;

/** Offline-only native path resolution; the generation prevents loaded-class replay. */
final class GenerationFilesystemLoader extends FilesystemLoader
{
    private array $strings = [];

    public function __construct(private readonly string $generation)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $generation)) {
            throw new \InvalidArgumentException('Use a valid render generation.');
        }
        parent::__construct();
    }

    public function addString(string $name, string $source): void { $this->strings[$name] = $source; }
    public function stringNames(): array { return array_keys($this->strings); }

    public function getCacheKey(string $name): string
    {
        return 'gantry:' . $this->generation . ':' . (isset($this->strings[$name]) ? 'inline:' . $name : parent::getCacheKey($name));
    }

    public function getSourceContext(string $name): Source
    {
        return isset($this->strings[$name]) ? new Source($this->strings[$name], $name) : parent::getSourceContext($name);
    }

    public function exists(string $name): bool { return isset($this->strings[$name]) || parent::exists($name); }
    public function isFresh(string $name, int $time): bool { return isset($this->strings[$name]) || parent::isFresh($name, $time); }
}
