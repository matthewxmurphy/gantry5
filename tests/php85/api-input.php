<?php
/**
 * Core API request-boundary regressions, run with: php tests/php85/api-input.php
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Api\ApiException;
use Gantry\Component\Api\Input;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
    throw new RuntimeException('Run the Gantry 6 core API regressions on PHP 8.5 CLI.');
}
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Api/ApiException.php';
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Api/Input.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$reject = static function (callable $operation, string $code, string $label) use ($expect): void {
    try {
        $operation();
    } catch (ApiException $error) {
        $expect($error->getErrorCode() === $code && in_array($error->getCode(), [400, 422], true), $label);
        return;
    }
    throw new RuntimeException('Failed to reject: ' . $label);
};
try {
    foreach (['default', '_offline', '20', 'g6_staging', 'g6-staging'] as $id) {
        $expect(Input::id($id) === $id, 'native identifier accepted: ' . $id);
    }
    foreach (['../default', 'a/b', 'a\\b', 'gantry-config://default', '%2e%2e', 'a.yaml', '', str_repeat('a', 129), 20, null] as $id) {
        $reject(static fn () => Input::id($id), 'invalid_identifier', 'path-like or malformed identifier rejected');
    }
    foreach (['G601 API Acceptance', 'Café — © 2026', 'Owner & client'] as $title) {
        $expect(Input::title($title) === $title, 'plain UTF-8 title retained exactly');
    }
    foreach (['<script>alert(1)</script>', "newline\ntitle", ' trailing ', '', str_repeat('a', 161), "\xff"] as $title) {
        $reject(static fn () => Input::title($title), 'invalid_title', 'unsafe or malformed title rejected');
    }
    $revision = hash('sha256', 'current native configuration');
    $expect(Input::revision($revision) === $revision, 'current revision round trips exactly');
    foreach (['', 'unknown', strtoupper($revision), null, 42, str_repeat('a', 63), str_repeat('a', 65)] as $candidate) {
        $reject(static fn () => Input::revision($candidate), 'revision_required', 'missing or malformed optimistic revision rejected');
    }
    $preset = ['name' => 'default', 'timestamp' => 123456, 'image' => 'gantry-admin://images/layouts/default.png'];
    Input::preset(['image' => $preset['image'], 'timestamp' => $preset['timestamp'], 'name' => $preset['name']], $preset);
    $expect(true, 'identical native preset JSON metadata accepts reordered object keys');
    $expect(array_keys($preset) === ['name', 'timestamp', 'image'], 'preset validation preserves caller property order');
    foreach ([array_replace($preset, ['timestamp' => '123456']), array_replace($preset, ['name' => 'other']), $preset + ['filename' => 'other.yaml'], ['name' => 'default'], null] as $candidate) {
        $reject(static fn () => Input::preset($candidate, $preset), 'invalid_preset', 'preset metadata values and types remain strict');
    }
    $reject(static fn () => Input::shape(['title' => 'Good', 'filename' => '/etc/passwd'], ['title'], ['title']), 'invalid_fields', 'unknown file field rejected');
    $reject(static fn () => Input::shape([], ['title'], ['title']), 'invalid_fields', 'required field rejected');
    $reject(static fn () => Input::shape(['title' => str_repeat('a', Input::MAX_BYTES)], ['title']), 'request_too_large', 'oversized request rejected before writing');
    $reject(static fn () => Input::shape(['title' => "\xff"], ['title']), 'invalid_json', 'malformed UTF-8 request rejected');

    $raw = ['accent' => ['color-1' => '#123456', 'color-2' => '#abcdef'], 'font' => ['family' => 'Inter'], 'list' => ['one', 'two']];
    $merged = Input::merge($raw, ['accent' => ['color-1' => '#ee4b2b'], 'list' => ['three']]);
    $expect($merged === ['accent' => ['color-1' => '#ee4b2b', 'color-2' => '#abcdef'], 'font' => ['family' => 'Inter'], 'list' => ['three']], 'PATCH preserves unrelated raw settings and replaces a JSON list');
    $expect($raw['accent']['color-1'] === '#123456', 'PATCH leaves its input unchanged');
    foreach (['#ee4b2b', 'Inter, sans-serif', '1.25rem', '100%', 'https://example.test/logo.png', 'gantry-media://logo.png', 'Copyright © 2026'] as $value) {
        Input::safeValue($value, true);
        $expect(true, 'safe style or content scalar accepted');
    }
    foreach (['{{ include("x") }}', '{% include "x" %}', '<script>x</script>', 'javascript:alert(1)', "java\tscript:alert(1)", "java\nscript:alert(1)", "java\rscript:alert(1)", 'data:text/html,x', 'php://filter', 'phar://x', '../private', '..\\private'] as $value) {
        $reject(static fn () => Input::safeValue($value), 'unsafe_value', 'executable or traversal value rejected');
    }
    foreach (['red; @import "x"', '$other', 'url(https://example.test/x)', 'expression(alert(1))', 'red} body{color:blue'] as $value) {
        $reject(static fn () => Input::safeValue($value, true), 'unsafe_value', 'SCSS/CSS execution boundary rejected');
    }
    foreach ([new stdClass(), INF, NAN, ['../x' => 'value']] as $value) {
        $reject(static fn () => Input::data($value), 'invalid_data', 'non-JSON or path-key data rejected');
    }
    $deep = 'x';
    for ($i = 0; $i < Input::MAX_DEPTH + 1; ++$i) { $deep = ['value' => $deep]; }
    $reject(static fn () => Input::data($deep), 'invalid_data', 'excessive recursive settings rejected');

    $layout = [[
        'id' => 'footer', 'type' => 'section', 'subtype' => 'section', 'title' => 'Footer', 'attributes' => ['class' => 'g601-api'], 'layout' => true,
        'children' => [[
            'id' => 'grid-1', 'type' => 'grid', 'subtype' => 'grid', 'attributes' => [], 'layout' => true,
            'children' => [[
                'id' => 'block-1', 'type' => 'block', 'subtype' => 'block', 'attributes' => ['size' => 100], 'layout' => true,
                'children' => [['id' => 'copyright-1', 'type' => 'particle', 'subtype' => 'copyright', 'title' => 'Copyright', 'attributes' => ['enabled' => 1, 'owner' => 'G601 API Acceptance']]],
            ]],
        ]],
    ]];
    $expect(Input::layout($layout) === $layout, 'real editor layout structure with copyright particle retains exact data');
    $native = $layout;
    $native[0]['subtype'] = 'footer';
    $native[0]['children'][0]['children'][0]['children'][] = ['id' => 'spacer-2', 'type' => 'spacer', 'subtype' => 'spacer', 'attributes' => ['enabled' => 1]];
    $native[] = ['id' => 'header', 'type' => 'section', 'subtype' => 'header', 'attributes' => []];
    $native[] = ['id' => 'main', 'type' => 'section', 'subtype' => 'main', 'attributes' => [], 'children' => [
        ['id' => 'system-messages-2', 'type' => 'system', 'subtype' => 'messages', 'attributes' => ['enabled' => 1]],
        ['id' => 'system-content-2', 'type' => 'system', 'subtype' => 'content', 'attributes' => ['enabled' => 1]],
        ['id' => 'position-footer', 'type' => 'position', 'subtype' => 'position', 'attributes' => ['key' => 'footer', 'enabled' => 1]],
    ]];
    $expect(Input::layout($native) === $native, 'native header/main/footer, spacer, system and position node envelopes are accepted unchanged');
    $expect(Input::layout([]) === [], 'intentional empty layout accepted');
    $reject(static fn () => Input::layout(['layout' => $layout]), 'invalid_layout', 'serialized YAML envelope is not an editor node list');
    $bad = $layout;
    $bad[] = $layout[0];
    $reject(static fn () => Input::layout($bad), 'invalid_layout', 'duplicate layout IDs rejected');
    $bad = $layout;
    $bad[0]['filename'] = 'layout.yaml';
    $reject(static fn () => Input::layout($bad), 'invalid_fields', 'unknown node property rejected');
    $bad = $layout;
    $bad[0]['id'] = '../default';
    $reject(static fn () => Input::layout($bad), 'invalid_identifier', 'layout node traversal rejected');
    $bad = $layout;
    $bad[0]['type'] = 'atoms';
    $reject(static fn () => Input::layout($bad), 'invalid_layout', 'atom/code-write node rejected');
    $bad = $layout;
    $bad[0]['inherit'] = ['outline' => '20', 'include' => ['filename']];
    $reject(static fn () => Input::layout($bad), 'invalid_layout', 'arbitrary inheritance selector rejected');
    $bad[0]['inherit']['include'] = [['attributes']];
    $reject(static fn () => Input::layout($bad), 'invalid_layout', 'non-scalar inheritance selectors rejected without PHP warnings');
    $bad = $layout;
    $bad[0]['children'][0]['children'][0]['children'][0]['children'] = [];
    $reject(static fn () => Input::layout($bad), 'invalid_layout', 'particle children rejected');
    $many = [];
    for ($i = 0; $i <= Input::MAX_NODES; ++$i) {
        $many[] = ['id' => 'section-' . $i, 'type' => 'section', 'subtype' => 'section', 'attributes' => []];
    }
    $reject(static fn () => Input::layout($many), 'invalid_layout', 'node-count limit enforced');
    echo 'PASS ' . $checks . ' core API input regression checks on PHP ' . PHP_VERSION . "\n";
} finally {
    restore_error_handler();
}
