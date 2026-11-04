<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Gantry\Component\Render\BundleRuntime;
use Twig\Environment;
use Twig\Loader\LoaderInterface;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;
use Twig\TokenStream;

/** Native Twig execution with every source/compiler escape hatch closed. */
final class CompiledEnvironment extends Environment
{
    private bool $sealed = false;
    private int $compilerAttempts = 0;

    public function __construct(private readonly CompiledLoader $compiledLoader, private readonly ImmutableCache $compiledCache,
        private readonly array $strings, private readonly string $generation, array $options = [])
    {
        parent::__construct($compiledLoader, array_replace($options, ['cache' => $compiledCache, 'auto_reload' => false]));
        $this->sealed = true;
    }

    public function generation(): string { return $this->generation; }
    public static function unavailable(string $message): never
    {
        if (class_exists(BundleRuntime::class)) { BundleRuntime::fail($message); }
        throw new \RuntimeException($message, 503);
    }
    public function getTemplateClass(string $name, ?int $index = null): string
    {
        $class = parent::getTemplateClass($name, $index);
        $expected = $this->compiledLoader->entry($name)['class'] . ($index === null ? '' : '___' . $index);
        if (!hash_equals($expected, $class)) { CompiledEnvironment::unavailable('Compiled Twig environment differs from its bundle.'); }
        return $class;
    }
    public function loadTemplate(string $cls, string $name, ?int $index = null): Template
    {
        // Verify once per environment even when PHP already loaded this class
        // during an earlier environment in the same long-running request.
        $this->compiledCache->verifyTemplate($name);
        return parent::loadTemplate($cls, $name, $index);
    }
    public function createTemplate(string $template, ?string $name = null): TemplateWrapper
    {
        $logical = $this->strings[hash('sha256', $template)] ?? null;
        if (!is_string($logical)) { CompiledEnvironment::unavailable('Inline Twig is absent from the active render bundle. Save it before rendering.'); }
        return $this->load($logical);
    }
    private function refuseCompilation(): never
    {
        ++$this->compilerAttempts;
        CompiledEnvironment::unavailable('Runtime Twig interpretation is disabled. Rebuild the render bundle.');
    }
    public function tokenize(Source $source): TokenStream { $this->refuseCompilation(); }
    public function parse(TokenStream $stream): ModuleNode { $this->refuseCompilation(); }
    public function compile(Node $node): string { $this->refuseCompilation(); }
    public function compileSource(Source $source): string { $this->refuseCompilation(); }
    public function setLoader(LoaderInterface $loader): void
    {
        if ($this->sealed) { CompiledEnvironment::unavailable('The compiled Twig loader is immutable.'); }
        parent::setLoader($loader);
    }
    public function setCache($cache): void
    {
        if ($this->sealed) { CompiledEnvironment::unavailable('The compiled Twig cache is immutable.'); }
        parent::setCache($cache);
    }
    public function enableAutoReload(): void { CompiledEnvironment::unavailable('Compiled Twig cannot reload source templates.'); }
    public function statistics(): array
    {
        return ['source_attempts' => $this->compiledLoader->sourceAttempts(), 'compiler_attempts' => $this->compilerAttempts, 'artifact_loads' => $this->compiledCache->loads()];
    }
}
