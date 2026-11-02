<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Api;

use Gantry\Admin\Events\LayoutEvent;
use Gantry\Admin\Events\PageEvent;
use Gantry\Admin\Events\SettingsEvent;
use Gantry\Admin\Events\StylesEvent;
use Gantry\Component\Config\BlueprintForm;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Services\ConfigServiceProvider;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * The bounded core data surface shared by CMS transports.
 * Authentication and CMS permissions belong to the native CMS transport.
 * Native outline, layout, blueprint, YAML, event and CSS paths stay authoritative.
 */
final class ThemeApi
{
    private readonly string $theme;
    private readonly Outlines $outlines;
    private array $collections;

    public function __construct(private readonly Gantry $gantry)
    {
        $this->theme = Input::id($gantry['theme.name']);
        // The container intentionally returns a fresh clone on every outlines lookup.
        $this->outlines = $gantry['outlines'];
        $this->collections = [
            'styles' => new \Gantry\Admin\Styles($gantry),
            'page' => new \Gantry\Admin\Page($gantry),
            'particles' => new \Gantry\Admin\Particles($gantry),
        ];
    }

    public function listOutlines(): array
    {
        $items = [];
        foreach ($this->outlines as $id => $title) {
            $items[] = $this->getOutline((string) $id);
        }
        return ['items' => $items];
    }

    public function getOutline(string $id): array
    {
        return $this->fresh(function () use ($id): array {
            $this->requireOutline($id);
            $outlines = $this->outlines;
            return $this->resource($id) + [
                'title' => $outlines->title($id),
                'preset' => $outlines->preset($id),
                'system' => $id === 'default' || str_starts_with($id, '_'),
                'default' => $id === 'default' || $outlines->isDefault($id),
            ];
        });
    }

    public function createOutline(array $payload): array
    {
        Input::shape($payload, ['title', 'preset'], ['title']);
        $title = Input::title($payload['title']);
        $preset = Input::id($payload['preset'] ?? 'default');
        $presets = Layout::presets();
        if (!isset($presets['user'][$preset])) {
            throw new ApiException('Choose an installed user layout preset.', 422, 'invalid_preset');
        }
        return $this->locked(function () use ($title, $preset): array {
            // The native Joomla collection reserves its actual style ID before writing layout data.
            $id = $this->outlines->create(null, $title, $preset, function ($id): void {
                $path = $this->path((string) $id);
                if (file_exists($path)) {
                    throw new ApiException('The new outline path is already in use.', 409, 'outline_conflict');
                }
            });
            $this->invalidateCaches([$this->path((string) $id, 'layout.yaml'), $this->path((string) $id, 'index.yaml')], [(string) $id]);
            $this->refreshOutlines();
            return $this->getOutline((string) $id);
        });
    }

    public function updateOutline(string $id, array $payload): array
    {
        Input::shape($payload, ['title', 'revision'], ['title', 'revision']);
        $title = Input::title($payload['title']);
        return $this->locked(function () use ($id, $title, $payload): array {
            $this->requireRevision($id, $payload['revision']);
            if ($id === 'default' || str_starts_with($id, '_')) {
                throw new ApiException('System outlines cannot be renamed.', 409, 'protected_outline');
            }
            $newId = $this->outlines->rename($id, $title);
            $this->refreshOutlines();
            return $this->getOutline((string) ($newId ?: $id));
        });
    }

    public function deleteOutline(string $id, array $payload): array
    {
        Input::shape($payload, ['revision'], ['revision']);
        return $this->locked(function () use ($id, $payload): array {
            $this->requireRevision($id, $payload['revision']);
            $outlines = $this->outlines;
            if ($id === 'default' || str_starts_with($id, '_') || $outlines->isDefault($id) || !$outlines->canDelete($id)) {
                throw new ApiException('Base, system and default outlines cannot be deleted.', 409, 'protected_outline');
            }
            if ($this->assigned($id) || $outlines->getInheritingOutlines($id) || $outlines->getInheritingOutlinesWithAtom($id)) {
                throw new ApiException('An assigned or inherited outline cannot be deleted.', 409, 'outline_in_use');
            }
            $this->path($id);
            $outlines->delete($id);
            return ['id' => $id, 'deleted' => true];
        });
    }

