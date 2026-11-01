<?php
/**
 * Explicit client text approvals and revision-checked layout patches.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 */
namespace Gantry\Component\ClientEditing;

use Gantry\Component\Layout\LayoutReader;
use Symfony\Component\Yaml\Yaml;

class PolicyStore
{
    const MAX_TEXT = 4000;
    const MAX_LAYOUT = 1048576;
    const MAX_POLICY = 131072;
    private $root;
    private $scope;
    private $outlines;
    private $safeRenderer;

    public function __construct($root, array $scope, array $outlines, $safeRenderer)
    {
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            throw new \RuntimeException('Client editing configuration is unavailable.', 400);
        }
        $this->root = rtrim($root, '/');
        $this->scope = $scope;
        $this->outlines = array_fill_keys($outlines, true);
        $this->safeRenderer = $safeRenderer === true;
    }

    public function policyRevision()
    {
        return hash('sha256', $this->policyBytes());
    }

    public function state()
    {
        $bytes = $this->policyBytes();
        if ($bytes === '') {
            if (file_exists($this->root . '/client-editing.yaml')) {
                throw new \RuntimeException('Existing client editing policy is empty and must be reviewed.', 400);
            }
            return ['enabled' => false, 'approvals' => [], 'revision' => hash('sha256', '')];
        }
        $data = $this->parse($bytes);
        if (array_keys($data) !== ['schema', 'scope', 'enabled', 'approvals'] || $data['schema'] !== 1
            || $data['scope'] !== $this->scope || !is_bool($data['enabled']) || !is_array($data['approvals'])
            || count($data['approvals']) > 64) {
            throw new \RuntimeException('Client editing policy is invalid; a settings administrator must review it.', 400);
        }
        $seen = [];
        foreach ($data['approvals'] as $row) {
            if (!is_array($row) || array_keys($row) !== ['outline', 'instance', 'fields']
                || !$this->id($row['outline']) || !$this->id($row['instance']) || $row['fields'] !== ['text']
                || isset($seen[$row['outline'] . ':' . $row['instance']])) {
                throw new \RuntimeException('Client editing policy is invalid; a settings administrator must review it.', 400);
            }
            $seen[$row['outline'] . ':' . $row['instance']] = true;
        }
        return ['enabled' => $data['enabled'], 'approvals' => $data['approvals'], 'revision' => hash('sha256', $bytes)];
    }

    public function candidates()
    {
        $choices = $revisions = [];
        if ($this->safeRenderer) {
            foreach (array_keys($this->outlines) as $outline) {
                $outline = (string) $outline;
                $path = $this->layoutPath($outline);
                if (!is_file($path)) {
                    continue;
                }
                $bytes = $this->read($path, self::MAX_LAYOUT);
                $raw = $this->parse($bytes);
                if (($raw['version'] ?? null) !== 2 || !isset($raw['layout']) || !is_array($raw['layout'])) {
                    continue;
                }
                $revisions[$outline] = hash('sha256', $bytes);
                foreach ($this->instances($raw) as $instance => $item) {
                    $choices[$outline . ':' . $instance] = [
                        'outline' => $outline, 'instance' => $instance,
                        'label' => is_string($item->title ?? null) ? $item->title : 'Client text',
                        'text' => $raw['content'][$instance]['attributes']['text'],
                        'layout_revision' => hash('sha256', $bytes)
                    ];
                }
            }
        }
        ksort($choices);
        ksort($revisions);
        return ['choices' => $choices, 'revision' => hash('sha256', json_encode([$this->scope, $revisions, array_keys($choices)]))];
    }

    public function approved()
    {
        $state = $this->state();
        $choices = $this->candidates()['choices'];
        $approved = [];
        if ($state['enabled']) {
            foreach ($state['approvals'] as $row) {
                $key = $row['outline'] . ':' . $row['instance'];
                if (isset($choices[$key])) {
                    $approved[$key] = $choices[$key];
                }
            }
        }
        return $approved;
    }

    public function savePolicy($revision, $candidateRevision, $enabled, array $selected, $confirmed)
    {
        if ($confirmed !== true || !is_bool($enabled) || count($selected) > 64) {
            throw new \RuntimeException('Explicitly confirm the existing native capabilities and approved fields.', 400);
        }
        return $this->locked(function () use ($revision, $candidateRevision, $enabled, $selected) {
            $this->same($this->policyRevision(), $revision, 'Policy changed. Reload before approving.');
            $candidates = $this->candidates();
            $this->same($candidates['revision'], $candidateRevision, 'Layouts changed. Reload before approving.');
            $approvals = [];
            foreach (array_unique($selected) as $key) {
                if (!is_string($key) || !isset($candidates['choices'][$key])) {
                    throw new \RuntimeException('Choose only currently available, non-inherited client text instances.', 400);
                }
                $item = $candidates['choices'][$key];
                $approvals[] = ['outline' => $item['outline'], 'instance' => $item['instance'], 'fields' => ['text']];
            }
            $bytes = Yaml::dump(['schema' => 1, 'scope' => $this->scope, 'enabled' => $enabled, 'approvals' => $approvals], 8, 2);
            $this->replace($this->root . '/client-editing.yaml', $bytes, 0600);
        }, true);
    }

    public function saveText($policyRevision, $layoutRevision, $outline, $instance, $text)
    {
        if (!is_string($text) || strlen($text) > self::MAX_TEXT * 4 || mb_strlen($text, 'UTF-8') > self::MAX_TEXT
            || !mb_check_encoding($text, 'UTF-8') || strpos($text, "\0") !== false || !$this->id($outline) || !$this->id($instance)) {
            throw new \RuntimeException('Use bounded plain text for an approved instance.', 400);
        }
        return $this->locked(function () use ($policyRevision, $layoutRevision, $outline, $instance, $text) {
            $state = $this->state();
            $this->same($state['revision'], $policyRevision, 'Policy changed. Reload before editing.');
            if (!$state['enabled'] || !isset($this->approved()[$outline . ':' . $instance])) {
                throw new \RuntimeException('This text instance is not approved for client editing.', 403);
            }
            $path = $this->layoutPath($outline);
            $bytes = $this->read($path, self::MAX_LAYOUT);
            $this->same(hash('sha256', $bytes), $layoutRevision, 'Layout changed. Reload before editing.');
            $raw = $this->parse($bytes);
            if (!isset($this->instances($raw)[$instance])) {
                throw new \RuntimeException('The approved text instance changed or is inherited.', 403);
            }
            $raw['content'][$instance]['attributes']['text'] = $text;
            $updated = Yaml::dump($raw, 20, 2);
            if (strlen($updated) > self::MAX_LAYOUT) {
                throw new \RuntimeException('The updated layout exceeds the supported size.', 400);
            }
            $this->same($this->state()['revision'], $policyRevision, 'Policy changed. Reload before editing.');
            $this->same(hash('sha256', $this->read($path, self::MAX_LAYOUT)), $layoutRevision, 'Layout changed. Reload before editing.');
            $this->replace($path, $updated, fileperms($path) & 0777);
        }, false);
    }

    private function instances(array $raw)
    {
        if (($raw['version'] ?? null) !== 2) {
            return [];
        }
        $found = [];
        $walk = function ($items, $inherited = false) use (&$walk, &$found, $raw) {
            foreach ($items as $item) {
                if (!is_object($item)) {
                    continue;
                }
                $blocked = $inherited || !empty($item->inherit->outline);
                $id = $item->id ?? null;
                if (!$blocked && ($item->type ?? null) === 'particle' && ($item->subtype ?? null) === 'client-text'
                    && $this->id($id) && isset($raw['content'][$id]['attributes']['text'])
                    && is_string($raw['content'][$id]['attributes']['text'])) {
                    $found[$id] = $item;
                }
                if (isset($item->children) && is_array($item->children)) {
                    $walk($item->children, $blocked);
                }
            }
        };
        $walk(LayoutReader::data($raw));
        return $found;
    }

    private function policyBytes()
    {
        $path = $this->root . '/client-editing.yaml';
        if (!file_exists($path) && !is_link($path)) {
            return '';
        }
        return $this->read($path, self::MAX_POLICY);
    }

    private function layoutPath($outline)
    {
        if (!$this->id($outline) || !isset($this->outlines[$outline])) {
            throw new \RuntimeException('Unknown outline.', 400);
        }
        return $this->root . '/' . $outline . '/layout.yaml';
    }

    private function read($path, $limit)
    {
        $this->safePath($path);
        if (!is_file($path) || filesize($path) > $limit || (lstat($path)['nlink'] ?? 0) !== 1) {
            throw new \RuntimeException('Client editing requires regular, bounded configuration files.', 400);
        }
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($bytes) || strlen($bytes) > $limit) {
            throw new \RuntimeException('Cannot read bounded client editing configuration.', 400);
        }
        return $bytes;
    }

    private function parse($bytes)
    {
        try {
            $data = Yaml::parse($bytes);
            if (!is_array($data)) {
                throw new \RuntimeException();
            }
            return $data;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Client editing configuration is invalid.', 400);
        }
    }

    private function id($id)
    {
        return is_string($id) && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id);
    }

    private function same($actual, $provided, $message)
    {
        if (!is_string($provided) || !hash_equals($actual, $provided)) {
            throw new \RuntimeException($message, 409);
        }
    }

    private function safePath($path)
    {
        if (strpos($path, $this->root . '/') !== 0) {
            throw new \RuntimeException('Client editing path is outside its theme.', 400);
        }
        $walk = '';
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if (is_dir($walk === '' ? '/' : $walk)) {
                foreach (new \FilesystemIterator($walk === '' ? '/' : $walk, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    if (strtolower($entry->getFilename()) === strtolower($segment) && $entry->getFilename() !== $segment) {
                        throw new \RuntimeException('Case-aliased client editing paths are unsupported.', 400);
                    }
                }
            }
            $walk .= '/' . $segment;
            if (is_link($walk)) {
                throw new \RuntimeException('Linked client editing paths are unsupported.', 400);
            }
        }
    }

    private function locked(callable $operation, $create)
    {
        $this->safePath($this->root . '/client-editing.yaml');
        if (!is_dir($this->root) && (!$create || !mkdir($this->root, 0755, true))) {
            throw new \RuntimeException('Client editing configuration directory is unavailable.', 400);
        }
        $path = $this->root . '/.client-editing.lock';
        $this->safePath($path);
        if (file_exists($path) && (!is_file($path) || (lstat($path)['nlink'] ?? 0) !== 1)) {
            throw new \RuntimeException('Unsafe client editing lock.', 400);
        }
        $mask = umask(0077);
        $lock = @fopen($path, 'c');
        umask($mask);
        if (!$lock || (fstat($lock)['nlink'] ?? 0) !== 1 || !flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            throw new \RuntimeException('Cannot lock client editing configuration.', 500);
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function replace($path, $bytes, $mode)
    {
        $this->safePath($path);
        if (file_exists($path) && (!is_file($path) || (lstat($path)['nlink'] ?? 0) !== 1)) {
            throw new \RuntimeException('Unsafe client editing target.', 400);
        }
        $temporary = dirname($path) . '/.client-editing-' . bin2hex(random_bytes(12)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if (!$stream) { throw new \RuntimeException('Cannot stage approved text.', 500); }
        try {
            chmod($temporary, 0600);
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
                throw new \RuntimeException('Cannot stage approved text.', 500);
            }
            if (function_exists('fsync')) { fsync($stream); }
            fclose($stream); $stream = null;
            chmod($temporary, $mode);
            $this->safePath($path);
            if (!rename($temporary, $path)) { throw new \RuntimeException('Cannot replace approved configuration.', 500); }
        } finally {
            if ($stream) { fclose($stream); }
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
