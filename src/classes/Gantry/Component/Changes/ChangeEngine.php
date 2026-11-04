<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Changes;

use Gantry\Component\Api\ApiException;
use Gantry\Component\Api\Input;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Framework\Gantry;

/**
 * The single theme mutation boundary for native editors and CMS transports.
 * Preview runs only a pure planner. Save and undo serialize, check content revisions,
 * journal exact authoritative state, recover failure, and return one new revision.
 * Joomla prepares an immutable render generation before committing, then atomically
 * activates it. Interrupted finalization blocks further writes for verified recovery.
 */
final class ChangeEngine
{
    private const GUARD = "<?php exit; ?>\n";
    private static array $active = [];
    private readonly ThemeState $state;
    private readonly string $directory;

    public function __construct(private readonly Gantry $gantry)
    {
        $this->state = new ThemeState($gantry);
        $config = $gantry['locator']->findResource('gantry-config://', true, true);
        $directory = is_string($config) ? dirname(rtrim($config, '/')) . '/.gantry-changes' : null;
        if (!is_string($directory) || $directory === '' || $directory[0] !== '/') {
            throw new ApiException('The change journal is unavailable.', 500, 'journal_unavailable');
        }
        $parent = realpath(dirname($directory));
        $this->directory = ($parent ?: dirname($directory)) . '/' . basename($directory);
        $this->safeDirectory();
    }

    public function refreshCatalog(): void { $this->state->refresh(); }
    public function isAssigned(string $id): bool { return $this->state->assigned($id); }

    public function revision(): string
    {
        $this->requireHealthy();
        return $this->digest($this->state->capture());
    }

    public function preview(string $expectedRevision, callable $planner): array
    {
        return $this->locked(function () use ($expectedRevision, $planner): array {
            $revision = $this->check($expectedRevision);
            $snapshot = $this->state->capture();
            $this->state->begin();
            $rolledBack = false;
            try {
                $result = $this->fresh($planner);
                if (!hash_equals($revision, $this->digest($this->state->capture()))) {
                    throw new ApiException('A preview planner attempted to change authoritative state.', 500, 'impure_preview');
                }
                $this->state->rollback();
                $rolledBack = true;
                return ['revision' => $revision, 'preview' => true, 'result' => $result];
            } catch (\Throwable $error) {
                if (!$rolledBack) { $this->state->rollback(); }
                // Normal planners have no effects. Recover a programming error defensively;
                // this path must never be used to implement preview by running a writer.
                if (!hash_equals($revision, $this->digest($this->state->capture()))) {
                    try {
                        $this->state->restore($snapshot);
                        $this->fresh(fn () => $this->refresh());
                        if (!hash_equals($revision, $this->digest($this->state->capture()))) { throw new \RuntimeException('Preview recovery differs from the original revision.'); }
                    } catch (\Throwable $recovery) {
                        $this->writeRecord('pending', ['schema' => 1, 'theme' => (string) $this->gantry['theme.name'], 'before' => $revision, 'before_state' => $snapshot, 'before_generation' => $this->generation(), 'reason' => 'impure_preview']);
                        throw new ApiException('Preview recovery requires administrator attention.', 500, 'recovery_failed', $recovery);
                    }
                }
                throw $error;
            }
        });
    }

    public function save(string $expectedRevision, string $label, callable $writer): array
    {
        return $this->locked(fn (): array => $this->commit($expectedRevision, $label, $writer));
    }

    /** Rebuild derived production artifacts under the same lock, without changing source/revision. */
    public function rebuild(string $expectedRevision): array
    {
        return $this->locked(function () use ($expectedRevision): array {
            $revision = $this->check($expectedRevision);
            if (!isset($this->gantry['render.registry'])) { throw new ApiException('This CMS render adapter is unavailable.', 503, 'render_unavailable'); }
            $registry = $this->gantry['render.registry'];
            $id = $registry->prepare($revision, $this->state->renderSources());
            $this->check($revision);
            $registry->activate($id, $revision);
            return ['revision' => $revision, 'render_generation' => $id];
        });
    }

    public function undo(string $expectedRevision, string $changeId): array
    {
        return $this->locked(function () use ($expectedRevision, $changeId): array {
            $revision = $this->check($expectedRevision);
            $change = $this->readChange($changeId);
            if (!hash_equals($change['after'], $revision)) {
                throw new ApiException('Later changes exist. Undo only the current latest change.', 409, 'revision_conflict');
            }
            return $this->commit($revision, 'undo', function () use ($change, $changeId): array {
                $this->state->restore($change['before_state'], true);
                $this->refresh();
                if (!hash_equals($change['before_state_hash'], $this->state->digest($this->state->capture()))) { throw new ApiException('The original theme state could not be verified after undo.', 500, 'recovery_failed'); }
                return ['undone' => $changeId];
            });
        });
    }