    public function getLayout(string $id): array
    {
        return $this->fresh(function () use ($id): array {
            $this->requireOutline($id);
            $layout = Layout::load($id);
            return $this->resource($id) + ['layout' => $this->arrayData($layout->toArray()), 'preset' => $layout->preset];
        });
    }

    public function updateLayout(string $id, array $payload): array
    {
        Input::shape($payload, ['layout', 'preset', 'revision'], ['layout', 'revision']);
        $nodes = Input::layout($payload['layout']);
        return $this->locked(function () use ($id, $payload, $nodes): array {
            $this->requireRevision($id, $payload['revision']);
            $old = Layout::load($id);
            $oldNodes = [];
            $this->indexNodes($this->arrayData($old->toArray()), $oldNodes);
            $this->validateNodes($id, $nodes, $oldNodes);
            $preset = $old->preset;
            if (array_key_exists('preset', $payload)) {
                Input::preset($payload['preset'], $preset);
            }
            // The native writer expects objects, exactly as the editor JSON decoder produces.
            $layout = new Layout($id, $this->nodeObjects($nodes), $preset);
            $layout->init(false, false);
            $elements = $layout->sections() + $layout->particles(false);
            $changed = [$id];
            foreach ($this->outlines->getInheritingOutlines($id) as $dependent => $title) {
                $this->path((string) $dependent);
                Layout::instance($dependent)->updateInheritance($id, $id, $elements)->save()->saveIndex();
                $changed[] = (string) $dependent;
            }
            $layout->save()->saveIndex();
            $files = [];
            foreach ($changed as $changedId) {
                $files[] = $this->path($changedId, 'layout.yaml');
                $files[] = $this->path($changedId, 'index.yaml');
            }
            $this->invalidateCaches($files, $changed);
            $event = new LayoutEvent();
            $event->gantry = $this->gantry;
            $event->theme = $this->gantry['theme'];
            $event->layout = $layout;
            $this->gantry->fireEvent('admin.layout.save', $event);
            return $this->getLayout($id);
        });
    }

    public function getSettings(string $id): array
    {
        return $this->fresh(function () use ($id): array {
            $this->requireOutline($id);
            $raw = ConfigServiceProvider::load($this->gantry, $id, false, false);
            $effective = ConfigServiceProvider::load($this->gantry, $id, true, true);
            $result = ['raw' => [], 'effective' => []];
            foreach (array_keys($this->collections) as $group) {
                $result['raw'][$group] = (array) $raw->get($group, []);
                $result['effective'][$group] = (array) $effective->get($group, []);
            }
            return $this->resource($id) + $result;
        });
    }

