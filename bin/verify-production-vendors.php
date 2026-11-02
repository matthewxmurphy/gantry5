<?php
/**
 * Reject development dependencies before a production package copies its vendors.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

final class GantryProductionVendors
{
    /** Read Composer 2 metadata only; never load the dependency autoloader. */
    public static function verify(string $folder): int
    {
        $reject = static function (string $reason) use ($folder): never {
            throw new RuntimeException("Production vendors rejected in {$folder}: {$reason}. Run composer install --no-dev --prefer-dist --no-interaction in this isolated build folder before packaging; keep development vendors in the test checkout.");
        };
        $json = static function (string $path) use ($reject): array {
            if (!is_file($path)) { $reject('missing Composer metadata ' . basename($path)); }
            try { $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable $error) { $reject('invalid Composer metadata ' . basename($path)); }
            if (!is_array($data)) { $reject('incomplete Composer metadata ' . basename($path)); }
            return $data;
        };
        $lock = $json($folder . '/composer.lock');
        if (!is_array($lock['packages'] ?? null) || !is_array($lock['packages-dev'] ?? null)) {
            $reject('incomplete locked production/development package lists');
        }
        $names = static function (array $packages) use ($reject): array {
            $result = [];
            foreach ($packages as $package) {
                $name = $package['name'] ?? null;
                if (!is_string($name) || !preg_match('~\A[a-z0-9_.-]+/[a-z0-9_.-]+\z~D', $name)) { $reject('invalid locked package name'); }
                $result[$name] = true;
            }
            return $result;
        };
        $required = $names($lock['packages']);
        $development = array_diff_key($names($lock['packages-dev']), $required);
        $virtual = [];
        foreach ($lock['packages'] as $package) {
            foreach (['provide', 'replace'] as $kind) {
                foreach (array_keys($package[$kind] ?? []) as $name) { $virtual[$name] = true; }
            }
        }
        $vendor = $folder . '/vendor';
        $installed = $vendor . '/composer/installed.php';
        if (!is_file($installed)) { $reject('missing Composer 2 installed.php'); }
        try { $metadata = (static fn (string $path): mixed => require $path)($installed); }
        catch (Throwable $error) { $reject('invalid Composer 2 installed.php'); }
        if (!is_array($metadata) || ($metadata['root']['dev'] ?? null) !== false || !is_array($metadata['versions'] ?? null)) {
            $reject('Composer metadata is not a --no-dev installation');
        }
        $rootName = $metadata['root']['name'] ?? null;
        $actual = [];
        foreach ($metadata['versions'] as $name => $package) {
            if (!is_array($package) || ($package['dev_requirement'] ?? null) !== false) { $reject('development package metadata for ' . $name); }
            if ($name === $rootName) { continue; }
            // Composer records provided/replaced virtual packages in versions,
            // even though no corresponding package is physically installed.
            if (!isset($required[$name]) && isset($virtual[$name]) && !isset($package['install_path'])
                && (is_array($package['provided'] ?? null) || is_array($package['replaced'] ?? null))) { continue; }
            if (isset($development[$name]) || !isset($required[$name])) { $reject('unlocked or development package ' . $name); }
            $actual[$name] = true;
        }
        if (array_diff_key($required, $actual)) { $reject('missing locked runtime packages: ' . implode(', ', array_keys(array_diff_key($required, $actual)))); }
        if (is_file($vendor . '/composer/installed.json')) {
            $data = $json($vendor . '/composer/installed.json');
            if (($data['dev'] ?? null) !== false || !is_array($data['packages'] ?? null)) { $reject('installed.json is not a --no-dev installation'); }
            $listed = $names($data['packages']);
            if (array_diff_key($listed, $required) || array_diff_key($required, $listed)) { $reject('installed.json does not match the locked runtime packages'); }
        }
        // Composer metadata can be stale after copying a vendor directory. Check
        // both surviving package directories and the actual generated maps: the
        // optimized autoloader uses autoload_static.php, not autoload_files.php.
        foreach ($development as $name => $_) {
            if (file_exists($vendor . '/' . $name) || is_link($vendor . '/' . $name)) { $reject('leftover development package ' . $name); }
        }
        foreach (glob($vendor . '/composer/autoload_*.php') ?: [] as $path) {
            $contents = file_get_contents($path);
            foreach ($development as $name => $_) {
                if (preg_match('~(?:/|\\\\)' . preg_quote($name, '~') . '(?:/|\\\\|[\'\"])~', $contents)) {
                    $reject('development reference in ' . basename($path) . ': ' . $name);
                }
            }
        }
        if (!is_file($vendor . '/autoload.php') || !is_file($vendor . '/composer/autoload_static.php')) { $reject('missing generated runtime autoloader'); }
        return count($actual);
    }

    /** Only selected package targets need admission; dev test trees stay intact. */
    public static function folders(string $root, array $arguments): array
    {
        $targets = array_values(array_filter($arguments, static fn (string $arg): bool => !str_starts_with($arg, '-')));
        if (!$targets) { $targets = ['prod']; }
        $platforms = [];
        foreach ($targets as $target) {
            if ($target === 'prod') { $platforms += ['joomla' => true, 'wordpress' => true, 'grav' => true]; }
            elseif (!str_ends_with($target, '-dev') && $target !== 'dev') {
                foreach (['joomla', 'wordpress', 'grav'] as $platform) {
                    if (str_contains($target, $platform)) { $platforms[$platform] = true; }
                }
            }
        }
        $paths = ['joomla' => '/platforms/joomla/lib_gantry5', 'wordpress' => '/platforms/wordpress/gantry5', 'grav' => '/platforms/grav/gantry5'];
        $folders = [];
        foreach (array_keys($platforms) as $platform) {
            $folders[] = $root . $paths[$platform];
            $folders[] = $root . $paths[$platform] . '/compat';
        }
        return $folders;
    }

    /** Resolve the same source root Phing will use, before it can copy vendors. */
    public static function sourceRoot(string $root, array $arguments): string
    {
        if (!self::folders($root, $arguments)) { return $root; }
        $override = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-Dpath.platforms=')) {
                throw new RuntimeException('Production builds do not accept -Dpath.platforms; use -Dproject_dir so its runtime vendors can be verified.');
            }
            if (str_starts_with($argument, '-Dproject_dir=')) {
                if ($override !== null) { throw new RuntimeException('Production builds require one unambiguous -Dproject_dir.'); }
                $override = substr($argument, strlen('-Dproject_dir='));
            }
        }
        if ($override === null) { return $root; }
        $absolute = str_starts_with($override, '/') || preg_match('~\A[A-Za-z]:[\\\\/]~', $override);
        $resolved = realpath($absolute ? $override : $root . '/bin/builder/' . $override);
        if ($override === '' || $resolved === false || !is_dir($resolved)) { throw new RuntimeException('Production -Dproject_dir must resolve to an existing source directory.'); }
        return $resolved;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli' || count($argv) < 2) { fwrite(STDERR, "Usage: php bin/verify-production-vendors.php /path/to/runtime-package [...]\n"); exit(64); }
    try {
        foreach (array_slice($argv, 1) as $folder) { echo 'PASS production vendors: ' . $folder . ' (' . GantryProductionVendors::verify($folder) . " locked runtime packages)\n"; }
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
