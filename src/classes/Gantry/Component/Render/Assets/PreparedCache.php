<?php
/**
 * Verified build-time reuse of immutable public files; never read by frontend.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

final class PreparedCache
{
    private const GUARD = "<?php exit; ?>\n";
    private readonly string $directory;

    public function __construct(private readonly string $publicDirectory, private readonly string $publicUrl)
    {
        $this->directory = $publicDirectory . '/.prepared';
        if (is_link($this->directory)) { throw new \RuntimeException('Linked prepared-asset metadata is unsupported.'); }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700) && !is_dir($this->directory)) { throw new \RuntimeException('Prepared-asset metadata is unavailable.'); }
        chmod($this->directory, 0700);
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (is_link($path) || !is_file($path)) { return null; }
        try {
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || !str_starts_with($bytes, self::GUARD)) { return null; }
            $value = json_decode(substr($bytes, strlen(self::GUARD)), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !$this->verify($value)) { return null; }
            return $value;
        } catch (\JsonException) { return null; }
    }

    public function set(string $key, array $value): void
    {
        if (!$this->verify($value)) { throw new \RuntimeException('Prepared render assets could not be verified.'); }
        $path = $this->path($key);
        if (is_link($path)) { throw new \RuntimeException('Linked prepared-asset metadata is unsupported.'); }
        $bytes = self::GUARD . json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
        $temporary = tempnam($this->directory, '.prepare-');
        try {
            if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $path)) { throw new \RuntimeException('Prepared-asset metadata could not be saved.'); }
        } finally { if (is_string($temporary) && is_file($temporary)) { unlink($temporary); } }
    }

    private function path(string $key): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $key)) { throw new \InvalidArgumentException('Invalid prepared-asset key.'); }
        return "$this->directory/$key.php";
    }

    private function verify(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) { if (!$this->verify($item)) { return false; } continue; }
            if (!in_array($key, ['url', 'fallback', 'path'], true)) { continue; }
            if (!is_string($item)) { return false; }
            $prefix = $key === 'path' ? $this->publicDirectory . '/' : rtrim($this->publicUrl, '/') . '/';
            if (!str_starts_with($item, $prefix)) { return false; }
            $name = substr($item, strlen($prefix));
            if ($key !== 'path' && str_contains($name, '?')) {
                [$name, $query] = explode('?', $name, 2);
                if (!preg_match('/\Ag6=[a-f0-9]{16}\z/D', $query)) { return false; }
            }
            if (!preg_match('/\A([a-f0-9]{64})\.[a-z0-9]+\z/D', $name, $match)) { return false; }
            $path = $this->publicDirectory . '/' . $name;
            if (is_link($path) || !is_file($path) || !hash_equals($match[1], hash_file('sha256', $path))) { return false; }
        }
        return true;
    }
}
