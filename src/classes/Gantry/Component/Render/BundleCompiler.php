<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render;

use Gantry\Component\Changes\ThemeState;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout;
use Gantry\Component\Render\Assets\AssetCompiler;
use Gantry\Framework\Gantry;
use Gantry\Framework\Services\ConfigServiceProvider;

/** Offline source reader. Called only inside the shared theme lock, never by a page request. */
final class BundleCompiler
{
    public function __construct(private readonly Gantry $gantry, private readonly string $root) {}

    public function prepare(string $revision, array $contentSources): string
    {
        if (!BundleRuntime::isSource() || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
            throw new \RuntimeException('Render preparation requires an explicit source build and revision.', 409);
        }
        RenderRegistry::safePath($this->root);
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) { throw new \RuntimeException('Production artifact directory is unavailable.', 500); }
        $id = bin2hex(random_bytes(32));
        $staging = $this->root . '/prepare-' . $id;
        if (!mkdir($staging, 0700)) { throw new \RuntimeException('Production generation could not be prepared.', 500); }
        $saved = [];
        foreach (['configuration', 'config', 'blueprints', 'outlines'] as $key) {
            $saved[$key] = isset($this->gantry[$key]) ? $this->gantry->raw($key) : null;
        }
        $yamlCaching = CompiledYamlFile::$defaultCaching;
        CompiledYamlFile::$defaultCaching = false;
        $theme = $this->gantry['theme'];
        $locator = $this->gantry['locator'];
        try {
            Layout::resetCache();
            $theme->resetRenderState();
            $outlines = $this->gantry['outlines']->load();
            unset($this->gantry['outlines'], $this->gantry['blueprints']);
            $this->gantry['outlines'] = $outlines;
            $this->gantry['blueprints'] = static fn (Gantry $gantry) => ConfigServiceProvider::blueprints($gantry);
            $ids = array_map('strval', array_keys($outlines->toArray()));
            foreach (['default', '_body_only', '_error', '_offline'] as $system) {
                if (!in_array($system, $ids, true) && $locator->findResource("gantry-layouts://{$system}.yaml")) { $ids[] = $system; }
            }
            $nativeTarget = $locator->findResource($theme->compiler()->getTarget(), true, true);
            $configRoot = $locator->findResource('gantry-config://', true, true);
            if (!is_string($configRoot)) { throw new \RuntimeException('Writable configuration is unavailable.', 500); }
            $publicDirectory = dirname(rtrim($configRoot, '/')) . '/compiled-assets';
            RenderRegistry::safePath($publicDirectory);
            if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) { throw new \RuntimeException('Compiled public asset directory is unavailable.', 500); }
            $siteRoot = rtrim((string) realpath(GANTRY5_ROOT), '/');
            if (!str_starts_with($publicDirectory, $siteRoot . '/')) { throw new \RuntimeException('The compiled public asset path is outside the native public root.', 422); }
            // Native Document::url accepts a web/stream path, not an absolute
            // filesystem path. Preserve its native subdirectory prefix too.
            $publicUrl = $this->gantry['document']::url(substr($publicDirectory, strlen($siteRoot) + 1), false, false, false);
            $sources = $this->sourceFiles(array_filter([$nativeTarget, $publicDirectory]));
            $before = $this->fingerprint($sources);
            $manifest = ['schema' => 1, 'id' => $id, 'revision' => $revision,
                'theme' => (string) $this->gantry['theme.name'], 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'framework' => defined('GANTRY5_VERSION') ? GANTRY5_VERSION : '6.0.2-rc.1',
                'created' => gmdate('Y-m-d\TH:i:s\Z'), 'particle_configs' => true, 'data' => [], 'outlines' => []];

            $details = [];
            $detail = $theme->details();
            do {
                if (isset($details[$detail->name])) { throw new \RuntimeException('Theme inheritance contains a cycle.', 422); }
                $details[$detail->name] = $detail->toArray();
            } while (($detail = $detail->parent()));
            $manifest['data']['details'] = $this->write($staging, 'data/details.php', $details);
            $presets = $theme->presets()->toArray();
            $manifest['preset_names'] = [];
            foreach ($presets as $name => $preset) {
                // Match native isset(), including null-valued and numeric keys.
                if ($preset !== null) { $manifest['preset_names'][$name] = true; }
            }
            $manifest['data']['presets'] = $this->write($staging, 'data/presets.php', $presets);
            $manifest['data']['blueprints'] = $this->write($staging, 'data/blueprints.php', $this->gantry['blueprints']->getState());
            $assignments = (new \Gantry\Framework\Assignments())->loadSavedAssignments();
            $manifest['data']['assignments'] = $this->write($staging, 'data/assignments.php', $assignments);