    /** Safe summaries only; snapshots may contain private page/config data. */
    public function history(): array
    {
        $revision = $this->revision();
        $items = [];
        if (is_dir($this->directory)) {
            foreach (new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (!preg_match('/\A[a-f0-9]{64}\.php\z/D', $file->getFilename())) { continue; }
                $id = substr($file->getFilename(), 0, 64);
                $change = $this->readChange($id);
                $items[] = ['id' => $id, 'label' => $change['label'], 'created' => $change['created'], 'can_undo' => hash_equals($change['after'], $revision)];
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['created'], $a['created']));
        return ['revision' => $revision, 'items' => $items];
    }

    private function commit(string $expectedRevision, string $label, callable $writer): array
    {
        $before = $this->check($expectedRevision);
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_. \/-]{0,79}\z/D', $label)) { throw new ApiException('The change label is invalid.', 400, 'invalid_change'); }
        $snapshot = $this->state->capture();
        $id = bin2hex(random_bytes(32));
        $generation = $this->generation();
        $record = ['before_generation' => $generation, 'before_state_hash' => $this->state->digest($snapshot), 'schema' => 1, 'theme' => (string) $this->gantry['theme.name'], 'label' => $label, 'created' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'), 'before' => $before, 'before_state' => $snapshot];
        $registry = isset($this->gantry['render.registry']) ? $this->gantry['render.registry'] : null;
        $record['before_render'] = $registry?->activeId();
        $this->state->begin();
        $active = true;
        try {
            $this->writeRecord('pending', $record);
            self::$active[$this->directory] = true;
            $result = $this->fresh($writer);
            $this->writeRecord('generation', ['generation' => $id]);
            $after = $this->digest($this->state->capture());
            $record['after'] = $after;
            // Prepare all immutable bytes while native DB changes are still
            // transactional. Existing readers keep their pinned old bundle.
            $render = $registry?->prepare($after, $this->state->renderSources());
            if (!hash_equals($after, $this->digest($this->state->capture()))) {
                throw new ApiException('Theme state changed during render preparation.', 409, 'revision_conflict');
            }
            $record['after_render'] = $render;
            $this->writeRecord($id, $record);
            $this->state->commit();
            $active = false;
            // Failure from here must leave the pending marker and must never
            // attempt a database rollback after a successful native commit.
            if ($render !== null) { $registry->activate($render, $after); }
            $this->prune();
            $this->removeRecord('pending');
            $response = ['revision' => $after, 'change_id' => $id, 'result' => $result];
            if ($render !== null) { $response['render_generation'] = $render; }
            return $response;
        } catch (\Throwable $error) {
            // A durable pending record deliberately blocks subsequent edits if recovery fails.
            // An interrupted process requires operator recovery, never blind success/retry.
            if (!$active) { throw new ApiException('The change committed but journal finalization requires recovery.', 500, 'recovery_required', $error); }
            try {
                $this->state->rollback();
                $changed = !hash_equals($this->state->digest($snapshot), $this->state->digest($this->state->capture()));
                if ($changed) { $this->state->restore($snapshot); }
                $this->writeRecord('generation', ['generation' => $generation]);
                if ($changed) { $this->fresh(fn () => $this->refresh()); }
                if (!hash_equals($before, $this->digest($this->state->capture()))) {
                    throw new \RuntimeException('Recovered state differs from the original revision.');
                }
                $this->removeRecord($id);
                $this->removeRecord('pending');
            } catch (\Throwable $recovery) {
                throw new ApiException('Theme recovery requires administrator attention. Further edits are blocked.', 500, 'recovery_failed', $recovery);
            }
            throw $error;
        } finally {
            unset(self::$active[$this->directory]);
        }
    }

    private function check(string $expected): string
    {
        Input::revision($expected);
        $revision = $this->revision();
        if (!hash_equals($revision, $expected)) { throw new ApiException('The theme changed. Reload before saving or undoing.', 409, 'revision_conflict'); }
        return $revision;
    }