    public function updateSettings(string $id, array $payload): array
    {
        Input::shape($payload, ['styles', 'page', 'particles', 'revision'], ['revision']);
        if (count($payload) === 1) {
            throw new ApiException('Supply at least one settings group.', 400, 'invalid_fields');
        }
        return $this->locked(function () use ($id, $payload): array {
            $this->requireRevision($id, $payload['revision']);
            $settings = $this->getSettings($id);
            $writes = [];
            $events = [];
            foreach ($this->collections as $group => $collection) {
                if (!array_key_exists($group, $payload)) { continue; }
                $patch = $payload[$group];
                if (!is_array($patch) || array_is_list($patch) || !$patch) {
                    throw new ApiException('Settings groups must be non-empty objects.', 422, 'invalid_settings');
                }
                $installed = $collection->all();
                $merged = $settings['raw'][$group];
                foreach ($patch as $block => $values) {
                    Input::id($block);
                    if (!array_key_exists($block, $installed) || !is_array($values) || !$values || array_is_list($values)) {
                        throw new ApiException('Use an installed settings block with named values.', 422, 'invalid_settings');
                    }
                    $form = $collection->getBlueprintForm($block);
                    $this->validateFields($form, $values, $group === 'styles');
                    $complete = Input::merge((array) ($settings['effective'][$group][$block] ?? []), $values);
                    $this->nativeValidate($form, $complete);
                    $merged[$block] = Input::merge((array) ($merged[$block] ?? []), $values);
                    if ($group !== 'styles') {
                        $writes[$this->path($id, "$group/$block.yaml")] = $merged[$block];
                    }
                }
                if ($group === 'styles') { $writes[$this->path($id, 'styles.yaml')] = $merged; }
                $events[$group] = $merged;
            }
            // All groups validate before the first write. Restore exact prior bytes on save/compile failure.
            $backup = [];
            try {
                foreach ($writes as $filename => $data) {
                    $backup[$filename] = is_file($filename) ? file_get_contents($filename) : null;
                    if ($backup[$filename] === false) { throw new ApiException('The current settings could not be read.', 500, 'settings_read_failed'); }
                    $file = YamlFile::instance($filename);
                    try { $file->save($data); } finally { $file->free(); }
                }
                $this->invalidateCaches(array_keys($writes), [$id]);
                if (isset($events['styles'])) { $this->compile($id); }
            } catch (\Throwable $error) {
                foreach ($backup as $filename => $bytes) {
                    if ($bytes === null) {
                        if (is_file($filename) && !unlink($filename)) { throw new ApiException('Settings recovery requires administrator attention.', 500, 'settings_recovery_failed', $error); }
                    } elseif (is_string($bytes) && file_put_contents($filename, $bytes, LOCK_EX) === false) {
                        throw new ApiException('Settings recovery requires administrator attention.', 500, 'settings_recovery_failed', $error);
                    }
                }
                $this->invalidateCaches(array_keys($backup), [$id]);
                if (isset($events['styles'])) { $this->compile($id); }
                throw $error;
            }
            foreach ($events as $group => $data) {
                $class = ['styles' => StylesEvent::class, 'page' => PageEvent::class, 'particles' => SettingsEvent::class][$group];
                $event = new $class();
                $event->gantry = $this->gantry;
                $event->theme = $this->gantry['theme'];
                $event->data = $data;
                $this->gantry->fireEvent('admin.' . ($group === 'particles' ? 'settings' : $group) . '.save', $event);
            }
            return $this->getSettings($id);
        });
    }

    private function requireOutline(string $id): void
    {
        Input::id($id);
        if (!isset($this->outlines[$id])) {
            throw new ApiException('The outline was not found in the selected theme.', 404, 'outline_not_found');
        }
        $this->path($id);
    }

    private function resource(string $id): array
    {
        return ['id' => $id, 'theme' => $this->theme, 'revision' => $this->revision($id)];
    }

    private function requireRevision(string $id, mixed $revision): void
    {
        $this->requireOutline($id);
        if (!hash_equals($this->revision($id), Input::revision($revision))) {
            throw new ApiException('The outline changed. Read it again before writing.', 409, 'revision_conflict');
        }
    }

    private function revision(string $id): string
    {
        $parts = [$this->theme, $id, $this->outlines->title($id), (string) $this->outlines->isDefault($id)];
        foreach (array_unique([$id, 'default']) as $outline) {
            foreach ($this->gantry['locator']->findResources("gantry-config://$outline") as $directory) {
                if (!is_dir($directory)) { continue; }
                $files = [];
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) { throw new ApiException('Linked configuration paths are not supported.', 409, 'unsafe_config_path'); }
                    if ($file->isFile()) {
                        if (count($files) >= 1024 || $file->getSize() > 2097152) { throw new ApiException('The configuration exceeds the API limits.', 409, 'config_too_large'); }
                        $hash = hash_file('sha256', $file->getPathname());
                        if ($hash === false) { throw new ApiException('The configuration could not be read.', 500, 'config_read_failed'); }
                        $files[substr($file->getPathname(), strlen($directory))] = $hash;
                    }
                }
                ksort($files);
                $parts[] = json_encode($files, JSON_THROW_ON_ERROR);
            }
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** Resolve only native selected-theme config paths, rejecting linked descendants. */
    private function path(string $id, string $suffix = ''): string
    {
        Input::id($id);
        if ($suffix !== '' && !preg_match('/\A(?:(?:styles|layout|index)\.yaml|(?:page|particles)\/[a-zA-Z0-9_-]+\.yaml)\z/D', $suffix)) {
            throw new ApiException('The configuration path is invalid.', 400, 'unsafe_config_path');
        }
        $root = $this->gantry['locator']->findResource('gantry-config://', true, true);
        $path = $this->gantry['locator']->findResource("gantry-config://$id" . ($suffix ? "/$suffix" : ''), true, true);
        if (!is_string($root) || !is_string($path)) { throw new ApiException('The writable theme configuration is unavailable.', 500, 'config_path_unavailable'); }
        $root = rtrim($root, '/');
        if ($path !== "$root/$id" . ($suffix ? "/$suffix" : '')) {
            throw new ApiException('The configuration path is outside the selected theme.', 409, 'unsafe_config_path');
        }
        $cursor = $root;
        foreach (explode('/', $id . ($suffix ? "/$suffix" : '')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new ApiException('Linked configuration paths are not supported.', 409, 'unsafe_config_path'); }
        }
        return $path;
    }

