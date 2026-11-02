<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Changes;

use Gantry\Component\Api\ApiException;
use Gantry\Framework\Gantry;

/** Bounded authoritative state; derived caches and arbitrary theme files are never journalled. */
final class ThemeState
{
    private array $roots;
    private readonly string $theme;
    private readonly StateAdapter $adapter;
    private array $fileScopes = [];

    public function __construct(private readonly Gantry $gantry)
    {
        $this->theme = (string) $gantry['theme.name'];
        $config = $gantry['locator']->findResource('gantry-config://', true, true);
        if (!is_string($config) || $config === '' || $config[0] !== '/') {
            throw new ApiException('The writable theme configuration is unavailable.', 500, 'config_path_unavailable');
        }
        if (is_link($config)) { throw new ApiException('Linked theme state is unsupported.', 409, 'unsafe_config_path'); }
        $parent = realpath(dirname(rtrim($config, '/')));
        $config = ($parent ?: dirname(rtrim($config, '/'))) . '/' . basename(rtrim($config, '/'));
        $this->roots = ['config' => $config];
        // The native design-pack importer writes exclusively owned bitmaps here.
        // Do not snapshot unrelated images, uploads, source, SCSS or credentials.
        if (basename($config) === 'config') {
            $this->roots['design-packs'] = dirname($config) . '/images/design-packs';
        }
        $this->adapter = new \Gantry\Framework\ChangeState($gantry);
        foreach ($this->adapter->files() as $scope => $path) {
            if (!is_string($path) || $path === '' || $path[0] !== '/') { throw new ApiException('Native state paths are unavailable.', 500, 'config_path_unavailable'); }
            $this->roots[$scope] = $path;
            $this->fileScopes[$scope] = true;
        }
        foreach ($this->roots as $root) { $this->safePath($root); }
    }

    public function capture(): array
    {
        $state = ['files' => [], 'directories' => [], 'metadata' => $this->adapter->capture()];
        $size = 0;
        foreach ($this->roots as $scope => $root) {
            $this->safePath($root);
            if (!file_exists($root)) { continue; }
            if (isset($this->fileScopes[$scope])) {
                if (!is_file($root) || filesize($root) > 4194304) { throw new ApiException('Native state file is unavailable or too large.', 409, 'state_too_large'); }
                $bytes = file_get_contents($root);
                if ($bytes === false) { throw new ApiException('Native state could not be read.', 500, 'state_read_failed'); }
                $state['files'][$scope] = ['bytes' => base64_encode($bytes), 'mode' => fileperms($root) & 0777];
                continue;
            }
            if (!is_dir($root)) { throw new ApiException('The theme state root is not a directory.', 409, 'unsafe_config_path'); }
            $state['directories'][] = $scope;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if ($file->isLink()) { throw new ApiException('Linked theme state is unsupported.', 409, 'unsafe_config_path'); }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (str_ends_with($relative, '.lock')) { continue; }
                $key = $scope . '/' . $relative;
                if ($file->isDir()) {
                    $state['directories'][] = $key;
                } elseif ($file->isFile()) {
                    $size += $file->getSize();
                    if ($file->getSize() > 4194304 || $size > 33554432 || count($state['files']) >= 4096) {
                        throw new ApiException('The theme exceeds the recoverable change limit.', 409, 'state_too_large');
                    }
                    $bytes = file_get_contents($file->getPathname());
                    if ($bytes === false) { throw new ApiException('Theme state could not be read.', 500, 'state_read_failed'); }
                    $state['files'][$key] = ['bytes' => base64_encode($bytes), 'mode' => $file->getPerms() & 0777];
                } else {
                    throw new ApiException('Special files cannot be edited.', 409, 'unsafe_config_path');
                }
                if (count($state['directories']) > 2048) { throw new ApiException('The theme exceeds the recoverable change limit.', 409, 'state_too_large'); }
            }
        }
        ksort($state['files']);
        sort($state['directories']);
        return $state;
    }

    public function digest(array $state): string
    {
        // Include the whole theme: a parent/default edit invalidates every editor.
        return hash('sha256', json_encode([$this->theme, $state], JSON_THROW_ON_ERROR));
    }

    public function refresh(): void { $this->adapter->refresh(); }
    public function assigned(string $id): bool { return $this->adapter->assigned($id); }

    public function begin(): void { $this->adapter->begin(); }
    public function commit(): void { $this->adapter->commit(); }
    public function rollback(): void { $this->adapter->rollback(); }
    public function renderSources(): array { return $this->adapter->renderSources(); }

    /** Restore exact original config bytes. Database restoration is used for committed undo only. */
    public function restore(array $state, bool $metadata = false): void
    {
        $current = $this->capture();
        foreach ($state['files'] as $key => $record) {
            $path = $this->path($key);
            $bytes = base64_decode($record['bytes'], true);
            if ($bytes === false) { throw new ApiException('The change snapshot is invalid.', 409, 'invalid_change'); }
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                throw new ApiException('Theme recovery could not create a directory.', 500, 'recovery_failed');
            }
            self::atomic($path, $bytes, $record['mode']);
        }
        foreach (array_diff_key($current['files'], $state['files']) as $key => $unused) {
            $path = $this->path($key);
            if (!unlink($path)) { throw new ApiException('Theme recovery could not remove a file.', 500, 'recovery_failed'); }
        }
        $remove = array_diff($current['directories'], $state['directories']);
        usort($remove, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($remove as $key) {
            $path = $this->path($key);
            if (is_dir($path) && count(scandir($path)) === 2 && !rmdir($path)) {
                throw new ApiException('Theme recovery could not remove a directory.', 500, 'recovery_failed');
            }
        }
        foreach ($state['directories'] as $key) {
            $path = $this->path($key);
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new ApiException('Theme recovery could not restore a directory.', 500, 'recovery_failed');
            }
        }
        if ($metadata) { $this->adapter->restore($state['metadata']); }
        clearstatcache();
    }

    private function path(string $key): string
    {
        [$scope, $relative] = array_pad(explode('/', $key, 2), 2, '');
        if ((isset($this->fileScopes[$scope]) && $relative !== '') || !isset($this->roots[$scope]) || str_contains($relative, '\\') || str_contains($relative, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative) || str_starts_with($relative, '/')) {
            throw new ApiException('The snapshot path is invalid.', 409, 'invalid_change');
        }
        $path = $this->roots[$scope] . ($relative !== '' ? '/' . $relative : '');
        $this->safePath($path);
        return $path;
    }

    private function safePath(string $path): void
    {
        $cursor = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new ApiException('Linked theme state is unsupported.', 409, 'unsafe_config_path'); }
        }
    }

    /** Publish one complete file with rename, never expose a half-written YAML or journal. */
    public static function atomic(string $path, string $bytes, int $mode = 0600): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $temporary = dirname($path) . '/.g6-write-' . bin2hex(random_bytes(12)) . ($extension !== '' ? '.' . $extension : '.tmp');
        $handle = null;
        try {
            $handle = fopen($temporary, 'xb');
            if ($handle === false) { throw new ApiException('The change could not be staged.', 500, 'state_write_failed'); }
            if (!chmod($temporary, $mode) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new ApiException('The change could not be staged.', 500, 'state_write_failed');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) { throw new ApiException('The change could not be activated.', 500, 'state_write_failed'); }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
