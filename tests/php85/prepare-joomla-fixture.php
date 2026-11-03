<?php
/**
 * Reproducible test-only native Joomla WAM dependencies; never installs a CMS.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const JOOMLA_FIXTURE_VERSION = '6.2.0';
const JOOMLA_FIXTURE_URL = 'https://github.com/joomla/joomla-cms/releases/download/6.2.0/Joomla_6.2.0-Stable-Full_Package.zip';
const JOOMLA_FIXTURE_SHA256 = 'eaafc68b5990d7b9dd9156656d4c3c3547743d1d7a85682a92e2f9121b58f018';

/** Output belongs in a private test directory, never in a distributable source tree. */
function fixtureDestination(string $path): string
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\')
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
        throw new RuntimeException('Use an absolute fixture path outside the repository.');
    }
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('The private fixture parent could not be created.');
    }
    $parent = realpath($parent);
    if ($parent === false || is_link($path)) { throw new RuntimeException('The fixture path is unavailable or linked.'); }
    $path = $parent . '/' . basename($path);
    $repository = realpath(dirname(__DIR__, 2));
    if ($path === $repository || str_starts_with($path, $repository . '/')) {
        throw new RuntimeException('Keep downloaded/extracted test dependencies outside the repository.');
    }
    return $path;
}