    /** API writers serialize per selected theme. The revision also detects completed admin saves. */
    private function locked(callable $operation): array
    {
        $filename = $this->gantry['locator']->findResource('gantry-cache://theme/gantry-api.lock', true, true);
        if (!is_string($filename) || is_link($filename)) { throw new ApiException('The API write lock is unavailable.', 500, 'lock_unavailable'); }
        $directory = dirname($filename);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ApiException('The API write lock is unavailable.', 500, 'lock_unavailable');
        }
        $lock = fopen($filename, 'c');
        if ($lock === false) { throw new ApiException('The API write lock is unavailable.', 500, 'lock_unavailable'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new ApiException('Another API write is in progress. Retry after reading the outline.', 409, 'write_in_progress'); }
            return $this->fresh($operation);
        } catch (ApiException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ApiException('The native Gantry operation failed. Inspect the server log.', 500, 'native_operation_failed', $error);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Native YAML and combined-config caches use second-resolution mtimes.
     * Request-local bypass prevents equal-length, same-second API writes from
     * reading or compiling the previous value. No cache/config file is removed,
     * and nested calls restore the exact preceding flags even after failure.
     */
    private function fresh(callable $operation): array
    {
        $global = $this->gantry['global'];
        $hadFlag = array_key_exists('compile_yaml', $global->toArray());
        $compiled = $global->get('compile_yaml');
        $yaml = CompiledYamlFile::$defaultCaching;
        $global->set('compile_yaml', false);
        CompiledYamlFile::$defaultCaching = false;
        try {
            return $operation();
        } finally {
            CompiledYamlFile::$defaultCaching = $yaml;
            if ($hadFlag) { $global->set('compile_yaml', $compiled); }
            else { unset($global['compile_yaml']); }
        }
    }

    /** Invalidate only native derived files; front-end/admin reads must see same-second writes too. */
    private function invalidateCaches(array $filenames, array $outlines): void
    {
        clearstatcache();
        $locator = $this->gantry['locator'];
        $yamlDirectories = [$locator->findResource('gantry-cache://theme/compiled/yaml', true, true)];
        foreach ($outlines as $id) {
            Input::id($id);
            $yamlDirectories[] = $locator->findResource("gantry-cache://$id/compiled/yaml", true, true);
        }
        foreach (array_unique($yamlDirectories) as $directory) {
            if (!is_string($directory) || !is_dir($directory)) { continue; }
            if (is_link($directory)) { throw new ApiException('Linked API cache paths are not supported.', 409, 'unsafe_cache_path'); }
            foreach ($filenames as $filename) {
                foreach (array_unique([$filename, realpath($filename) ?: $filename]) as $source) {
                    $this->removeCache($directory . '/' . md5($source) . '.yaml.php');
                }
            }
        }
        // Native combined-config names are hashes of path sets, including default inheritance.
        // Rebuild the selected theme's generated combined configs instead of guessing every consumer.
        $directory = $locator->findResource('gantry-cache://theme/compiled/config', true, true);
        if (is_string($directory) && is_dir($directory)) {
            if (is_link($directory)) { throw new ApiException('Linked API cache paths are not supported.', 409, 'unsafe_cache_path'); }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (preg_match('/\A[a-f0-9]{32}\.php\z/D', $file->getFilename())) {
                    $this->removeCache($file->getPathname());
                }
            }
        }
    }

