<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Changes;

use Gantry\Admin\Events\LayoutEvent;
use Gantry\Admin\Events\PageEvent;
use Gantry\Admin\Events\SettingsEvent;
use Gantry\Admin\Events\StylesEvent;
use Gantry\Component\Config\Config;
use Gantry\Component\Layout\Layout;
use Gantry\Framework\Atoms;
use Gantry\Framework\Gantry;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * Canonical native persistence used inside ChangeEngine transactions.
 * Transports authorize and validate their own capabilities first. Full native
 * admin values remain supported; REST applies its bounded blueprint contract
 * before reaching these same writers. This class never acquires another lock.
 */
final class NativeWriter
{
    public function __construct(private readonly Gantry $gantry) {}

    /** Pure native editor plan: no index materialization, compiler, events or writes. */
    public function previewLayout(string $outline, array $nodes, array $preset): array
    {
        $this->path($outline);
        if (!array_is_list($nodes)) { throw new \RuntimeException('Layout must be a node list.', 400); }
        $walk = function (array $items, int $depth = 0) use (&$walk): void {
            if ($depth > 32) { throw new \RuntimeException('Layout nesting exceeds the supported depth.', 400); }
            foreach ($items as $item) {
                $item = (array) $item;
                if (!isset($item['id'], $item['type']) || !is_string($item['id']) || !is_string($item['type'])) {
                    throw new \RuntimeException('Every layout node needs its native identity and type.', 400);
                }
                if (isset($item['children'])) {
                    if (!is_array($item['children'])) { throw new \RuntimeException('Invalid layout children.', 400); }
                    $walk($item['children'], $depth + 1);
                }
            }
        };
        $walk($nodes);
        return ['operation' => 'layout', 'outline' => $outline, 'layout' => $nodes, 'preset' => $preset];
    }

