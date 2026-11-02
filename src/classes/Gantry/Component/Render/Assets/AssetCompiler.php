<?php
/**
 * Offline, content-addressed assets for immutable Gantry render bundles.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Gantry\Framework\Gantry;
use MatthiasMullie\Minify;

/** Nothing in this class runs from a compiled frontend request. */
final class AssetCompiler
{
    private string $siteRoot;
    private array $prepared = [];
    private array $building = [];
    private readonly PreparedCache $cache;
    private readonly PublicSources $sources;
    private array $statistics = ['minifications' => 0, 'image_encodes' => 0, 'cache_hits' => 0];

    public function statistics(): array { return $this->statistics; }

    public function __construct(private readonly Gantry $gantry, private readonly string $publicDirectory, private readonly string $publicUrl)
    {
        $this->siteRoot = realpath(defined('JPATH_ROOT') ? JPATH_ROOT : GANTRY5_ROOT) ?: '';
        if (!$this->siteRoot || !str_starts_with($publicDirectory, $this->siteRoot . '/') || !str_starts_with($publicUrl, '/') || str_contains($publicUrl, '..')) {
            throw new \RuntimeException('A local public render-asset directory and URL are required.');
        }
        $cursor = '';
        foreach (explode('/', ltrim($publicDirectory, '/')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new \RuntimeException('Linked render-asset directories are unsupported.'); }
        }
        if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) {
            throw new \RuntimeException('The render-asset directory is unavailable.');
        }
        $this->cache = new PreparedCache($publicDirectory, $publicUrl);
        $relativeOutput = substr($publicDirectory, strlen($this->siteRoot));
        if (!str_ends_with(rtrim($publicUrl, '/'), $relativeOutput)) { throw new \RuntimeException('The immutable asset URL must map to its public CMS-relative directory.'); }
        $sitePrefix = substr(rtrim($publicUrl, '/'), 0, -strlen($relativeOutput));
        $this->sources = new PublicSources($gantry, $this->siteRoot, $sitePrefix);
    }

    /**
     * Each outline is config/layout/css. Output is stored privately by the registry.
     * files maps original URI/public URL aliases to immutable files. pairs contains
     * bounded precompiled adjacent pairs; runtime never creates new combinations.
     */
    public function prepare(array $outlines, array $contentSources = []): array
    {
        if (!class_exists(Minify\CSS::class) || !class_exists(Minify\JS::class)) {
            throw new \RuntimeException('The maintained PHP asset minifiers are unavailable.');
        }
        $output = [];
        $images = new ImagesCompiler($this->gantry, $this->siteRoot, fn (string $bytes, string $extension): array => $this->publish($bytes, $extension), $this->cache, $this->sources);
        foreach ($outlines as $id => $outline) {
            $config = (array) ($outline['config'] ?? []);
            $page = (array) ($config['page']['assets'] ?? []);
            $options = ['version' => (bool) ($page['version'] ?? true), 'minify' => (bool) ($page['minify'] ?? true), 'combine' => (bool) ($page['combine'] ?? true)];
            $sources = ['gantry-engine://css-compiled/nucleus.css'];
            foreach ((array) ($outline['css'] ?? []) as $uri) { if (is_string($uri)) { $sources[] = $uri; } }
            $fa = (array) ($config['page']['fontawesome'] ?? []);
            if (($fa['enable'] ?? true) && in_array($fa['version'] ?? $fa['default_version'] ?? 'fa5css', ['fa5css', 'fa4', 'fa7css'], true)) {
                $sources[] = 'gantry-assets://css/' . match ($fa['version'] ?? $fa['default_version'] ?? 'fa5css') { 'fa4' => 'font-awesome.min.css', 'fa7css' => 'font-awesome7-all.min.css', default => 'font-awesome6-all.min.css' };
            }
            foreach (['css', 'javascript'] as $kind) {
                foreach ((array) ($page[$kind] ?? []) as $asset) {
                    if (is_array($asset) && is_string($asset['location'] ?? null)) { $sources[] = $asset['location']; }
                }
            }
            $strings = $contentSources;
            $this->strings($config, $strings);
            $this->strings((array) ($outline['layout'] ?? []), $strings);
            $particles = [];
            $this->particleSources((array) ($outline['layout'] ?? []), $sources, $strings, $particles);
            // Common frontend templates declare core scripts, icons and platform CSS.
            foreach (['gantry-engine://templates/page.html.twig', 'gantry-engine://templates/page_head.html.twig', 'gantry-engine://templates/page_body.html.twig', 'gantry-engine://twig/partials/page_head.html.twig'] as $template) {
                $file = $this->gantry['locator']->findResource($template, true);
                if (is_string($file) && is_file($file)) { $this->references(file_get_contents($file), $sources); }
            }
            foreach ($strings as $string) { if (is_string($string)) { $this->references($string, $sources); } }
            $sources = array_values(array_unique($sources));
            $files = [];
            $cssOutput = [];
            $ordered = ['style' => [], 'script' => []];
            foreach ($sources as $source) {
                $extension = strtolower(pathinfo(parse_url($source, PHP_URL_PATH) ?: $source, PATHINFO_EXTENSION));
                $kind = match ($extension) { 'css' => 'style', 'js' => 'script', default => null };
                if ($kind === null) { continue; }
                $asset = $this->file($source, $kind, $options['minify']);
                if (!$asset) { continue; }
                foreach ($asset['aliases'] as $alias) { $files[$alias] = array_diff_key($asset, ['aliases' => true, 'path' => true]); }
                $ordered[$kind][$asset['id']] = $asset;
            }
            // Native compiled SCSS is immutable even when optional asset versioning
            // is disabled: previous render generations must retain their own styles.
            foreach ((array) ($outline['css'] ?? []) as $name => $uri) {
                $asset = $this->file($uri, 'style', $options['minify']);
                if (!$asset) { throw new \RuntimeException('A required native compiled stylesheet is unavailable.'); }
                $cssOutput[(string) $name] = $asset['url'];
                foreach ($asset['aliases'] as $alias) { $files[$alias] = array_diff_key($asset, ['aliases' => true, 'path' => true]); }
            }
            $pairs = [];
            if ($options['combine']) {
                foreach ($ordered as $kind => $assets) {
                    $assets = array_values($assets);
                    for ($i = 0; $i + 1 < count($assets); ++$i) {
                        [$a, $b] = [$assets[$i], $assets[$i + 1]];
                        if (!$a['combine'] || !$b['combine']) { continue; }
                        $content = file_get_contents($a['path']) . ($kind === 'script' ? ";\n" : "\n") . file_get_contents($b['path']);
                        $pair = $this->publish($content, $kind === 'script' ? 'js' : 'css');
                        $pairKey = self::pairKey($kind, $a['id'], $b['id']);
                        $pairs[$pairKey] = ['url' => $pair['url'] . '?g6=' . substr($pairKey, 0, 16), 'bytes' => $pair['bytes'], 'id' => $pairKey];
                    }
                }
            }
            $imageMap = $images->prepare(array_merge($sources, $strings));
            $output[(string) $id] = ['schema' => 1, 'options' => $options, 'files' => $files, 'css' => $cssOutput, 'pairs' => $pairs, 'particles' => $particles,
                'critical' => $this->critical(array_values($ordered['style'])), 'images' => $imageMap,
                'main_image' => is_string($page['main_image'] ?? null) ? $page['main_image'] : ''];
        }
        $this->statistics['image_encodes'] = $images->encodes();
        return $output;
    }

    public static function pairKey(string $kind, string $first, string $second): string
    {
        return AssetRuntime::pairKey($kind, $first, $second);
    }

    private function file(string $uri, string $kind, bool $minify): ?array
    {
        $resolved = $this->sources->resolve($uri, $kind);
        if (!$resolved) { return null; }
        [$path, $url] = $resolved;
        $key = $path . ':' . (int) $minify;
        if (isset($this->prepared[$key])) {
            $asset = $this->prepared[$key];
            $asset['aliases'] = array_unique(array_merge($asset['aliases'], [$uri, $url]));
            return $asset;
        }
        if (filesize($path) > 8388608) { return null; }
        $content = file_get_contents($path);
        if (!is_string($content)) { throw new \RuntimeException('A declared render asset is unreadable.'); }
        if ($kind === 'script' && preg_match('/\b(?:import\s*(?:[({*]|[\w$]+\s+from)|export\s|document\s*\.\s*(?:currentScript|write)\b|import\.meta)/', $content)) {
            return null; // Relative module/self-location semantics must remain native.
        }
        if (isset($this->building[$key])) { throw new \RuntimeException('Circular local CSS imports cannot be frozen.'); }
        $this->building[$key] = true;
        $fingerprint = hash('sha256', 'asset-v1:' . hash_file('sha256', __FILE__) . ':' . $path . ':' . $this->publicUrl . ':' . $key . ':' . $content
            . ':' . ($kind === 'style' ? $this->dependencyDigest($content, $path) : '')
            . ':' . \Composer\InstalledVersions::getPrettyVersion('matthiasmullie/minify'));
        if (($cached = $this->cache->get($fingerprint))) {
            ++$this->statistics['cache_hits'];
            unset($this->building[$key]);
            $cached['aliases'] = array_values(array_unique(array_merge($cached['aliases'], [$uri, $url])));
            return $this->prepared[$key] = $cached;
        }
        $combine = $kind === 'style' ? !preg_match('/@(?:import|charset|namespace)\b/i', $content) : false;
        // Only known self-contained classic IIFEs are eligible, not arbitrary globals.
        if ($kind === 'script') {
            $withoutComments = preg_replace('/\A\s*(?:(?:\/\*[\s\S]*?\*\/)|(?:\/\/[^\r\n]*(?:\r?\n|$)))\s*/', '', $content);
            $combine = str_contains($content, '@gantry-combine-safe') && (bool) preg_match('/\A\s*(?:!function\b|\(function\b|!\(function\b)/', $withoutComments);
        }
        preg_match_all('/\/\*[\s\S]*?\*\/|^\s*\/\/[^\r\n]*/m', $content, $comments);
        $licenses = array_values(array_filter($comments[0], static fn (string $comment): bool => (bool) preg_match('/\A\/\*!|@license|@preserve|copyright|SPDX/i', $comment)));
        if ($kind === 'style') {
            $content = preg_replace('/(@import\s+)("[^"\\\\]*"|\'[^\'\\\\]*\')/i', '$1url($2)', $content);
            try { $content = $this->cssUrls($content, $path, $minify); }
            catch (UnrelocatableAsset) { unset($this->building[$key]); return null; }
        }
        if ($minify) { ++$this->statistics['minifications']; $content = ($kind === 'style' ? new LiteralCssMinifier($content) : new LiteralJsMinifier($content))->minify(); }
        foreach ($licenses as $license) { if (!str_contains($content, $license)) { $content = $license . "\n" . $content; } }
        $published = $this->publish($content, $kind === 'style' ? 'css' : 'js');
        unset($this->building[$key]);
        // Equal bytes under distinct original URLs can intentionally execute/apply
        // twice. Preserve that identity despite sharing one physical hash file.
        $identity = hash('sha256', $published['id'] . "\0" . $url);
        $published['content_hash'] = $published['id'];
        $published['id'] = $identity;
        $published['url'] .= '?g6=' . substr($identity, 0, 16);
        $asset = $published + ['kind' => $kind, 'source_url' => $url, 'combine' => $combine, 'aliases' => array_values(array_unique([$uri, $url, $published['url']]))];
        $this->cache->set($fingerprint, $asset);
        return $this->prepared[$key] = $asset;
    }

    /** Rebase local dependencies once during build; data/external/escaped URLs stay exact. */
    private function cssUrls(string $css, string $source, bool $minify): string
    {
        if (preg_match('/url\([^)]*\\\\/', $css)) { throw new UnrelocatableAsset('Escaped stylesheet URLs retain their native location.'); }
        return preg_replace_callback('/url\(\s*(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([^\s)"\']+))\s*\)/i', function (array $match) use ($source, $minify): string {
            $uri = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            if ($uri === '' || str_starts_with($uri, '#') || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $uri)) { return $match[0]; }
            $parts = parse_url($uri);
            $path = is_array($parts) ? rawurldecode((string) ($parts['path'] ?? '')) : '';
            $dependency = $this->sources->dependency($uri, $source);
            $resolved = $dependency[0] ?? null;
            if (!$resolved || !$this->sources->allowed($resolved, 'dependency') || filesize($resolved) > 33554432) { throw new UnrelocatableAsset('A stylesheet dependency is not an admitted public source.'); }
            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            if (!in_array($extension, ['css', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'otf'], true)) { throw new UnrelocatableAsset('Unsupported stylesheet dependencies retain their native location.'); }
            $file = $extension === 'css'
                ? $this->file($dependency[1], 'style', $minify)
                : $this->publish(file_get_contents($resolved), $extension);
            if (!$file) { throw new UnrelocatableAsset('A stylesheet import cannot safely move.'); }
            return 'url("' . $file['url'] . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '') . '")';
        }, $css);
    }

    private function publish(string $bytes, string $extension): array
    {
        $id = hash('sha256', $bytes);
        $name = "$id.$extension";
        $path = $this->publicDirectory . '/' . $name;
        if (is_link($path)) { throw new \RuntimeException('Linked immutable render assets are unsupported.'); }
        if (is_file($path)) {
            if (!hash_equals($id, hash_file('sha256', $path))) { throw new \RuntimeException('An immutable render asset is corrupt.'); }
        } else {
            $temporary = tempnam($this->publicDirectory, '.prepare-');
            try {
                if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0644) || !rename($temporary, $path)) {
                    throw new \RuntimeException('Could not prepare an immutable render asset.');
                }
            } finally { if (is_string($temporary) && is_file($temporary)) { unlink($temporary); } }
        }
        return ['id' => $id, 'url' => rtrim($this->publicUrl, '/') . '/' . $name, 'bytes' => strlen($bytes), 'path' => $path];
    }

    private function strings(array $data, array &$strings): void
    {
        foreach ($data as $value) {
            if (is_string($value)) { $strings[] = $value; }
            elseif (is_array($value) || is_object($value)) { $this->strings((array) $value, $strings); }
        }
    }

    private function references(string $string, array &$sources): void
    {
        if (preg_match_all('#(?:gantry-[a-z-]+://)[^\s"\'<>}{)]+#i', $string, $matches)) {
            foreach ($matches[0] as $match) { $sources[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5); }
        }
    }

    private function particleSources(array $nodes, array &$sources, array &$strings, array &$particles): void
    {
        foreach ($nodes as $node) {
            $node = (array) $node;
            if (isset($node['children'])) { $this->particleSources((array) $node['children'], $sources, $strings, $particles); }
            if (!in_array($node['type'] ?? '', ['particle', 'atom'], true) || !is_string($node['subtype'] ?? null)) { continue; }
            $subtype = $node['subtype'];
            $file = $this->gantry['locator']->findResource("gantry-particles://$subtype.html.twig", true);
            $assets = [];
            if (is_string($file) && is_file($file)) { $source = file_get_contents($file); $this->references($source, $assets); }
            $sources = array_merge($sources, $assets);
            $particles[(string) ($node['id'] ?? $subtype)] = ['type' => $subtype, 'assets' => array_values(array_unique($assets))];
        }
    }

    /** Copy only complete top-level invariant layout rules; never lift media rules. */
    private function critical(array $assets): string
    {
        $selectors = ['html', 'body', '*,*::before,*::after', '#g-page-surround', '.g-container', '.g-grid', '.g-block', 'img', 'iframe,svg'];
        $result = '';
        foreach ($assets as $asset) {
            if ($asset['kind'] !== 'style') { continue; }
            $css = file_get_contents($asset['path']);
            $start = 0; $depth = 0; $quote = null; $comment = false; $header = ''; $open = 0;
            for ($i = 0, $length = strlen($css); $i < $length; ++$i) {
                $char = $css[$i];
                if ($comment) { if ($char === '*' && ($css[$i + 1] ?? '') === '/') { $comment = false; ++$i; if ($depth === 0) { $start = $i + 1; } } continue; }
                if ($quote !== null) { if ($char === '\\') { ++$i; } elseif ($char === $quote) { $quote = null; } continue; }
                if ($char === '/' && ($css[$i + 1] ?? '') === '*') { $comment = true; ++$i; continue; }
                if ($char === '"' || $char === "'") { $quote = $char; continue; }
                if ($char === '{') { if ($depth++ === 0) { $header = trim(substr($css, $start, $i - $start)); $open = $i; } }
                elseif ($char === '}' && --$depth === 0) {
                    $rule = $header . substr($css, $open, $i - $open + 1);
                    if (in_array(str_replace(' ', '', $header), $selectors, true) && strlen($result . $rule) <= 8192) { $result .= $rule; }
                    $start = $i + 1;
                }
            }
        }
        return str_ireplace('</style', '\\3c /style', $result);
    }

    private function dependencyDigest(string $css, string $source, array $seen = []): string
    {
        if (isset($seen[$source])) { return ''; }
        $seen[$source] = true;
        $items = [];
        preg_match_all('/(?:url\(\s*|@import\s+)(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([^\s)"\';]+))/', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $uri = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $uri)) { continue; }
            $dependency = $this->sources->dependency($uri, $source);
            $path = $dependency[0] ?? null;
            if (!$path || !$this->sources->allowed($path, 'dependency')) { continue; }
            $items[$path] = hash_file('sha256', $path);
            if (str_ends_with(strtolower($path), '.css')) { $items[$path] .= $this->dependencyDigest(file_get_contents($path), $path, $seen); }
        }
        ksort($items);
        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }
}