    private function removeCache(string $filename): void
    {
        if (is_link($filename)) { throw new ApiException('Linked API cache paths are not supported.', 409, 'unsafe_cache_path'); }
        if (is_file($filename)) {
            if (!unlink($filename)) { throw new ApiException('A native configuration cache could not be refreshed.', 500, 'cache_refresh_failed'); }
            if (function_exists('opcache_invalidate')) { opcache_invalidate($filename, true); }
        }
    }

    private function assigned(string $id): bool
    {
        // Only the Joomla transport ships in this release. Query native menu assignment, never change it.
        if (class_exists(\Joomla\CMS\Factory::class) && ctype_digit($id)) {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)->select('COUNT(*)')->from('#__menu')->where('template_style_id=' . (int) $id);
            $db->setQuery($query);
            return (int) $db->loadResult() > 0;
        }
        return false;
    }

    private function refreshOutlines(): void
    {
        // Joomla's native writer stores the template-prefixed DB title in its
        // collection, whereas its loader exposes the short editor title. Reload
        // the native style catalogue so mutation and following GET revisions agree.
        if (class_exists(\Joomla\CMS\Factory::class)) {
            \Gantry\Admin\ThemeList::getStyles($this->theme, true);
        }
        $this->outlines->load();
    }

    private function compile(string $id): void
    {
        if ($this->gantry['theme']->updateCss($id === 'default' ? null : [$id => $this->outlines->title($id)])) {
            throw new ApiException('The native CSS compiler reported warnings.', 422, 'css_compile_failed');
        }
    }

    private function arrayData(array $data): array
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    }

    private function nativeValidate(BlueprintForm $form, array $data): void
    {
        try { $form->validate($data); } catch (\RuntimeException $error) {
            throw new ApiException('A value does not satisfy its installed Gantry blueprint.', 422, 'blueprint_validation_failed', $error);
        }
    }

    private function validateFields(BlueprintForm $form, array $patch, bool $styles = false): void
    {
        Input::data($patch);
        $state = $form->schema()->getState();
        if (!isset($state['items'], $state['nested']) || !is_array($state['items']) || !is_array($state['nested'])) {
            throw new ApiException('The installed blueprint schema is unavailable.', 500, 'blueprint_unavailable');
        }
        $this->fields($patch, $state['nested'], $state['items'], $styles);
    }

    private function fields(array $patch, array $rules, array $items, bool $styles): void
    {
        foreach ($patch as $name => $value) {
            $rule = $rules[$name] ?? null;
            if (is_array($rule) && is_array($value) && !array_is_list($value)) {
                $this->fields($value, $rule, $items, $styles);
                continue;
            }
            $field = is_string($rule) ? ($items[$rule] ?? null) : null;
            if (!$field) { throw new ApiException('A settings field is not in the installed blueprint.', 422, 'unknown_setting'); }
            $type = $field['type'] ?? '';
            if (!in_array($type, ['input.text', 'input.number', 'input.checkbox', 'input.toggle', 'input.radios', 'input.selectize', 'input.colorpicker', 'input.fonts', 'input.icon', 'input.imagepicker', 'input.section-variations', 'input.block-variations', 'select.select', 'select.selectize', 'select.fonts'], true)
                || preg_match('/(?:^|[._-])(?:html|svg|code|twig|filter|atoms|inline|javascript|css|extra|body_top|body_bottom|head_bottom)(?:$|[._-])/i', (string) $name)) {
                throw new ApiException('Executable content, asset/code controls and untyped collections are outside this API.', 422, 'unsupported_setting');
            }
            if (!is_scalar($value) && $value !== null) { throw new ApiException('Use a scalar value for this settings control.', 422, 'invalid_setting'); }
            Input::safeValue($value, $styles);
            if (isset($field['options']) && is_array($field['options']) && $value !== null && !array_key_exists((string) $value, $field['options'])) {
                throw new ApiException('Choose a value from the installed control options.', 422, 'invalid_setting');
            }
            if ($type === 'input.colorpicker' && (!is_string($value) || !preg_match('/\A(?:#[a-f0-9]{3,4}|#[a-f0-9]{6}|#[a-f0-9]{8}|transparent|currentColor|(?:rgb|hsl)a?\([0-9.,%\s+-]+\))\z/iD', $value))) {
                throw new ApiException('Use a supported CSS color.', 422, 'invalid_setting');
            }
        }
    }

    private function indexNodes(array $nodes, array &$index): void
    {
        foreach ($nodes as $node) {
            $index[$node['id']] = $node;
            if (isset($node['children'])) { $this->indexNodes($node['children'], $index); }
        }
    }

    private function nodeObjects(array $nodes): array
    {
        $objects = [];
        foreach ($nodes as $node) {
            $object = json_decode(json_encode($node, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
            // JSON object {} and [] both arrive as [] with Joomla's associative decoder.
            $object->attributes = (object) $object->attributes;
            if (isset($node['inherit'])) { $object->inherit = (object) $object->inherit; }
            if (isset($node['block'])) { $object->block = (object) $object->block; }
            if (isset($node['children'])) { $object->children = $this->nodeObjects($node['children']); }
            $objects[] = $object;
        }
        return $objects;
    }

    private function validateNodes(string $outline, array $nodes, array $old): void
    {
        $installed = $this->collections['particles']->all();
        foreach ($nodes as $node) {
            $prior = $old[$node['id']] ?? [];
            $structural = in_array($node['type'], ['wrapper', 'container', 'section', 'grid', 'block', 'offcanvas'], true);
            if ($structural && $node['subtype'] !== $node['type']) { throw new ApiException('Structural node subtypes must match their type.', 422, 'invalid_layout'); }
            if (!$structural && !isset($installed[$node['subtype']])) { throw new ApiException('Use an installed particle subtype.', 422, 'invalid_layout'); }
            if (!empty($node['inherit']['outline'])) {
                $source = $node['inherit']['outline'];
                $this->requireOutline($source);
                if ($source === $outline || isset($this->outlines->getInheritingOutlines($outline)[$source])) {
                    throw new ApiException('Layout inheritance cannot form a cycle.', 422, 'invalid_inheritance');
                }
            }
            // Keep existing native code-bearing fields byte-for-byte, but never add or edit them through this surface.
            $changed = [];
            foreach ($node['attributes'] as $key => $value) {
                if (!array_key_exists($key, $prior['attributes'] ?? []) || $prior['attributes'][$key] !== $value || ($prior['subtype'] ?? null) !== $node['subtype']) {
                    $changed[$key] = $value;
                }
            }
            if ($changed) {
                if ($structural) {
                    $allowed = ['id', 'class', 'variations', 'boxed', 'size', 'fixed', 'position', 'swipe', 'css3animation', 'tag', 'name'];
                    Input::shape($changed, $allowed);
                    foreach ($changed as $key => $value) {
                        if (!is_scalar($value) && $value !== null) { throw new ApiException('Use scalar layout attributes.', 422, 'invalid_layout'); }
                        Input::safeValue($value);
                    }
                    if (isset($changed['tag']) && !in_array($changed['tag'], ['div', 'section', 'aside', 'nav', 'article', 'header', 'footer', 'main'], true)) {
                        throw new ApiException('Use a supported structural HTML tag.', 422, 'invalid_layout');
                    }
                    if (isset($changed['size']) && (!is_numeric($changed['size']) || $changed['size'] < 5 || $changed['size'] > 100)) {
                        throw new ApiException('Layout sizes must be between 5 and 100.', 422, 'invalid_layout');
                    }
                } else {
                    $form = $this->collections['particles']->getBlueprintForm($node['subtype']);
                    $this->validateFields($form, $changed);
                    $this->nativeValidate($form, $node['attributes']);
                }
            }
            if (isset($node['block']) && $node['block'] !== ($prior['block'] ?? [])) {
                throw new ApiException('Edit block attributes on the native block node.', 422, 'invalid_layout');
            }
            if (isset($node['children'])) { $this->validateNodes($outline, $node['children'], $old); }
        }
    }
}