function fixtureRemove(string $directory): void
{
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) { fixtureRemove($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

try {
    if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !class_exists(ZipArchive::class)) {
        throw new RuntimeException('Use PHP 8.5 with ext-zip.');
    }
    $options = getopt('', ['zip:', 'directory:', 'help']);
    if (isset($options['help'])) {
        echo "php tests/php85/prepare-joomla-fixture.php [--zip=/absolute/cached.zip] [--directory=/absolute/private-fixture]\n";
        exit;
    }
    $temporary = realpath(sys_get_temp_dir());
    if ($temporary === false) { throw new RuntimeException('A native temporary directory is required.'); }
    $directoryOption = $options['directory'] ?? $temporary . '/gantry-native-joomla-6.2.0-' . substr(JOOMLA_FIXTURE_SHA256, 0, 12);
    $archiveOption = $options['zip'] ?? $temporary . '/Joomla_6.2.0-Stable-Full_Package.zip';
    if (!is_string($directoryOption) || !is_string($archiveOption)) { throw new RuntimeException('Supply each path option once.'); }
    $directory = fixtureDestination($directoryOption);
    $archive = fixtureDestination($archiveOption);
    if ($archive === $directory || str_starts_with($archive, $directory . '/')) {
        throw new RuntimeException('Keep the cached ZIP outside the extracted fixture directory.');
    }
    $archiveSource = 'cached';
    if (!is_file($archive)) {
        if (file_exists($archive) || !extension_loaded('curl')) { throw new RuntimeException('Downloading the pinned archive requires ext-curl and an unused ZIP path.'); }
        $download = dirname($archive) . '/.joomla-download-' . bin2hex(random_bytes(12));
        $handle = fopen($download, 'xb');
        if ($handle === false) { throw new RuntimeException('The temporary download could not be opened.'); }
        chmod($download, 0600);
        try {
            $curl = curl_init(JOOMLA_FIXTURE_URL);
            curl_setopt_array($curl, [CURLOPT_FILE => $handle, CURLOPT_FAILONERROR => true,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_REDIR_PROTOCOLS_STR => 'https',
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180, CURLOPT_USERAGENT => 'Gantry-native-test-fixture/6.2.0']);
            $success = curl_exec($curl);
            $error = curl_error($curl);
            fclose($handle);
            $handle = null;
            if (!$success || !hash_equals(JOOMLA_FIXTURE_SHA256, (string) hash_file('sha256', $download))) {
                throw new RuntimeException('The pinned Joomla download failed integrity verification. ' . $error);
            }
            if (!rename($download, $archive)) { throw new RuntimeException('The verified archive could not be cached.'); }
            $archiveSource = 'downloaded';
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($download)) { unlink($download); }
        }
    }
    if (!hash_equals(JOOMLA_FIXTURE_SHA256, (string) hash_file('sha256', $archive))) {
        throw new RuntimeException('The cached Joomla ZIP has the wrong SHA-256; it was not replaced or loaded.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('The verified Joomla ZIP could not be opened.'); }
    $entries = [];
    $bytes = 0;
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $record = $zip->statIndex($i);
        if ($record === false) { throw new RuntimeException('The ZIP directory is incomplete.'); }
        $name = $record['name'];
        if (str_ends_with($name, '/')) { continue; }
        if (!str_starts_with($name, 'libraries/vendor/') && !str_starts_with($name, 'libraries/src/')
            && $name !== 'media/system/css/joomla-fontawesome.min.css' && $name !== 'LICENSE.txt') { continue; }
        if ($name[0] === '/' || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name)) {
            throw new RuntimeException('The selected ZIP path is unsafe.');
        }
        $zip->getExternalAttributesIndex($i, $system, $attributes);
        if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
            throw new RuntimeException('Linked native fixture entries are unsupported.');
        }
        $bytes += $record['size'];
        if ($record['size'] > 33554432 || $bytes > 268435456 || count($entries) >= 10000 || isset($entries[$name])) {
            throw new RuntimeException('The native fixture exceeds its bounded extraction limits.');
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || strlen($content) !== $record['size']) { throw new RuntimeException('A selected fixture file is incomplete.'); }
        $entries[$name] = ['bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'index' => $i];
    }
    foreach (['libraries/vendor/autoload.php', 'libraries/src/WebAsset/WebAssetManager.php', 'libraries/src/Event/CoreEventAware.php',
        'libraries/src/Access/Access.php', 'libraries/src/Factory.php', 'libraries/src/Version.php',
        'media/system/css/joomla-fontawesome.min.css', 'LICENSE.txt'] as $required) {
        if (!isset($entries[$required])) { throw new RuntimeException('The pinned archive lacks a required native fixture file.'); }
    }
    $manifest = ['schema' => 1, 'joomla' => JOOMLA_FIXTURE_VERSION, 'url' => JOOMLA_FIXTURE_URL,
        'archive_sha256' => JOOMLA_FIXTURE_SHA256, 'files' => array_map(static fn (array $entry): array => array_diff_key($entry, ['index' => true]), $entries)];
    if (is_dir($directory)) {
        $marker = $directory . '/fixture.json';
        if (!is_file($marker) || is_link($marker)
            || json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR) !== $manifest) {
            throw new RuntimeException('An existing fixture has no matching provenance; it was not changed or loaded.');
        }
        foreach ($entries as $name => $entry) {
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('The extracted fixture differs from the verified archive; it was not loaded.');
            }
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink() || (!$file->isDir() && !$file->isFile())) { throw new RuntimeException('The cached fixture contains a linked or special entry.'); }
            if ($file->isFile()) { $actual[substr($file->getPathname(), strlen($directory) + 1)] = true; }
        }
        if (count($actual) !== count($entries) + 1 || array_diff_key($actual, $entries, ['fixture.json' => true])) {
            throw new RuntimeException('The cached fixture contains unverified files; it was not loaded.');
        }
    } else {
        if (file_exists($directory)) { throw new RuntimeException('The fixture destination is already in use.'); }
        $staging = dirname($directory) . '/.joomla-fixture-' . bin2hex(random_bytes(12));
        if (!mkdir($staging, 0700)) { throw new RuntimeException('The private extraction could not be staged.'); }
        try {
            foreach ($entries as $name => $entry) {
                $path = $staging . '/' . $name;
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                    throw new RuntimeException('A native fixture directory could not be created.');
                }
                $content = $zip->getFromIndex($entry['index']);
                if (!is_string($content) || file_put_contents($path, $content, LOCK_EX) !== $entry['bytes'] || !chmod($path, 0600)
                    || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) { throw new RuntimeException('A native fixture file could not be verified after extraction.'); }
            }
            if (file_put_contents($staging . '/fixture.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n") === false
                || !chmod($staging . '/fixture.json', 0600) || !rename($staging, $directory)) { throw new RuntimeException('The verified native fixture could not be activated.'); }
        } finally { if (is_dir($staging)) { fixtureRemove($staging); } }
    }
    $zip->close();
    echo json_encode(['joomla' => JOOMLA_FIXTURE_VERSION, 'archive_source' => $archiveSource, 'archive_sha256' => JOOMLA_FIXTURE_SHA256,
        'fixture' => $directory, 'vendor_autoload' => $directory . '/libraries/vendor/autoload.php',
        'cms_source' => $directory . '/libraries/src', 'verified_files' => count($entries)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Native Joomla fixture preparation failed: ' . $error->getMessage() . "\n");
    exit(1);
}
