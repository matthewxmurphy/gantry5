<?php
/**
 * Private selected-outline snapshots and guarded restoration.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com.
 * @license MIT; existing Gantry and third-party licenses remain unchanged.
 */
namespace Gantry\Component\Upgrade;

use Symfony\Component\Yaml\Yaml;

class OutlineSnapshot
{
    const MAX_FILES = 128;
    const MAX_FILE_BYTES = 262144;
    const MAX_TOTAL_BYTES = 1048576;
    const MAX_JSON_BYTES = 2097152;

    private $root;
    private $identity;

    public function __construct($root, array $identity)
    {
        if (!is_string($root) || !is_dir($root) || is_link($root) || !realpath($root)) {
            throw new \RuntimeException('The selected outline has no accessible configuration directory.', 400);
        }
        $this->root = realpath($root);
        foreach (['cms', 'theme', 'outline'] as $key) {
            if (!isset($identity[$key]) || !is_string($identity[$key])
                || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $identity[$key])) {
                throw new \RuntimeException('Invalid outline identity.', 400);
            }
        }
        $this->identity = array_intersect_key($identity, array_flip(['cms', 'theme', 'outline']));
    }

    public function create()
    {
        $files = $this->readFiles();
        $snapshot = ['schema' => 1, 'scope' => $this->identity, 'files' => []];
        foreach ($files as $path => $bytes) {
            $snapshot['files'][$path] = ['sha256' => hash('sha256', $bytes), 'bytes' => base64_encode($bytes)];
        }
        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function preview($json)
    {
        $files = $this->decode($json);
        $current = $this->readFiles();
        $this->assertCases(array_merge(array_keys($current), array_keys($files)));
        $changes = [];
        foreach ($files as $path => $bytes) {
            $changes[] = ['path' => $path, 'action' => !isset($current[$path]) ? 'create'
                : ($current[$path] === $bytes ? 'unchanged' : 'replace')];
        }
        return ['revision' => $this->revision($current), 'snapshot' => hash('sha256', $json),
            'files' => $changes, 'preserved' => count(array_diff_key($current, $files)),
            'changes' => count(array_filter($changes, static function ($item) { return $item['action'] !== 'unchanged'; }))];
    }

    public function restore($json, $revision, $snapshot, $confirmed)
    {
        if ($confirmed !== true || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)
            || !is_string($snapshot) || !hash_equals(hash('sha256', $json), $snapshot)) {
            throw new \RuntimeException('Preview the same snapshot and explicitly confirm restoration.', 400);
        }
        $files = $this->decode($json);
        // The lock coordinates assistant restores; close other configuration editors before restoring.
        $lockPath = $this->root . '/.gantry-upgrade.lock';
        if (is_link($lockPath) || (file_exists($lockPath)
            && (!is_file($lockPath) || (lstat($lockPath)['nlink'] ?? 0) !== 1))) {
            throw new \RuntimeException('Unsafe configuration lock.', 400);
        }
        $mask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($mask);
        if (!$lock || (fstat($lock)['nlink'] ?? 0) !== 1 || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            throw new \RuntimeException('Cannot lock outline configuration.', 500);
        }
        $staged = $before = $committed = $directories = [];
        try {
            $current = $this->readFiles();
            $this->assertCases(array_merge(array_keys($current), array_keys($files)));
            if (!hash_equals($this->revision($current), $revision)) {
                throw new \RuntimeException('Outline settings changed after preview. Preview again.', 409);
            }
            foreach ($files as $path => $bytes) {
                if (isset($current[$path]) && $current[$path] === $bytes) {
                    continue;
                }
                $target = $this->target($path);
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    $missing = $parent;
                    while (!is_dir($missing)) {
                        $directories[] = $missing;
                        $missing = dirname($missing);
                    }
                    if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                        throw new \RuntimeException('Cannot prepare outline directories.', 500);
                    }
                }
                $this->target($path);
                $before[$path] = array_key_exists($path, $current) ? $current[$path] : null;
                $staged[$path] = $this->stage($target, $bytes);
            }
            if (!hash_equals($this->revision($this->readFiles()), $revision)) {
                throw new \RuntimeException('Outline settings changed while preparing restoration. Preview again.', 409);
            }
            foreach ($staged as $path => $temporary) {
                $target = $this->target($path);
                $this->commit($temporary, $target);
                unset($staged[$path]);
                $committed[] = $path;
            }
            return ['restored' => count($committed), 'preserved' => count(array_diff_key($current, $files))];
        } catch (\Throwable $error) {
            $compensated = true;
            foreach (array_reverse($committed) as $path) {
                try {
                    $target = $this->target($path);
                    if ($before[$path] === null) {
                        if (!unlink($target)) {
                            throw new \RuntimeException('Compensation failed.');
                        }
                    } else {
                        $temporary = $this->stage($target, $before[$path]);
                        if (!rename($temporary, $target)) {
                            @unlink($temporary);
                            throw new \RuntimeException('Compensation failed.');
                        }
                    }
                } catch (\Throwable $ignored) {
                    $compensated = false;
                }
            }
            if (!$compensated) {
                throw new \RuntimeException('Restoration failed and compensation is incomplete. Keep your private backup for manual recovery.', 500);
            }
            if ($error instanceof \RuntimeException && in_array($error->getCode(), [400, 409], true)) {
                throw $error;
            }
            throw new \RuntimeException('Restoration failed; previous settings were recovered.', 500);
        } finally {
            foreach ($staged as $temporary) {
                @unlink($temporary);
            }
            foreach ($directories as $directory) {
                @rmdir($directory);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function commit($temporary, $target)
    {
        if (!@rename($temporary, $target)) {
            throw new \RuntimeException('Cannot replace outline configuration.', 500);
        }
    }

    private function stage($target, $bytes)
    {
        $temporary = dirname($target) . '/.gantry-upgrade-' . bin2hex(random_bytes(16)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if (!$stream) {
            throw new \RuntimeException('Cannot stage outline configuration.', 500);
        }
        try {
            chmod($temporary, 0600);
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
                throw new \RuntimeException('Cannot stage outline configuration.', 500);
            }
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } catch (\Throwable $error) {
            fclose($stream);
            @unlink($temporary);
            throw $error;
        }
        fclose($stream);
        chmod($temporary, file_exists($target) ? (fileperms($target) & 0777) : 0644);
        return $temporary;
    }

    private function decode($json)
    {
        if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
            throw new \RuntimeException('Snapshot exceeds the supported size.', 400);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            // Accept only our canonical exported format, refusing duplicate/ambiguous JSON keys.
            if (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !== $json) {
                throw new \RuntimeException();
            }
            if (!is_array($data) || array_keys($data) !== ['schema', 'scope', 'files'] || $data['schema'] !== 1
                || $data['scope'] !== $this->identity || !is_array($data['files']) || count($data['files']) > self::MAX_FILES) {
                throw new \RuntimeException();
            }
            $files = [];
            $total = 0;
            foreach ($data['files'] as $path => $entry) {
                $this->target($path);
                if (!is_array($entry) || array_keys($entry) !== ['sha256', 'bytes']
                    || !is_string($entry['sha256']) || !is_string($entry['bytes'])) {
                    throw new \RuntimeException();
                }
                $bytes = base64_decode($entry['bytes'], true);
                if ($bytes === false || base64_encode($bytes) !== $entry['bytes'] || strlen($bytes) > self::MAX_FILE_BYTES
                    || !hash_equals(hash('sha256', $bytes), $entry['sha256'])) {
                    throw new \RuntimeException();
                }
                $total += strlen($bytes);
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new \RuntimeException();
                }
                // Validate data only: no object construction, PHP/Twig/HTML evaluation or include.
                $parsed = Yaml::parse($bytes);
                if ($parsed !== null && !is_array($parsed)) {
                    throw new \RuntimeException();
                }
                $files[$path] = $bytes;
            }
            ksort($files);
            $this->assertCases(array_keys($files));
            return $files;
        } catch (\Throwable $error) {
            // Parser errors can contain private source values; never forward their messages.
            throw new \RuntimeException('Invalid snapshot, scope, configuration path or YAML data.', 400);
        }
    }

    private function revision(array $files)
    {
        $hashes = [];
        foreach ($files as $path => $bytes) {
            $hashes[$path] = hash('sha256', $bytes);
        }
        return hash('sha256', json_encode([$this->identity, $hashes], JSON_THROW_ON_ERROR));
    }

    private function readFiles()
    {
        if (!is_dir($this->root) || is_link($this->root) || realpath($this->root) !== $this->root) {
            throw new \RuntimeException('Outline directory changed.', 400);
        }
        $files = [];
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Linked outline entries are unsupported.', 400);
            }
            if (!$file->isFile() || substr($file->getFilename(), -5) !== '.yaml') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($this->root) + 1);
            $target = $this->target($path);
            if (count($files) >= self::MAX_FILES || filesize($target) > self::MAX_FILE_BYTES) {
                throw new \RuntimeException('Outline exceeds the supported size.', 400);
            }
            $bytes = file_get_contents($target, false, null, 0, self::MAX_FILE_BYTES + 1);
            if ($bytes === false || strlen($bytes) > self::MAX_FILE_BYTES || ($total += strlen($bytes)) > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('Cannot read the bounded outline configuration.', 400);
            }
            $files[$path] = $bytes;
        }
        ksort($files);
        $this->assertCases(array_keys($files));
        return $files;
    }

    private function assertCases(array $paths)
    {
        $seen = [];
        foreach ($paths as $path) {
            $prefix = '';
            foreach (explode('/', $path) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                $folded = strtolower($prefix);
                if (isset($seen[$folded]) && $seen[$folded] !== $prefix) {
                    throw new \RuntimeException('Case-ambiguous configuration paths are unsupported.', 400);
                }
                $seen[$folded] = $prefix;
            }
        }
    }

    private function target($path)
    {
        if (!is_string($path) || strlen($path) > 180 || !preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+){0,4}\.yaml\z/D', $path)) {
            throw new \RuntimeException('Unsupported configuration path.', 400);
        }
        $target = $this->root;
        foreach (explode('/', $path) as $segment) {
            if (is_dir($target)) {
                foreach (new \FilesystemIterator($target, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    if (strtolower($entry->getFilename()) === strtolower($segment) && $entry->getFilename() !== $segment) {
                        throw new \RuntimeException('Case-aliased configuration targets are unsupported.', 400);
                    }
                }
            }
            $target .= '/' . $segment;
            if (is_link($target)) {
                throw new \RuntimeException('Linked configuration paths are unsupported.', 400);
            }
        }
        if (file_exists($target) && (!is_file($target) || (lstat($target)['nlink'] ?? 0) !== 1
            || strpos(realpath($target), $this->root . '/') !== 0)) {
            throw new \RuntimeException('Configuration path leaves its outline.', 400);
        }
        return $target;
    }
}