            $inputs = [];
            $inline = [];
            foreach ($ids as $outline) {
                $key = hash('sha256', $outline);
                $entry = [];
                foreach (['config' => [true, true], 'config_raw' => [true, false], 'config_single' => [false, true], 'config_single_raw' => [false, false]] as $kind => [$combine, $defaults]) {
                    $config = ConfigServiceProvider::load($this->gantry, $outline, $combine, $defaults);
                    $entry[$kind] = $this->write($staging, $kind . '/' . $key . '.php', $config->toArray());
                    if ($kind === 'config') { $effective = $config; }
                }
                unset($this->gantry['configuration'], $this->gantry['config']);
                $this->gantry['configuration'] = $outline;
                $this->gantry['config'] = $effective;
                $theme->resetRenderState();
                $layout = Layout::instance($outline)->init();
                $nodes = $this->arrays($layout->toArray());
                $entry['particles'] = $this->write($staging, 'particles/' . $key . '.php', $this->particleConfigs($layout->toArray(), $effective));
                $entry['layout'] = $this->write($staging, 'layout/' . $key . '.php', ['nodes' => $layout->toArray(), 'preset' => $layout->preset, 'timestamp' => $layout->timestamp]);
                $entry['index'] = $this->write($staging, 'index/' . $key . '.php', $layout->buildIndex());
                $css = [];
                foreach ((array) $theme->details()->get('configuration.css.files', []) as $name) {
                    $name = (string) $name;
                    $compiler = $theme->compiler();
                    $compiler->setVariables($theme->getCssVariables());
                    $compiler->compileFile($name);
                    $css[$name] = $compiler->getCssUrl($name);
                    if ($compiler->getWarnings()) { throw new \RuntimeException('Production CSS could not be compiled without warnings.', 422); }
                }
                $inputs[$outline] = ['config' => $effective->toArray(), 'layout' => $nodes, 'css' => $css];
                $this->inlineSources($nodes, $effective, $inline);
                $manifest['outlines'][$outline] = $entry;
            }
            // Native compilation creates files that may previously have been
            // cached as absent by the stream locator.
            $locator->clearCache();
            $assetCompiler = new AssetCompiler($this->gantry, $publicDirectory, (string) $publicUrl);
            $assets = $assetCompiler->prepare($inputs, $contentSources);
            foreach ($ids as $outline) {
                if (!isset($assets[$outline]) || !is_array($assets[$outline])) { throw new \RuntimeException('An outline asset manifest is missing.', 500); }
                $manifest['outlines'][$outline]['assets'] = $this->write($staging, 'assets/' . hash('sha256', $outline) . '.php', $assets[$outline]);
            }
            $twig = $theme->compileTwigBundle($id, $staging, array_values($inline));
            $manifest['data']['twig'] = $this->write($staging, 'data/twig.php', $twig);

