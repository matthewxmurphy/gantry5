<?php
/**
 * Explicit public-source boundary: readable filesystem data is not public data.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Gantry\Framework\Gantry;

final class PublicSources
{
    private array $roots = [];

    public function __construct(private readonly Gantry $gantry, private readonly string $siteRoot, private readonly string $sitePrefix = '')
    {
        $streams = [
            'style' => ['gantry-assets://css', 'gantry-engine://css-compiled', 'gantry-theme://css', 'gantry-theme://css-compiled'],
            'script' => ['gantry-assets://js', 'gantry-engine://js', 'gantry-theme://js'],
            'image' => ['gantry-assets://images', 'gantry-engine://images', 'gantry-theme://images'],
            'font' => ['gantry-assets://fonts', 'gantry-assets://webfonts', 'gantry-engine://fonts', 'gantry-theme://fonts', 'gantry-theme://webfonts'],
        ];
        foreach ($streams as $kind => $uris) {
            $this->roots[$kind] = [];
            foreach ($uris as $uri) {
                foreach ((array) $gantry['locator']->findResources($uri) as $path) {
                    $path = is_string($path) ? realpath($path) : false;
                    if ($path && str_starts_with($path, $siteRoot . '/') && is_dir($path)) { $this->roots[$kind][] = $path; }
                }
            }
        }
        if (is_dir($siteRoot . '/images')) { $this->roots['image'][] = $siteRoot . '/images'; }
    }

    public function resolve(string $uri, string $kind): ?array
    {
        $parts = parse_url($uri);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) { return null; }
        if (isset($parts['query']) && !preg_match('/\A(?:[a-f0-9]+|(?:v|version)=[a-zA-Z0-9_.-]+)\z/D', $parts['query'])) { return null; }
        if (str_starts_with($uri, 'gantry-')) {
            try { $path = $this->gantry['locator']->findResource(preg_replace('/[?#].*/', '', $uri), true); }
            catch (\InvalidArgumentException) { return null; }
        } elseif (empty($parts['scheme']) && empty($parts['host'])) {
            $relative = (string) ($parts['path'] ?? '');
            if ($this->sitePrefix !== '' && str_starts_with($relative, $this->sitePrefix . '/')) { $relative = substr($relative, strlen($this->sitePrefix)); }
            $path = $this->siteRoot . '/' . ltrim(rawurldecode($relative), '/');
        } else { return null; }
        $original = is_string($path) ? $path : '';
        if ($original !== '' && $this->hasSymlink($original)) { return null; }
        $path = $original !== '' ? realpath($original) : false;
        if (!$path || !$this->allowed($path, $kind, (string) ($parts['query'] ?? ''))) { return null; }
        $url = $this->sitePrefix . '/' . ltrim(substr($path, strlen($this->siteRoot)), '/');
        return [$path, str_replace(' ', '%20', $url)];
    }

    public function allowed(string $path, string $kind, string $query = ''): bool
    {
        if ($this->hasSymlink($path)) { return false; }
        $resolved = realpath($path);
        if (!$resolved || !str_starts_with($resolved, $this->siteRoot . '/') || !is_file($resolved)) { return false; }
        $relative = substr($resolved, strlen($this->siteRoot) + 1);
        $extensions = match ($kind) {
            'style' => ['css'], 'script' => ['js'],
            'image' => ['png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg'],
            'font' => ['woff', 'woff2', 'ttf', 'eot', 'otf'],
            'dependency' => ['css', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'otf'],
            default => [],
        };
        if (!in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), $extensions, true)) { return false; }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/\A(?:administrator|private|protected|logs?|tmp|temp|cache|backups?|credentials|secrets?)\z/iD', $part)) { return false; }
        }
        $kinds = $kind === 'dependency' ? ['style', 'image', 'font'] : [$kind];
        $allowed = false;
        foreach ($kinds as $candidate) {
            foreach ($this->roots[$candidate] ?? [] as $root) { if (str_starts_with($resolved, $root . '/')) { $allowed = true; } }
        }
        if (!$allowed) { return false; }
        $cursor = $this->siteRoot;
        $directories = explode('/', dirname($relative));
        foreach (array_merge([''], $directories) as $part) {
            if ($part !== '' && $part !== '.') { $cursor .= '/' . $part; }
            if (is_link($cursor) || is_file($cursor . '/web.config')) { return false; }
            $access = $cursor . '/.htaccess';
            if (is_link($access) || (is_file($access) && !$this->accessAllows($access, $resolved, $query))) { return false; }
        }
        return true;
    }

    /** Resolve a CSS dependency through the same CMS-prefix and public policy. */
    public function dependency(string $uri, string $source): ?array
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) { return null; }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === '') { return null; }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim(substr(dirname($source), strlen($this->siteRoot)), '/') . '/' . $path;
        }
        return $this->resolve($path . (isset($parts['query']) ? '?' . $parts['query'] : ''), 'dependency');
    }

    private function hasSymlink(string $path): bool
    {
        $cursor = '';
        foreach (explode('/', ltrim($path, '/')) as $part) { $cursor .= '/' . $part; if (is_link($cursor)) { return true; } }
        return false;
    }

    private function accessAllows(string $file, string $source, string $query): bool
    {
        if (filesize($file) > 262144) { return false; }
        $rules = file_get_contents($file);
        if (!is_string($rules)) { return false; }
        $rules = preg_replace('/^\s*#.*$/m', '', $rules);
        $rules = preg_replace_callback('/<FilesMatch\s+["\']?([^>]*?)["\']?>[\s\S]*?<\/FilesMatch>/i', static function (array $match) use ($source): string {
            $pattern = trim($match[1], " \t\r\n\"'");
            return self::regex($pattern, basename($source), true) === 0 ? '' : $match[0];
        }, $rules);
        $rules = preg_replace_callback('/<Files\s+["\']?([^>]*?)["\']?>[\s\S]*?<\/Files>/i', static function (array $match) use ($source): string {
            return fnmatch(trim($match[1], " \t\r\n\"'"), basename($source)) ? $match[0] : '';
        }, $rules);
        if (preg_match('/\b(?:AuthType|AuthName|AuthUserFile|Order\s+|Allow\s+from|Deny\s+from|Redirect(?:Match)?\s+|Require\s+(?!all\s+granted\b))/i', $rules)) { return false; }
        $conditions = [];
        foreach (preg_split('/\R/', $rules) as $line) {
            if (preg_match('/^\s*RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^]]+)\])?/i', $line, $match)) { $conditions[] = $match; continue; }
            if (!preg_match('/^\s*RewriteRule\s+(\S+)\s+\S+(?:\s+\[([^]]+)\])?/i', $line, $match)) { continue; }
            if (preg_match('/(?:^|,)\s*(?:F|G|R=(?:40[134]|410))(?:,|$)/i', $match[2] ?? '')) {
                $relative = ltrim(substr($source, strlen(dirname($file))), '/');
                if (self::regex($match[1], $relative, self::flag($match[2] ?? '', 'NC')) !== 0 && !$this->conditionsFalse($conditions, $source, $relative, $query)) { return false; }
            }
            $conditions = [];
        }
        return true;
    }

    /** Unknown conditional denials are conservative; known false query guards are safe. */
    private function conditionsFalse(array $conditions, string $source, string $relative, string $query): bool
    {
        $group = false;
        foreach ($conditions as $condition) {
            $subject = match (strtoupper($condition[1])) { '%{QUERY_STRING}' => $query, '%{REQUEST_URI}' => $this->sitePrefix . '/' . ltrim(substr($source, strlen($this->siteRoot)), '/'), '%{REQUEST_FILENAME}' => $source, default => null };
            $pattern = $condition[2];
            $negate = str_starts_with($pattern, '!');
            $pattern = $negate ? substr($pattern, 1) : $pattern;
            $value = $subject === null ? null : match (true) { $pattern === '-f' => is_file($subject), $pattern === '-d' => is_dir($subject), preg_match('/\A[-=<>]/', $pattern) === 1 => null, default => self::regex($pattern, $subject, self::flag($condition[3] ?? '', 'NC')) };
            $value = $value === null || $value === false ? null : ($negate ? !$value : (bool) $value);
            $group = $group || $value !== false;
            if (!self::flag($condition[3] ?? '', 'OR')) { if (!$group) { return true; } $group = false; }
        }
        return false;
    }
    private static function flag(string $flags, string $name): bool
    {
        return in_array($name, array_map(static fn (string $flag): string => strtoupper(trim($flag)), explode(',', $flags)), true);
    }

    private static function regex(string $pattern, string $subject, bool $nocase): int|false
    {
        // Invalid server patterns are unknown policies, never PHP diagnostics.
        set_error_handler(static fn (): bool => true);
        try { return preg_match('~' . str_replace('~', '\\~', $pattern) . '~' . ($nocase ? 'i' : ''), $subject); }
        finally { restore_error_handler(); }
    }

}
