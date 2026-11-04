<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Framework;

use Gantry\Component\Api\ApiException;
use Gantry\Component\Changes\StateAdapter;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** Joomla owns its DB transaction, style rows, assignment preconditions and position metadata. */
final class ChangeState extends StateAdapter
{
    private readonly DatabaseInterface $db;
    private readonly string $theme;

    public function __construct(private readonly Gantry $gantry)
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->theme = (string) $gantry['theme.name'];
    }

    public function refresh(): void
    {
        \Gantry\Admin\ThemeList::getStyles($this->theme, true);
    }

    public function assigned(string $id): bool
    {
        if (!ctype_digit($id)) { return false; }
        $query = $this->db->getQuery(true)->select('COUNT(*)')->from('#__menu')->where('template_style_id=' . (int) $id);
        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    public function files(): array
    {
        $root = rtrim((string) $this->gantry['theme.path'], '/');
        return [
            'template-details' => $root . '/templateDetails.xml',
            'position-language' => $this->gantry['locator']->findResource('gantry-theme://language/en-GB/en-GB.tpl_' . $this->theme . '_positions.ini', true, true),
        ];
    }

    public function begin(): void { $this->db->transactionStart(); }
    public function commit(): void { $this->db->transactionCommit(); }
    public function rollback(): void { $this->db->transactionRollback(); }

    public function renderSources(): array
    {
        $sources = [];
        $bytes = 0;
        // Offline public derivatives must never declassify an administrator's
        // private/unpublished content. Query the native guest ACL, not this
        // build request's authenticated administrator identity.
        $levels = array_map('intval', Access::getAuthorisedViewLevels(0));
        if (!$levels) { return []; }
        $categoryRows = $this->db->setQuery($this->db->getQuery(true)->select(['id', 'parent_id', 'published', 'access', 'description'])->from('#__categories'))->loadAssocList('id');
        $visibility = [];
        $checking = [];
        $visible = function (int $id) use (&$visible, &$visibility, &$checking, $categoryRows, $levels): bool {
            if ($id === 0) { return true; }
            if (isset($visibility[$id])) { return $visibility[$id]; }
            if (isset($checking[$id]) || !isset($categoryRows[$id])) { return false; }
            $checking[$id] = true;
            $row = $categoryRows[$id];
            $result = (int) $row['published'] === 1 && in_array((int) $row['access'], $levels, true) && $visible((int) $row['parent_id']);
            unset($checking[$id]);
            return $visibility[$id] = $result;
        };
        $publicCategories = [];
        foreach ($categoryRows as $id => $row) {
            if ($visible((int) $id)) { $publicCategories[] = (int) $id; }
        }
        $now = $this->db->quote(gmdate('Y-m-d H:i:s'));
        foreach (['#__content' => ['introtext', 'fulltext', 'images'], '#__modules' => ['content']] as $table => $columns) {
            $query = $this->db->getQuery(true)->select($this->db->quoteName($columns))->from($this->db->quoteName($table))->order('id')
                ->where('access IN (' . implode(',', $levels) . ')')
                ->where(($table === '#__content' ? 'state' : 'published') . '=1');
            if ($table === '#__content') {
                if (!$publicCategories) { continue; }
                $query->where('catid IN (' . implode(',', $publicCategories) . ')');
            } else { $query->where('client_id=0'); }
            foreach (['publish_up' => '<=', 'publish_down' => '>='] as $column => $operator) {
                $query->where('(' . $column . ' IS NULL OR ' . $column . "='0000-00-00 00:00:00' OR " . $column . $operator . $now . ')');
            }
            $rows = $this->db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                if ($table === '#__content') {
                    // These are the two native guest-rendered article image
                    // fields, not arbitrary extension parameters or captions.
                    $images = is_string($row['images'] ?? null) ? json_decode($row['images'], true) : null;
                    unset($row['images']);
                    if (is_array($images)) {
                        foreach (['image_intro', 'image_fulltext'] as $field) {
                            if (is_string($images[$field] ?? null) && $images[$field] !== '') {
                                // Joomla removes this dimensions metadata from
                                // the emitted src. Preserve other URL fragments.
                                $row[$field] = explode('#joomlaImage://', $images[$field], 2)[0];
                            }
                        }
                    }
                }
                foreach ($row as $value) {
                    if (!is_string($value) || $value === '') { continue; }
                    $bytes += strlen($value);
                    if ($bytes > 67108864 || count($sources) >= 20000) { throw new \RuntimeException('Native content exceeds the offline render-source limit.', 422); }
                    $sources[] = $value;
                }
            }
        }
        foreach ($publicCategories as $id) {
            $value = $categoryRows[$id]['description'];
            if (is_string($value) && $value !== '') { $sources[] = $value; $bytes += strlen($value); }
        }
        if ($bytes > 67108864 || count($sources) > 20000) { throw new \RuntimeException('Native content exceeds the offline render-source limit.', 422); }
        return $sources;
    }

    public function capture(): array
    {
        if (!$this->db) { return []; }
        $db = $this->db;
        $query = $db->getQuery(true)->select('*')->from('#__template_styles')->where('client_id=0')->where('template=' . $db->quote($this->theme))->order('id');
        $styles = $db->setQuery($query)->loadAssocList();
        $ids = array_map('intval', array_column($styles, 'id'));
        $assignments = [];
        if ($ids) {
            $query = $db->getQuery(true)->select(['id', 'template_style_id'])->from('#__menu')->where('template_style_id IN (' . implode(',', $ids) . ')')->order('id');
            $assignments = $db->setQuery($query)->loadAssocList();
        }
        return ['styles' => $styles, 'assignments' => $assignments];
    }

    public function restore(array $metadata): void
    {
        if (!$this->db) { return; }
        $db = $this->db;
        $current = $this->capture();
        // Undo must never reassign menus, replace a default, or delete a newly assigned style.
        if ($current['assignments'] !== $metadata['assignments']) {
            throw new ApiException('Menu assignments changed; undo is unavailable.', 409, 'revision_conflict');
        }
        $before = array_column($metadata['styles'], null, 'id');
        $after = array_column($current['styles'], null, 'id');
        foreach (array_diff_key($after, $before) as $id => $row) {
            if ($row['home'] !== '0' || in_array($id, array_column($current['assignments'], 'template_style_id'))) {
                throw new ApiException('A protected outline cannot be undone.', 409, 'protected_outline');
            }
            $db->setQuery($db->getQuery(true)->delete('#__template_styles')->where('id=' . (int) $id)->where('client_id=0')->where('template=' . $db->quote($this->theme)))->execute();
        }
        foreach ($before as $id => $row) {
            // Joomla's native driver accepts the object by reference, and exact
            // recovery must write original NULL fields rather than skip them.
            $record = (object) $row;
            if (isset($after[$id])) {
                if ($after[$id] !== $row && !$db->updateObject('#__template_styles', $record, 'id', true)) { throw new ApiException('Native outline metadata could not be restored.', 500, 'recovery_failed'); }
            } else {
                $exists = $db->setQuery($db->getQuery(true)->select('id')->from('#__template_styles')->where('id=' . (int) $id))->loadResult();
                if ($exists) { throw new ApiException('The original outline ID is now in use.', 409, 'outline_conflict'); }
                if (!$db->insertObject('#__template_styles', $record)) { throw new ApiException('Native outline metadata could not be restored.', 500, 'recovery_failed'); }
            }
        }
    }

}