            $yaml = [];
            foreach ($sources as $path => $aliases) {
                if (!str_ends_with($path, '.yaml') && !str_ends_with($path, '.yml')) { continue; }
                $file = CompiledYamlFile::instance($path);
                try { $value = (array) $file->content(); } finally { $file->free(); }
                $record = $this->write($staging, 'yaml/' . hash('sha256', $path) . '.php', $value);
                $yaml[$path] = $record;
                foreach ($aliases as $alias) {
                    // Directory sorting must never change native override precedence.
                    $resolved = $locator->findResource($alias);
                    if (is_string($resolved) && realpath($resolved) === $path) { $yaml[$alias] = $record; }
                }
            }
            $manifest['data']['yaml'] = $this->write($staging, 'data/yaml.php', $yaml);
            if (!hash_equals($before, $this->fingerprint($this->sourceFiles(array_filter([$nativeTarget, $publicDirectory]))))) {
                throw new \RuntimeException('Theme source changed during production preparation. Retry from the current revision.', 409);
            }
            $manifest['source_digest'] = $before;
            $this->write($staging, 'bundle.php', $manifest);
            if (!rename($staging, $this->root . '/' . $id)) { throw new \RuntimeException('The complete production generation could not be staged.', 500); }
            return $id;
        } finally {
            CompiledYamlFile::$defaultCaching = $yamlCaching;
            Layout::resetCache();
            $theme->resetRenderState();
            foreach ($saved as $key => $value) {
                unset($this->gantry[$key]);
                if ($value !== null) { $this->gantry[$key] = $value; }
            }
            if (is_dir($staging)) { $this->remove($staging); }
        }
    }

    private function write(string $directory, string $file, array $data): array
    {
        $path = $directory . '/' . $file;
        RenderRegistry::safePath($path);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) { throw new \RuntimeException('Production artifact directory is unavailable.', 500); }
        $bytes = "<?php\nif (!defined('GANTRY5_ROOT')) { http_response_code(404); exit; }\nreturn " . var_export($this->phpData($data), true) . ";\n";
        ThemeState::atomic($path, $bytes);
        return ['file' => $file, 'sha256' => hash('sha256', $bytes)];
    }

    private function arrays(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR);
    }

    /** Resolve native merge rules offline, including settings-derived atoms. */
    private function particleConfigs(array $nodes, \Gantry\Component\Config\Config $config): array
    {
        $result = [];
        $visit = static function (array $items) use (&$visit, &$result, $config): void {
            foreach ($items as $item) {
                if (!is_array($item) && !$item instanceof \stdClass) { continue; }
                $item = (array) $item;
                if (isset($item['children']) && is_array($item['children'])) { $visit($item['children']); }
                if (in_array($item['type'] ?? null, ['atom', 'particle', 'position', 'spacer'], true) && is_string($item['subtype'] ?? null)) {
                    $subtype = $item['subtype'];
                    $attributes = (array) ($item['attributes'] ?? []);
                    $defaults = $config->get('particles.' . $subtype);
                    try { $key = BundleRuntime::particleKey($subtype, $defaults, $attributes); }
                    catch (\UnexpectedValueException | \LengthException $error) { continue; }
                    $merged = $config->getJoined('particles.' . $subtype, $attributes);
                    // Never snapshot object identity or mutable nested objects.
                    try { BundleRuntime::particleKey($subtype, $merged, []); }
                    catch (\UnexpectedValueException | \LengthException $error) { continue; }
                    $result[$key] = $merged;
                }
            }
        };
        $visit($nodes);
        $atoms = [];
        foreach ((array) $config->get('page.head.atoms') as $data) {
            $atoms[] = ['type' => 'atom', 'subtype' => $data['type']] + $data;
        }
        $assets = (array) $config->get('page.assets');
        if ($assets) { $atoms[] = ['type' => 'atom', 'subtype' => 'assets', 'attributes' => $assets + ['enabled' => 1]]; }
        $visit($atoms);
        return $result;
    }

    private function phpData(mixed $value): mixed
    {
        if (is_array($value) || $value instanceof \stdClass) {
            $result = [];
            foreach ((array) $value as $key => $item) { $result[$key] = $this->phpData($item); }
            return $value instanceof \stdClass ? (object) $result : $result;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) { return $value; }
        throw new \RuntimeException('A production artifact contains unsupported data.', 422);
    }

    private function inlineSources(array $nodes, \Gantry\Component\Config\Config $config, array &$strings): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) { continue; }
            if (($node['type'] ?? '') === 'particle' && ($node['subtype'] ?? '') === 'custom') {
                $particle = $config->getJoined('particles.custom', (array) ($node['attributes'] ?? []));
                if (!empty($particle['twig']) && is_string($particle['html'] ?? null)) { $strings[hash('sha256', $particle['html'])] = $particle['html']; }
            }
            if (is_array($node['children'] ?? null)) { $this->inlineSources($node['children'], $config, $strings); }
        }
    }

    /** Build-time discovery only. Resolve stream aliases once; requests never enumerate these roots. */
    private function sourceFiles(array $excluded): array
    {
        $files = [];
        foreach (['gantry-theme', 'gantry-engine', 'gantry-assets', 'gantry-config', 'gantry-particles', 'gantry-blueprints'] as $stream) {
            foreach ($this->gantry['locator']->findResources($stream . '://') as $root) {
                $root = realpath($root);
                if (!$root || !is_dir($root)) { continue; }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->isLink()) { continue; }
                    $path = $file->getPathname();
                    $relative = substr($path, strlen($root) + 1);
                    if (preg_match('~(?:^|/)(?:\.[^/]+|node_modules|vendor|compiled-assets)(?:/|$)~', $relative)) { continue; }
                    foreach ($excluded as $exclude) { if ($path === $exclude || str_starts_with($path, rtrim((string) $exclude, '/') . '/')) { continue 2; } }
                    if (!preg_match('/\.(?:ya?ml|twig|scss|css|js|json|png|jpe?g|gif|webp|avif|svg|woff2?|ttf)$/i', $path)) { continue; }
                    if (count($files) > 20000) { throw new \RuntimeException('Theme source exceeds the production build limit.', 422); }
                    $files[$path][] = $stream . '://' . $relative;
                }
            }
        }
        ksort($files);
        return $files;
    }

    private function fingerprint(array $files): string
    {
        $context = hash_init('sha256');
        foreach ($files as $path => $aliases) {
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) { throw new \RuntimeException('Production source could not be read.', 500); }
            hash_update($context, $path . "\0" . $hash . "\0");
        }
        return hash_final($context);
    }

    private function remove(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && !$file->isLink()) { $this->remove($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($directory);
    }
}
