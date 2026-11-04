<?php
/**
 * #2486: Unicode outline create/rename and native layout/index files agree.
 * php tests/php85/issue-2486.php /path/to/vendor/autoload.php [native-source-root]
 * This exercises the real shared collection/streams/filesystem, without a CMS write.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Config\Config;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Streams;
use Gantry\Component\Layout\Layout;
use Gantry\Component\Layout\LayoutReader;
use Gantry\Component\Outline\OutlineCollection;
use Gantry\Framework\Gantry;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and the native Gantry Composer autoloader.');
}
$source = $argv[2] ?? dirname(__DIR__, 2);
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$temporary = sys_get_temp_dir() . '/g6-issue2486-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GRAV_ROOT', $temporary); // Native container admission only; no CMS is booted.
define('GANTRY5_ROOT', $temporary);
define('GANTRY_DEBUGGER', false);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed #2486: ' . $label); }
    ++$checks;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $remove($entry->getPathname()); } }
        rmdir($path);
    } else { unlink($path); }
};
try {
    mkdir($temporary . '/config', 0700);
    mkdir($temporary . '/cache', 0700);
    $locator = new UniformResourceLocator($temporary);
    $streams = new Streams($locator);
    $streams->add([
        'gantry-theme' => ['paths' => [$temporary]],
        'gantry-config' => ['paths' => [$temporary . '/config']],
        'gantry-cache' => ['paths' => [$temporary . '/cache']],
    ]);
    $streams->register();
    $gantry = new Gantry();
    $gantry['locator'] = static fn () => $locator;
    $gantry['global'] = new Config(['compile_yaml' => false]);
    $outlines = new OutlineCollection($gantry);
    $gantry['outlines'] = $outlines;
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    CompiledYamlFile::$defaultCaching = false;
    CompiledYamlFile::$defaultCachePath = $temporary . '/cache';
    $outlines->load();
    $preset = LayoutReader::data(['version' => 2, 'preset' => ['name' => 'owned-empty'], 'layout' => ['/main/' => []]]);
    // Native catalogue reads may inspect each system outline while renaming.
    foreach (array_keys($outlines->toArray()) as $id) { (new Layout($id, $preset))->save(false)->saveIndex(); }

    foreach (['Главная страница' => 'главная_страница', 'Αρχική Σελίδα' => 'αρχική_σελίδα',
        '首页 内容' => '首页_内容', 'صفحة رئيسية' => 'صفحة_رئيسية', 'Café Accueil' => 'café_accueil'] as $title => $expected) {
        $id = $outlines->create(null, $title, $preset);
        $expect($id === $expected, 'Unicode title produces a safe readable native slug: ' . $title);
        $expect(is_file($temporary . '/config/' . $id . '/layout.yaml'), 'layout saves under the same Unicode identity');
        $expect(is_file($temporary . '/config/' . $id . '/index.yaml'), 'index saves under the same Unicode identity');
        $expect($outlines->title($id) === $title, 'display title remains separate from the normalized folder');
    }
    $id = $outlines->create(null, 'Главная страница', $preset);
    $expect($id === 'главная_страница_2', 'duplicate Unicode title allocates the next free native identity');
    $longTitle = str_repeat('首页内容', 40);
    $longId = $outlines->create(null, $longTitle, $preset);
    $expect(strlen($longId) <= 224 && preg_match('//u', $longId) === 1, 'long CJK slug is bounded without splitting a Unicode character');
    $expect($outlines->title($longId) === $longTitle, 'bounded slug preserves the requested long display title');
    $duplicateId = $outlines->create(null, $longTitle, $preset);
    $expect($duplicateId === $longId . '_2' && strlen($duplicateId) <= 255, 'long CJK duplicate suffix fits the native filesystem component');
    $expect($outlines->title($duplicateId) === $longTitle, 'long duplicate display title is preserved');
    $longBefore = file_get_contents($temporary . '/config/' . $longId . '/layout.yaml');
    Layout::load($duplicateId)->save(false)->saveIndex();
    $expect(is_file($temporary . '/config/' . $duplicateId . '/layout.yaml') && is_file($temporary . '/config/' . $duplicateId . '/index.yaml'), 'long CJK duplicate layout/index save retains its complete allocated identity');
    $expect(file_get_contents($temporary . '/config/' . $longId . '/layout.yaml') === $longBefore, 'duplicate save never truncates its suffix into the original outline');
    $before = file_get_contents($temporary . '/config/главная_страница/layout.yaml');
    $renamed = $outlines->rename('главная_страница', 'Новая страница');
    $expect($renamed === 'новая_страница', 'Unicode rename returns the new native identity');
    $expect($outlines->title($renamed) === 'Новая страница' && !isset($outlines['главная_страница']), 'in-memory catalogue uses the renamed identity and exact title');
    $expect(!is_dir($temporary . '/config/главная_страница') && is_file($temporary . '/config/новая_страница/layout.yaml'), 'rename moves the real native files together');
    $expect(file_get_contents($temporary . '/config/новая_страница/layout.yaml') === $before, 'rename preserves layout content exactly');
    $loaded = Layout::load($renamed);
    $loaded->save(false)->saveIndex();
    $expect(is_file($temporary . '/config/новая_страница/layout.yaml') && !is_dir($temporary . '/config/_______________'), 'a later native layout save retains the renamed Unicode folder');
    $expect(Layout::index($renamed)['name'] === $renamed, 'native layout/index identity agrees after the subsequent save');
    foreach (['', '😀', '../', "bad\xffname"] as $invalid) {
        try {
            $outlines->rename($renamed, $invalid);
            throw new LogicException('Invalid outline title unexpectedly succeeded');
        } catch (RuntimeException $error) {
            $expect($error->getCode() === 400, 'invalid Unicode/path-only title is refused without PHP diagnostics');
        }
    }
    $expect(!is_dir($temporary . '/config/bad_name'), 'invalid UTF-8 does not materialize a replacement-byte outline');
    $expect(is_file($temporary . '/config/' . $renamed . '/layout.yaml'), 'failed renames preserve the original native outline');
    echo "PASS issue-2486: {$checks} native Unicode outline filesystem checks on PHP " . PHP_VERSION . "\n";
} finally {
    foreach (['gantry-theme', 'gantry-config', 'gantry-cache'] as $scheme) {
        if (in_array($scheme, stream_get_wrappers(), true)) { stream_wrapper_unregister($scheme); }
    }
    $remove($temporary);
}
