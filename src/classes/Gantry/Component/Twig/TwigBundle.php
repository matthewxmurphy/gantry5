<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Gantry\Component\Theme\AbstractTheme;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Offline compiler for the render registry's inactive immutable generation. */
final class TwigBundle
{
    public static function prepare(AbstractTheme $theme, string $generation, string $directory, array $strings = []): array
    {
        $environment = $theme->compilationEnvironment($generation);
        $loader = $environment->getLoader();
        if (!$loader instanceof GenerationFilesystemLoader) { CompiledEnvironment::unavailable('The native Twig compiler loader was replaced.'); }
        $inline = [];
        foreach ($strings as $source) {
            if (!is_string($source)) { throw new \InvalidArgumentException('Registered inline Twig must be text.'); }
            $hash = hash('sha256', $source);
            $name = '__g6_inline_' . $hash . '.twig';
            $inline[$hash] = $name;
            $loader->addString($name, $source);
        }
        $names = array_fill_keys($loader->stringNames(), true);
        foreach ($loader->getNamespaces() as $namespace) {
            foreach ($loader->getPaths($namespace) as $path) {
                $root = realpath($path);
                if ($root === false || !is_dir($root)) { CompiledEnvironment::unavailable('A native Twig source path is unavailable.'); }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!str_ends_with($file->getFilename(), '.twig')) { continue; }
                    if ($file->isLink() || !$file->isFile()) { CompiledEnvironment::unavailable('Linked Twig sources cannot enter a render bundle.'); }
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $name = $namespace === FilesystemLoader::MAIN_NAMESPACE ? $relative : '@' . $namespace . '/' . $relative;
                    $names[CompiledLoader::normalize($name)] = true;
                }
            }
        }
        ksort($names);
        $target = rtrim($directory, '/') . '/twig';
        if (is_link($target) || (!is_dir($target) && !mkdir($target, 0700, true))) { CompiledEnvironment::unavailable('Compiled Twig target is unavailable.'); }
        $templates = [];
        $compiled = [];
        foreach (array_keys($names) as $name) {
            $key = $loader->getCacheKey($name);
            $class = $environment->getTemplateClass($name);
            if (!isset($compiled[$class])) {
                $source = $loader->getSourceContext($name);
                $php = $environment->compileSource($source);
                if (!str_starts_with($php, '<?php')) { CompiledEnvironment::unavailable('Invalid compiled Twig output.'); }
                $guard = "<?php\nif (!class_exists(\\Gantry\\Component\\Twig\\CompiledEnvironment::class, false)) { http_response_code(404); return; }\n";
                $php = $guard . substr($php, 5);
                $relative = 'twig/' . hash('sha256', $class) . '.php';
                $filename = rtrim($directory, '/') . '/' . $relative;
                self::write($filename, $php);
                $compiled[$class] = ['class' => $class, 'file' => $relative, 'sha256' => hash('sha256', $php)];
            }
            $templates[$name] = ['key' => $key] + $compiled[$class];
        }
        return ['schema' => 1, 'generation' => $generation, 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'twig' => Environment::VERSION, 'options' => ['debug' => $environment->isDebug(),
                'strict_variables' => $environment->isStrictVariables(), 'use_yield' => $environment->useYield(),
                'charset' => $environment->getCharset(), 'autoescape' => 'html'],
            'templates' => $templates, 'strings' => $inline];
    }

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

    private static function write(string $filename, string $content): void
    {
        if (is_link($filename)) { CompiledEnvironment::unavailable('Linked compiled Twig artifacts are refused.'); }
        if (file_exists($filename)) {
            if (!hash_equals(hash('sha256', $content), hash_file('sha256', $filename))) { CompiledEnvironment::unavailable('A compiled Twig generation cannot be overwritten.'); }
            return;
        }
        $temporary = tempnam(dirname($filename), '.g6-twig-');
        try {
            if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
                || !chmod($temporary, 0600) || !rename($temporary, $filename)) { CompiledEnvironment::unavailable('Writing compiled Twig failed.'); }
        } finally { if (is_string($temporary) && is_file($temporary)) { unlink($temporary); } }
    }
}