    /** Validate names before any writes while retaining installed native field types. */
    public function previewSettings(string $outline, array $groups): array
    {
        $this->path($outline);
        if (array_diff(array_keys($groups), ['styles', 'page', 'particles'])) { throw new \RuntimeException('Unknown native settings group.', 400); }
        foreach ($groups as $group => $data) {
            if (!is_array($data)) { throw new \RuntimeException('Invalid native settings data.', 400); }
            foreach ($data as $id => $values) {
                if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id)) { throw new \RuntimeException('Invalid settings block identity.', 400); }
                // The native Styles form stores its selected swatch alongside blocks.
                if ($group === 'styles' && $id === 'preset' && is_string($values) && strlen($values) <= 100) { continue; }
                if ($values !== null && !is_array($values)) { throw new \RuntimeException('Settings blocks must be maps or reset values.', 400); }
                if (is_array($values)) {
                    $collection = $this->gantry[$group === 'styles' ? 'styles' : ($group === 'page' ? 'page' : 'particles')];
                    $collection->getBlueprintForm($id);
                }
            }
        }
        return ['operation' => 'settings', 'outline' => $outline, 'groups' => $groups];
    }

    public function saveLayout(string $outline, array $nodes, array $preset, mixed $controller = null): array
    {
        $this->previewLayout($outline, $nodes, $preset);
        $nodes = json_decode(json_encode($nodes, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        $layout = new Layout($outline, $nodes, $preset);
        $layout->init(false, false);
        $elements = $layout->sections() + $layout->particles(false);
        $outlines = [$outline];
        foreach ($this->gantry['outlines']->getInheritingOutlines($outline) as $id => $title) {
            $this->path((string) $id);
            Layout::instance($id)->updateInheritance($outline, $outline, $elements)->save()->saveIndex();
            $outlines[] = (string) $id;
        }
        $layout->save()->saveIndex();
        $files = [];
        foreach ($outlines as $id) {
            $files[] = $this->path($id, 'layout.yaml');
            $files[] = $this->path($id, 'index.yaml');
        }
        $this->invalidate($files, $outlines);
        $event = new LayoutEvent();
        $event->gantry = $this->gantry;
        $event->theme = $this->gantry['theme'];
        $event->controller = $controller;
        $event->layout = $layout;
        $this->gantry->fireEvent('admin.layout.save', $event);
        return ['outlines' => $outlines];
    }

    /** Styles replace their complete group; page/particle maps replace submitted blocks. */
    public function saveSettings(string $outline, array $groups, mixed $controller = null): array
    {
        $this->previewSettings($outline, $groups);
        $files = [];
        foreach ($groups as $group => $data) {
            if (!is_array($data)) { throw new \RuntimeException('Invalid native settings data.', 400); }
            if ($group === 'styles') {
                $filename = $this->path($outline, 'styles.yaml');
                $this->write($filename, $data);
                $files[] = $filename;
                continue;
            }
            foreach ($data as $id => $values) {
                if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id)) {
                    throw new \RuntimeException('Invalid settings block identity.', 400);
                }
                if ($group === 'page' && $id === 'head') {
                    $layout = Layout::instance($outline);
                    if (is_array($layout->atoms())) { $layout->save(false); $files[] = $this->path($outline, 'layout.yaml'); }
                    if (is_array($values) && isset($values['atoms'])) {
                        $values['atoms'] = (new Atoms($values['atoms']))->update()->toArray();
                    }
                }
                $filename = $this->path($outline, "$group/$id.yaml");
                if (is_array($values)) {
                    $collection = $this->gantry[$group === 'particles' ? 'particles' : 'page'];
                    $blueprints = $collection->getBlueprintForm($id);
                    $config = new Config($values, static fn() => $blueprints);
                    $this->write($filename, $config->toArray());
                } elseif (is_file($filename) && !unlink($filename)) {
                    throw new \RuntimeException('Could not reset the settings block.', 500);
                }
                $files[] = $filename;
            }
        }
        $this->invalidate($files, [$outline]);
        foreach ($groups as $group => $data) {
            $class = ['styles' => StylesEvent::class, 'page' => PageEvent::class, 'particles' => SettingsEvent::class][$group];
            $event = new $class();
            $event->gantry = $this->gantry;
            $event->theme = $this->gantry['theme'];
            $event->controller = $controller;
            $event->data = $data;
            $this->gantry->fireEvent('admin.' . ($group === 'particles' ? 'settings' : $group) . '.save', $event);
        }
        $warnings = isset($groups['styles'])
            ? $this->gantry['theme']->updateCss($outline === 'default' ? null : [$outline => $this->gantry['outlines']->title($outline)]) : [];
        return ['warnings' => $warnings ?: []];
    }

    /** Derived caches are never journal content, and are refreshed before compilation. */
    public function invalidate(array $filenames, array $outlines): void
    {
        clearstatcache();
        $locator = $this->gantry['locator'];
        $directories = [$locator->findResource('gantry-cache://theme/compiled/yaml', true, true)];
        foreach ($outlines as $id) { $directories[] = $locator->findResource("gantry-cache://$id/compiled/yaml", true, true); }
        foreach (array_unique($directories) as $directory) {
            if (!is_string($directory) || !is_dir($directory)) { continue; }
            if (is_link($directory)) { throw new \RuntimeException('Linked configuration cache.', 409); }
            foreach ($filenames as $filename) {
                foreach (array_unique([$filename, realpath($filename) ?: $filename]) as $source) {
                    $this->removeCache($directory . '/' . md5($source) . '.yaml.php');
                }
            }
        }
        $directory = $locator->findResource('gantry-cache://theme/compiled/config', true, true);
        if (is_string($directory) && is_dir($directory)) {
            if (is_link($directory)) { throw new \RuntimeException('Linked configuration cache.', 409); }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (preg_match('/\A[a-f0-9]{32}\.php\z/D', $file->getFilename())) { $this->removeCache($file->getPathname()); }
            }
        }
    }

    /** Recovery can remove paths; clear every selected-theme derived YAML cache too. */
    public function invalidateAll(): void
    {
        $locator = $this->gantry['locator'];
        $ids = array_map('strval', array_keys($this->gantry['outlines']->toArray()));
        $directories = [$locator->findResource('gantry-cache://theme/compiled/yaml', true, true)];
        foreach ($ids as $id) { $directories[] = $locator->findResource("gantry-cache://$id/compiled/yaml", true, true); }
        foreach (array_unique($directories) as $directory) {
            if (!is_string($directory) || !is_dir($directory)) { continue; }
            if (is_link($directory)) { throw new \RuntimeException('Linked configuration cache.', 409); }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (preg_match('/\A[a-f0-9]{32}\.yaml\.php\z/D', $file->getFilename())) { $this->removeCache($file->getPathname()); }
            }
        }
        $this->invalidate([], $ids);
        if (isset($this->gantry['theme'])) {
            $warnings = $this->gantry['theme']->updateCss();
            if ($warnings) { throw new \RuntimeException('Restored CSS could not be compiled without warnings.', 422); }
        }
    }

    private function path(string $outline, string $suffix = ''): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $outline)) { throw new \RuntimeException('Invalid outline.', 400); }
        $locator = $this->gantry['locator'];
        $root = $locator->findResource('gantry-config://', true, true);
        $path = $locator->findResource("gantry-config://$outline" . ($suffix ? "/$suffix" : ''), true, true);
        if (!is_string($root) || !is_string($path) || $path !== rtrim($root, '/') . "/$outline" . ($suffix ? "/$suffix" : '')) {
            throw new \RuntimeException('Unavailable native outline configuration.', 409);
        }
        $cursor = rtrim($root, '/');
        foreach (explode('/', $outline . ($suffix ? "/$suffix" : '')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new \RuntimeException('Linked configuration paths are not supported.', 409); }
        }
        return $path;
    }

    private function write(string $filename, array $data): void
    {
        $file = YamlFile::instance($filename);
        try { $file->save($data); } finally { $file->free(); }
    }

    private function removeCache(string $filename): void
    {
        if (is_link($filename)) { throw new \RuntimeException('Linked configuration cache.', 409); }
        if (is_file($filename)) {
            if (!unlink($filename)) { throw new \RuntimeException('Could not refresh configuration cache.', 500); }
            if (function_exists('opcache_invalidate')) { opcache_invalidate($filename, true); }
        }
    }
}
