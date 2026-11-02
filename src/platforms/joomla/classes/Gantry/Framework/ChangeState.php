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
