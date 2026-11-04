<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Api;

use Gantry\Component\Config\BlueprintForm;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout;
use Gantry\Framework\Gantry;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Changes\NativeWriter;
use Gantry\Framework\Outlines;
use Gantry\Framework\Services\ConfigServiceProvider;

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
    private readonly ChangeEngine $changes;
    private readonly NativeWriter $writer;

    public function __construct(private readonly Gantry $gantry)
    {
        $this->theme = Input::id($gantry['theme.name']);
        $this->changes = new ChangeEngine($gantry);
        $this->writer = new NativeWriter($gantry);
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
        Input::shape($payload, ['title', 'preset', 'revision'], ['title']);
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
        }, isset($payload['revision']) ? Input::revision($payload['revision']) : null, 'create');
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
        }, Input::revision($payload['revision']), 'rename');
    }

    public function deleteOutline(string $id, array $payload): array
    {
        Input::shape($payload, ['revision'], ['revision']);
        return $this->locked(function () use ($id, $payload): array {
            $this->requireRevision($id, $payload['revision']);
            $this->requireDeletable($id);
            $outlines = $this->outlines;
            $outlines->delete($id);
            return ['id' => $id, 'deleted' => true];
        }, Input::revision($payload['revision']), 'delete');
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
        return $this->locked(function () use ($id, $payload): array {
            $plan = $this->planLayout($id, $payload);
            $this->writer->saveLayout($id, $plan['layout'], $plan['preset']);
            return $this->getLayout($id);
        }, Input::revision($payload['revision'] ?? null), 'layout');
    }

    private function planLayout(string $id, array $payload): array
    {
        Input::shape($payload, ['layout', 'preset', 'revision'], ['layout', 'revision']);
        $nodes = Input::layout($payload['layout']);
        $this->requireRevision($id, $payload['revision']);
        $old = Layout::load($id);
        $oldNodes = [];
        $this->indexNodes($this->arrayData($old->toArray()), $oldNodes);
        $this->validateNodes($id, $nodes, $oldNodes);
        $preset = $old->preset;
        if (array_key_exists('preset', $payload)) { Input::preset($payload['preset'], $preset); }
        return $this->writer->previewLayout($id, $nodes, $preset);
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
        return $this->locked(function () use ($id, $payload): array {
            $plan = $this->planSettings($id, $payload);
            $saved = $this->writer->saveSettings($id, $plan['groups']);
            if (!empty($saved['warnings'])) { throw new ApiException('The native CSS compiler reported warnings.', 422, 'css_compile_failed'); }
            return $this->getSettings($id);
        }, Input::revision($payload['revision'] ?? null), 'settings');
    }

    private function planSettings(string $id, array $payload): array
    {
        Input::shape($payload, ['styles', 'page', 'particles', 'revision'], ['revision']);
        if (count($payload) === 1) { throw new ApiException('Supply at least one settings group.', 400, 'invalid_fields'); }
        $this->requireRevision($id, $payload['revision']);
        $settings = $this->getSettings($id);
        $groups = [];
        foreach ($this->collections as $group => $collection) {
            if (!array_key_exists($group, $payload)) { continue; }
            $patch = $payload[$group];
            if (!is_array($patch) || array_is_list($patch) || !$patch) { throw new ApiException('Settings groups must be non-empty objects.', 422, 'invalid_settings'); }
            $installed = $collection->all();
            foreach ($patch as $block => $values) {
                Input::id($block);
                if (!array_key_exists($block, $installed) || !is_array($values) || !$values || array_is_list($values)) { throw new ApiException('Use an installed settings block with named values.', 422, 'invalid_settings'); }
                $form = $collection->getBlueprintForm($block);
                $this->validateFields($form, $values, $group === 'styles');
                $complete = Input::merge((array) ($settings['effective'][$group][$block] ?? []), $values);
                $this->nativeValidate($form, $complete);
                $groups[$group][$block] = Input::merge((array) ($settings['raw'][$group][$block] ?? []), $values);
            }
            // The common writer receives canonical replacement data. REST is a patch;
            // native admin forms supply complete selected blocks through the same writer.
            if ($group === 'styles') { $groups[$group] = array_replace($settings['raw'][$group], $groups[$group]); }
        }
        return $this->writer->previewSettings($id, $groups);
    }

    public function history(): array
    {
        return ['id' => $this->theme, 'theme' => $this->theme] + $this->changes->history();
    }

    public function undo(array $payload): array
    {
        Input::shape($payload, ['revision', 'change_id'], ['revision', 'change_id']);
        if (!is_string($payload['change_id'])) { throw new ApiException('Use a change identifier.', 400, 'invalid_change'); }
        $result = $this->changes->undo(Input::revision($payload['revision']), $payload['change_id']);
        $this->refreshOutlines();
        return ['id' => $this->theme, 'theme' => $this->theme] + $result;
    }

    /** Same validated plans consumed by save, without invoking persistence or events. */
    public function preview(array $payload): array
    {
        Input::shape($payload, ['operation', 'outline', 'data', 'revision'], ['operation', 'data', 'revision']);
        if (!is_string($payload['operation']) || !is_array($payload['data'])) { throw new ApiException('Use a named operation and object data.', 400, 'invalid_preview'); }
        $revision = Input::revision($payload['revision']);
        $id = isset($payload['outline']) ? Input::id($payload['outline']) : null;
        $result = $this->changes->preview($revision, function () use ($payload, $revision, $id): array {
            $data = $payload['data'];
            switch ($payload['operation']) {
                case 'layout':
                    if ($id === null) { throw new ApiException('Select an outline.', 400, 'invalid_outline'); }
                    return $this->planLayout($id, $data + ['revision' => $revision]);
                case 'settings':
                    if ($id === null) { throw new ApiException('Select an outline.', 400, 'invalid_outline'); }
                    return $this->planSettings($id, $data + ['revision' => $revision]);
                case 'rename':
                    if ($id === null) { throw new ApiException('Select an outline.', 400, 'invalid_outline'); }
                    Input::shape($data, ['title'], ['title']);
                    $this->requireOutline($id);
                    if ($id === 'default' || str_starts_with($id, '_')) { throw new ApiException('System outlines cannot be renamed.', 409, 'protected_outline'); }
                    return ['operation' => 'rename', 'outline' => $id, 'title' => Input::title($data['title'])];
                case 'create':
                    Input::shape($data, ['title', 'preset'], ['title']);
                    $preset = Input::id($data['preset'] ?? 'default');
                    if (!isset(Layout::presets()['user'][$preset])) { throw new ApiException('Choose an installed user layout preset.', 422, 'invalid_preset'); }
                    return ['operation' => 'create', 'title' => Input::title($data['title']), 'preset' => $preset, 'assigned' => false];
                case 'delete':
                    Input::shape($data, []);
                    if ($id === null) { throw new ApiException('Select an outline.', 400, 'invalid_outline'); }
                    $this->requireDeletable($id);
                    return ['operation' => 'delete', 'outline' => $id];
                default: throw new ApiException('Preview a supported outline, layout or settings operation.', 400, 'invalid_preview');
            }
        });
        return ['id' => $id ?? $this->theme, 'theme' => $this->theme] + $result;
    }

    private function requireDeletable(string $id): void
    {
        $this->requireOutline($id);
        if ($id === 'default' || str_starts_with($id, '_') || $this->outlines->isDefault($id) || !$this->outlines->canDelete($id)) { throw new ApiException('Base, system and default outlines cannot be deleted.', 409, 'protected_outline'); }
        if ($this->assigned($id) || $this->outlines->getInheritingOutlines($id) || $this->outlines->getInheritingOutlinesWithAtom($id)) { throw new ApiException('An assigned or inherited outline cannot be deleted.', 409, 'outline_in_use'); }
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
        return $this->changes->revision();
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

    /** Every transport uses the same theme generation, lock, writer and recovery journal. */
    private function locked(callable $operation, ?string $revision = null, string $label = 'outline'): array
    {
        try {
            $change = $this->changes->save($revision ?? $this->changes->revision(), $label, fn (): array => $this->fresh($operation));
            return array_replace($change['result'], ['revision' => $change['revision'], 'change_id' => $change['change_id']]);
        } catch (ApiException $error) { throw $error; }
        catch (\Throwable $error) { throw new ApiException('The native Gantry operation failed. Inspect the server log.', 500, 'native_operation_failed', $error); }
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

    /** Scoped native cache invalidation is shared by every writer. */
    private function invalidateCaches(array $filenames, array $outlines): void
    {
        $this->writer->invalidate($filenames, $outlines);
    }

    private function assigned(string $id): bool
    {
        return $this->changes->isAssigned($id);
    }

    private function refreshOutlines(): void
    {
        // Joomla's native writer stores the template-prefixed DB title in its
        // collection, whereas its loader exposes the short editor title. Reload
        // the native style catalogue so mutation and following GET revisions agree.
        $this->changes->refreshCatalog();
        $this->outlines->load();
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

    private function validateNodes(string $outline, array $nodes, array $old): void
    {
        $installed = $this->collections['particles']->all();
        foreach ($nodes as $node) {
            $prior = $old[$node['id']] ?? [];
            $structural = Input::nodeType($node['type'], $node['subtype'], $installed);
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
