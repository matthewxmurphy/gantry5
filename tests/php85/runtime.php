<?php
/**
 * Runtime regressions for PHP 8.5 cURL handles and Unicode attribute escaping.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 * Run: php tests/php85/runtime.php. No CMS, network or vendor installation needed.
 */
use Gantry\Component\Content\Document\HtmlDocument;
use Gantry\Component\Remote\Response;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !extension_loaded('curl')) {
    throw new RuntimeException('Use actual PHP 8.5 CLI with cURL.');
}
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Gantry/GantryTrait.php';
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Content/Document/HtmlDocument.php';
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Remote/Response.php';

error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$checks = 0;
$expect = static function ($condition, $label) use (&$checks) {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$file = tempnam(sys_get_temp_dir(), 'gantry-php85-');
try {
    foreach ([
        'safe-ASCII_1.2,3' => 'safe-ASCII_1.2,3',
        '"&<>' => '&quot;&amp;&lt;&gt;',
        "\x01\x1f\x7f" => '&#xFFFD;&#xFFFD;&#xFFFD;',
        "\t\n\r " => '&#x09;&#x0A;&#x0D;&#x20;',
        'café' => 'caf&#x00E9;',
        '—' => '&#x2014;',
        '😀' => '&#x1F600;',
        '' => '',
    ] as $input => $escaped) {
        $expect(HtmlDocument::escape($input, 'html_attr') === $escaped, 'attribute escaping preserves entities for ' . bin2hex($input));
    }
    try {
        HtmlDocument::escape("\xff", 'html_attr');
        throw new LogicException('Malformed UTF-8 was accepted.');
    } catch (RuntimeException $expected) {
        $expect(str_contains($expected->getMessage(), 'valid UTF-8'), 'malformed UTF-8 remains refused');
    }

    // A real file transfer exercises cURL execution/handle cleanup without remote services.
    $payload = "Gantry cURL bytes\n\0café";
    file_put_contents($file, $payload);
    Response::setMethod('curl');
    $expect(Response::get('file://' . $file) === $payload, 'actual cURL returns exact binary payload without deprecations');
    $progress = [];
    $expect(Response::get('file://' . $file, [], static function ($value) use (&$progress) { $progress[] = $value; }) === $payload,
        'actual cURL transfer accepts a progress callback without deprecations');
    $last = end($progress);
    $expect($last && $last['transferred'] === strlen($payload) && $last['filesize'] === strlen($payload) && $last['percent'] == 100,
        'real CurlHandle progress reports downloaded bytes and completion');
    // Exercise the stream notification contract separately from actual cURL I/O.
    $progress = [];
    Response::progress(STREAM_NOTIFY_FILE_SIZE_IS, 0, '', 0, 0, 20);
    Response::progress(STREAM_NOTIFY_PROGRESS, 0, '', 0, 5, 20);
    $expect(count($progress) === 1 && $progress[0]['transferred'] === 5 && $progress[0]['percent'] == 25,
        'stream progress callback uses the advertised content length');
    Response::progress(STREAM_NOTIFY_COMPLETED, 0, '', 0, 20, 20);
    $expect(count($progress) === 2 && $progress[1]['percent'] == 100, 'stream completion emits final progress');
    Response::progress(STREAM_NOTIFY_AUTH_RESULT, 0, '', 0, 20, 20);
    $expect(count($progress) === 2, 'unrelated stream notifications never emit progress');
    unlink($file);
    try {
        Response::get('file://' . $file);
        throw new LogicException('Missing cURL source was accepted.');
    } catch (RuntimeException $expected) {
        $expect(str_contains($expected->getMessage(), 'cURL error ('), 'actual cURL failure retains RuntimeException');
    }
    echo 'PASS ' . $checks . ' PHP 8.5 runtime regression checks on PHP ' . PHP_VERSION . "\n";
} finally {
    Response::setMethod('auto');
    Response::$callback = null;
    if (is_file($file)) { unlink($file); }
    restore_error_handler();
}