    private function locked(callable $operation): array
    {
        $this->safeDirectory();
        if (isset(self::$active[$this->directory])) { throw new ApiException('Nested theme mutations are unsupported.', 409, 'write_in_progress'); }
        $parent = dirname($this->directory);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new ApiException('The change journal is unavailable.', 500, 'journal_unavailable');
        }
        $filename = $this->directory . '.lock';
        if (is_link($filename)) { throw new ApiException('Linked change locks are unsupported.', 409, 'unsafe_config_path'); }
        $lock = fopen($filename, 'c');
        if ($lock === false) { throw new ApiException('The theme lock is unavailable.', 500, 'lock_unavailable'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new ApiException('Another theme edit is in progress. Reload and retry.', 409, 'write_in_progress'); }
            $this->requireHealthy();
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function fresh(callable $operation): mixed
    {
        $global = $this->gantry['global'];
        $has = array_key_exists('compile_yaml', $global->toArray());
        $old = $global->get('compile_yaml');
        $yaml = CompiledYamlFile::$defaultCaching;
        $global->set('compile_yaml', false);
        CompiledYamlFile::$defaultCaching = false;
        try { return $operation(); }
        finally {
            CompiledYamlFile::$defaultCaching = $yaml;
            if ($has) { $global->set('compile_yaml', $old); } else { unset($global['compile_yaml']); }
        }
    }

    private function refresh(): void
    {
        $this->state->refresh();
        $this->gantry['outlines']->load();
        if (class_exists(NativeWriter::class)) { (new NativeWriter($this->gantry))->invalidateAll(); }
    }

    private function writeRecord(string $id, array $record): void
    {
        $this->safeDirectory();
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new ApiException('The change journal is unavailable.', 500, 'journal_unavailable'); }
        chmod($this->directory, 0700);
        ThemeState::atomic($this->directory . '/' . $id . '.php', self::GUARD . json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
    }

    private function readChange(string $id): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) { throw new ApiException('The change identifier is invalid.', 400, 'invalid_change'); }
        $path = $this->directory . '/' . $id . '.php';
        if (is_link($path) || !is_file($path) || filesize($path) > 50331648) { throw new ApiException('The change is unavailable.', 409, 'change_unavailable'); }
        $bytes = file_get_contents($path);
        try {
            if (!is_string($bytes) || !str_starts_with($bytes, self::GUARD)) { throw new \JsonException('Invalid guard'); }
            $record = json_decode(substr($bytes, strlen(self::GUARD)), true, 128, JSON_THROW_ON_ERROR);
            if (($record['schema'] ?? null) !== 1 || ($record['theme'] ?? null) !== (string) $this->gantry['theme.name'] || !isset($record['before_state'], $record['after'], $record['before'], $record['label'], $record['created']) || !isset($record['before_state_hash']) || !hash_equals($record['before_state_hash'], $this->state->digest($record['before_state']))) { throw new \JsonException('Invalid snapshot'); }
            return $record;
        } catch (\Throwable $error) { throw new ApiException('The change snapshot is invalid.', 409, 'invalid_change', $error); }
    }

    private function removeRecord(string $id): void
    {
        $path = $this->directory . '/' . $id . '.php';
        if (is_link($path) || (is_file($path) && !unlink($path))) { throw new ApiException('The change journal needs recovery.', 500, 'recovery_required'); }
    }

    private function requireHealthy(): void
    {
        if (!isset(self::$active[$this->directory]) && (file_exists($this->directory . '/pending.php') || is_link($this->directory . '/pending.php'))) {
            throw new ApiException('An interrupted theme change requires administrator recovery.', 503, 'recovery_required');
        }
    }

    private function digest(array $state): string
    {
        return hash('sha256', $this->state->digest($state) . ':' . $this->generation());
    }

    private function generation(): string
    {
        $path = $this->directory . '/generation.php';
        if (is_link($path)) { throw new ApiException('Linked change generations are unsupported.', 409, 'invalid_change'); }
        if (!file_exists($path)) { return 'initial'; }
        $bytes = file_get_contents($path);
        try {
            if (!is_string($bytes) || !str_starts_with($bytes, self::GUARD)) { throw new \JsonException('Invalid guard'); }
            $generation = json_decode(substr($bytes, strlen(self::GUARD)), true, 8, JSON_THROW_ON_ERROR)['generation'] ?? null;
            if ($generation !== 'initial' && (!is_string($generation) || !preg_match('/\\A[a-f0-9]{64}\\z/D', $generation))) { throw new \JsonException('Invalid generation'); }
            return $generation;
        } catch (\Throwable $error) { throw new ApiException('The change generation requires recovery.', 503, 'recovery_required', $error); }
    }

    private function safeDirectory(): void
    {
        $cursor = '';
        foreach (explode('/', ltrim($this->directory, '/')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new ApiException('Linked change journals are unsupported.', 409, 'unsafe_config_path'); }
        }
    }

    private function prune(): void
    {
        $files = [];
        foreach (new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (!preg_match('/\A[a-f0-9]{64}\.php\z/D', $file->getFilename())) { continue; }
            $id = substr($file->getFilename(), 0, 64);
            $record = $this->readChange($id);
            $files[$id] = ['id' => $id, 'created' => (string) $record['created']];
        }
        uasort($files, static fn (array $a, array $b): int => strcmp($b['created'], $a['created']) ?: strcmp($b['id'], $a['id']));
        // The active generation is always retained, even if the host clock moved
        // backwards. Filesystem mtimes and directory iteration do not order edits.
        $current = $this->generation();
        $ids = array_keys($files);
        if (isset($files[$current])) {
            $ids = array_values(array_diff($ids, [$current]));
            array_unshift($ids, $current);
        }
        foreach (array_slice($ids, 20) as $id) { $this->removeRecord($id); }
    }
}
