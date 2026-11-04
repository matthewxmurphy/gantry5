<?php
/**
 * Stable native Format2 read identities, without a CMS, vendor installation or writes.
 * php tests/php85/layout-identity.php
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Layout\Version\Format2;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) { throw new RuntimeException('Use actual PHP 8.5 CLI.'); }
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Layout/Version/Format2.php';
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
// A WordPress random helper must never be needed to read a Gantry 6 document.
function wp_rand(): never { throw new RuntimeException('Reading a layout invoked random identity allocation.'); }

if (($argv[1] ?? '') === '--read') {
    mt_srand((int) $argv[3]);
    $data = json_decode(file_get_contents($argv[2]), true, 64, JSON_THROW_ON_ERROR);
    echo json_encode((new Format2($data))->load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}

function layoutIds(array $loaded): array
{
    unset($loaded['preset']);
    $ids = [];
    $walk = static function (array $items) use (&$walk, &$ids): void {
        foreach ($items as $item) {
            $ids[] = $item->id;
            if (isset($item->children)) { $walk($item->children); }
        }
    };
    $walk($loaded);
    return $ids;
}

$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$document = [
    'version' => 2, 'preset' => ['name' => 'default'],
    'layout' => [
        '/header/' => [[['copyright-1774'], ['logo-1000']]],
        'main' => ['system-content-44', 'position-sidebar', 'spacer-0'],
        'footer' => ['copyright-1774'],
    ],
    'structure' => ['header' => ['attributes' => ['class' => 'owned-header']]],
    'content' => ['copyright-1774' => ['attributes' => ['owner' => 'JoomlaMafia — café 😀']], 'logo-1000' => ['attributes' => ['image' => 'gantry-theme://images/logo.png']]],
];
$fixture = tempnam(sys_get_temp_dir(), 'g6-layout-identity-');
try {
    file_put_contents($fixture, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $before = [file_get_contents($fixture), filemtime($fixture), hash_file('sha256', $fixture)];
    $reader = new Format2($document);
    $first = $reader->load();
    $encoded = json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $expect($encoded === json_encode($reader->load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'same native reader resets allocation on each load');
    $expect($encoded === json_encode((new Format2($document))->load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'independent native readers return the exact same document');
    $ids = layoutIds($first);
    $expect(count($ids) === count(array_unique($ids)), 'implicit and repeated-reference identities are globally unique');
    foreach (['header', 'main', 'footer', 'copyright-1774', 'logo-1000', 'system-content-44', 'position-sidebar', 'spacer-0'] as $id) {
        $expect(in_array($id, $ids, true), 'explicit native identity is preserved: ' . $id);
    }
    $expect(str_contains($encoded, 'JoomlaMafia — café 😀'), 'Unicode particle values are preserved');

    $edited = $document;
    $edited['content']['copyright-1774']['attributes']['owner'] = 'JoomlaMafia edited wording';
    $edited['structure']['header']['attributes']['class'] = 'owned-edited';
    $expect(layoutIds((new Format2($edited))->load()) === $ids, 'attribute-only edits never renumber structural or particle nodes');

    foreach ([17, 99991] as $seed) {
        $process = proc_open([PHP_BINARY, __FILE__, '--read', $fixture, (string) $seed], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Could not run the independent PHP reader.'); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        $expect($status === 0 && $errors === '', 'fresh PHP reader completes without warnings or random calls');
        $expect($output === $encoded, 'fresh PHP reader has identical identities regardless of process/RNG state');
    }

    $collision = $document;
    $collision['layout']['grid-1'] = [];
    $collision['layout']['block-1'] = [];
    $collision['layout']['footer'][] = 'logo-2000';
    $collision['content']['logo-2000'] = ['id' => 'grid-2'];
    $collisionIds = layoutIds((new Format2($collision))->load());
    $expect(count($collisionIds) === count(array_unique($collisionIds)), 'later explicit grid/block/metadata IDs cannot collide with earlier implicit nodes');
    foreach (['grid-1', 'block-1', 'grid-2'] as $id) { $expect(in_array($id, $collisionIds, true), 'later explicit identity survives allocation: ' . $id); }
    $expect($collisionIds === layoutIds((new Format2($collision))->load()), 'collision avoidance is deterministic');

    $largeNamespace = $document;
    for ($i = 1; $i <= 10000; ++$i) { $largeNamespace['structure']['grid-' . $i] = []; }
    $largeIds = layoutIds((new Format2($largeNamespace))->load());
    $expect(count($largeIds) === count(array_unique($largeIds)), 'an exhausted four-digit namespace remains collision free');
    $expect(in_array('grid-10001', $largeIds, true), 'generated identity space is not limited to four decimal digits');
    foreach ($largeIds as $id) { $expect(preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_-]{0,127}\z/D', $id) === 1, 'native IDs stay compatible with API/editor identifiers'); }

    $nodes = $first; unset($nodes['preset']);
    $stored = (new Format2())->store($first['preset'], $nodes);
    $saved = (new Format2($stored))->load();
    $expect(layoutIds($saved) === $ids, 'native canonical save/load preserves the exposed node identities');
    $expect(json_encode((new Format2($stored))->load(), JSON_THROW_ON_ERROR) === json_encode($saved, JSON_THROW_ON_ERROR), 'saved canonical document reads exactly the same twice');
    $expect($stored === (new Format2())->store($first['preset'], $nodes), 'canonical storage needs no random identity fallback');
    clearstatcache(true, $fixture);
    $expect($before === [file_get_contents($fixture), filemtime($fixture), hash_file('sha256', $fixture)], 'all native/process reads preserve exact source bytes and mtime');
    echo 'PASS ' . $checks . ' stable native layout identity checks on PHP ' . PHP_VERSION . "\n";
} finally {
    unlink($fixture);
    restore_error_handler();
}
