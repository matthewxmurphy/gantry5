<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render;

use Gantry\Framework\Gantry;

/** One immutable generation per request. No source discovery or repair on a page load. */
final class BundleRuntime
{
    private static ?self $pinned = null;
    private static int $sourceDepth = 0;
    private static ?string $failure = null;
    private array $loaded = [];
    private array $checkedParticles = [];

    public function __construct(private readonly string $directory, private readonly array $manifest)
    {
        if (($manifest['schema'] ?? null) !== 1 || !is_string($manifest['id'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $manifest['id'])
            || ($manifest['php'] ?? null) !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            || !is_string($manifest['revision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $manifest['revision'])
            || !is_string($manifest['theme'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $manifest['theme'])
            || ($manifest['framework'] ?? null) !== (defined('GANTRY5_VERSION') ? GANTRY5_VERSION : '6.0.2-rc.1')
            || !is_array($manifest['data'] ?? null) || !is_array($manifest['outlines'] ?? null)) {
            self::fail('The production render bundle is incompatible or incomplete. Rebuild it before serving.');
        }
        foreach ($manifest['data'] as $key => $entry) {
            if (!is_string($key) || !preg_match('/\A[a-z_]+\z/D', $key) || !self::descriptor($entry)) { self::fail('The production artifact map is malformed.'); }
        }
        if (array_key_exists('particle_configs', $manifest) && $manifest['particle_configs'] !== true) {
            self::fail('The production particle configuration capability is invalid.');
        }
        foreach ($manifest['outlines'] as $outline => $kinds) {
            if (!preg_match('/\A[\p{L}\p{N}\p{M}_-]+\z/uD', (string) $outline) || !is_array($kinds)) { self::fail('The production outline map is malformed.'); }
            if (isset($manifest['particle_configs']) && !self::descriptor($kinds['particles'] ?? null)) {
                self::fail('The production particle configuration map is incomplete.');
            }
            foreach ($kinds as $kind => $entry) {
                if (!is_string($kind) || !preg_match('/\A[a-z_]+\z/D', $kind) || !self::descriptor($entry)) { self::fail('The production outline artifact is malformed.'); }
            }
        }
    }

    /** Explicit source work is limited to native administration, API and build scopes. */
    public static function isBuilding(): bool { return self::$sourceDepth > 0; }

    public static function isSource(): bool
    {
        if (self::$sourceDepth > 0) { return true; }
        if (self::$pinned !== null) { return false; }
        if (PHP_SAPI === 'cli' || defined('GANTRYADMIN_PATH')) { return true; }
        if (!defined('GANTRY5_PLATFORM') || GANTRY5_PLATFORM !== 'joomla') { return true; }
        $app = \Joomla\CMS\Factory::getApplication();
        if (!$app->isClient('site')) { return true; }
        // Native site routing can ask Gantry about templates before Joomla has
        // created its document. The default HTML request must already be strict.
        $document = $app->getDocument();
        return ($document ? $document->getType() : $app->getInput()->getCmd('format', 'html')) !== 'html';
    }

    public static function current(?Gantry $gantry = null): ?self
    {
        if (self::isSource()) { return null; }
        if (self::$pinned !== null) { return self::$pinned; }
        $gantry ??= Gantry::instance();
        if (!isset($gantry['theme.name'])) { return null; }
        if (!isset($gantry['render.registry'])) { self::fail('The production render registry is unavailable.'); }
        try { return self::pin($gantry['render.registry']->current()); }
        catch (\Throwable $error) { self::fail('The production render generation requires verified recovery.'); }
    }

    public static function pin(self $runtime): self
    {
        if (self::$pinned !== null && self::$pinned->id() !== $runtime->id()) {
            self::fail('A request cannot mix production render generations.');
        }
        return self::$pinned = $runtime;
    }

    /** Source builders use the existing container under a scoped, exception-safe suspension. */
    public static function source(callable $operation): mixed
    {
        ++self::$sourceDepth;
        try { return $operation(); } finally { --self::$sourceDepth; }
    }

    public static function fail(string $message): never
    {
        self::$failure = $message;
        throw new \RuntimeException($message, 503);
    }

    /** Native optional-block catches must not turn missing artifacts into partial 200 pages. */
    public static function assertHealthy(): void
    {
        if (self::$failure !== null) { throw new \RuntimeException(self::$failure, 503); }
    }

    public function id(): string { return $this->manifest['id']; }
    public function root(): string { return $this->directory; }
    public function theme(): string { return $this->manifest['theme']; }
    public function revision(): string { return $this->manifest['revision']; }

    public function data(string $key): array
    {
        if (!isset($this->manifest['data'][$key])) { self::fail('A required production render artifact is missing.'); }
        return $this->read($this->manifest['data'][$key]);
    }

    public function outlineData(string $outline, string $kind): array
    {
        $entry = $this->manifest['outlines'][$outline][$kind] ?? null;
        if (!is_array($entry)) { self::fail('The selected outline needs a verified production rebuild.'); }
        return $this->read($entry);
    }

    /** A changed/custom runtime input keeps the native frozen-schema merge path. */
    public function particleConfig(string $outline, string $subtype, array $attributes, mixed $defaults): ?array
    {
        if (!($this->manifest['particle_configs'] ?? false)) { return null; }
        try { $key = self::particleKey($subtype, $defaults, $attributes); }
        catch (\Throwable $error) { return null; }
        $entries = $this->outlineData($outline, 'particles');
        if (!isset($this->checkedParticles[$outline])) {
            foreach ($entries as $identity => $value) {
                if (!is_string($identity) || !preg_match('/\A[a-f0-9]{64}\z/D', $identity) || !is_array($value)) { self::fail('The production particle configuration map is invalid.'); }
                $remaining = 100000;
                try { self::particleValue($value, 0, $remaining); }
                catch (\Throwable $error) { self::fail('The production particle configuration contains unsupported values.'); }
            }
            $this->checkedParticles[$outline] = true;
        }
        if (!array_key_exists($key, $entries)) { return null; }
        if (!is_array($entries[$key])) { self::fail('The production particle configuration is invalid.'); }
        return $entries[$key];
    }

    public static function particleKey(string $subtype, mixed $defaults, array $attributes): string
    {
        $remaining = 100000;
        self::particleValue($defaults, 0, $remaining);
        self::particleValue($attributes, 0, $remaining);
        // PHP-native types/order matter to the original blueprint merge rules.
        return hash('sha256', $subtype . "\0" . serialize([$defaults, $attributes]));
    }

    /** Cache value-only data; native merging retains all object identity/behavior. */
    private static function particleValue(mixed $value, int $depth, int &$remaining): void
    {
        if ($depth > 128 || --$remaining < 0) { throw new \LengthException('Particle data exceeds the prepared-value boundary.'); }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null) { throw new \UnexpectedValueException('Referenced particle data requires native merging.'); }
                self::particleValue($item, $depth + 1, $remaining);
            }
        } elseif ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value) && !(is_float($value) && is_finite($value))) {
            throw new \UnexpectedValueException('Particle data requires native merging.');
        }
    }

    /** Frozen YAML values support the few native metadata readers outside config/layout hydration. */
    public function yaml(string $filename): array
    {
        $index = $this->data('yaml');
        $key = str_replace('\\', '/', $filename);
        if (!isset($index[$key]) || !self::descriptor($index[$key])) { self::fail('A source-only YAML value was requested by the compiled renderer.'); }
        return $this->read($index[$key]);
    }

    private function read(array $entry): array
    {
        $file = $entry['file'] ?? '';
        $hash = $entry['sha256'] ?? '';
        if (!self::descriptor($entry)) {
            self::fail('The production artifact reference is invalid.');
        }
        if (isset($this->loaded[$file])) { return $this->loaded[$file]; }
        $path = $this->directory . '/' . $file;
        try { RenderRegistry::safePath($path); } catch (\Throwable $error) { self::fail('The production artifact path is unsafe.'); }
        if (!is_file($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) {
            self::fail('A production artifact is missing or corrupt. Rebuild it before serving.');
        }
        try { $data = (static fn (string $path): mixed => require $path)($path); }
        catch (\Throwable $error) { self::fail('The production artifact could not be loaded.'); }
        if (!is_array($data)) { self::fail('The production artifact has an invalid payload.'); }
        return $this->loaded[$file] = $data;
    }

    private static function descriptor(mixed $entry): bool
    {
        return is_array($entry) && is_string($entry['file'] ?? null)
            && (bool) preg_match('~\A[a-z0-9_/-]+\.php\z~D', $entry['file'])
            && !str_contains($entry['file'], '..') && !str_starts_with($entry['file'], '/')
            && is_string($entry['sha256'] ?? null) && (bool) preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']);
    }
}
