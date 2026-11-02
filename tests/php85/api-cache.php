<?php
/**
 * Native Gantry/YAML cache regression. Run with actual installed Composer vendors:
 * php tests/php85/api-cache.php /path/to/lib_gantry5/vendor/autoload.php [native-source-root]
 * Fixtures are private temporary files; no CMS, site or build source is modified.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Api\ThemeApi;
use Gantry\Component\Api\ApiException;
use Gantry\Component\Api\Input;
use Gantry\Component\Config\BlueprintForm;
use Gantry\Component\Config\BlueprintSchema;
use Gantry\Component\Config\Config;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Services\ConfigServiceProvider;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use RocketTheme\Toolbox\File\YamlFile;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 CLI and the installed Gantry Composer autoloader.');
}
$source = $argv[2] ?? dirname(__DIR__, 2);
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
foreach (['ApiException', 'Input', 'ThemeApi'] as $class) {
    require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Api/' . $class . '.php';
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$temporary = sys_get_temp_dir() . '/g601-native-cache-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('_JEXEC', 1); // Enables native class loading only; no Joomla application is bootstrapped.
define('GANTRY5_ROOT', $temporary);
define('GANTRY_DEBUGGER', false);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$readYaml = static function (string $filename): array {
    $file = CompiledYamlFile::instance($filename);
    try { return $file->content(); } finally { $file->free(); }
};
try {
    mkdir($temporary . '/config/20', 0700, true);
    mkdir($temporary . '/config/default', 0700, true);
    mkdir($temporary . '/cache/theme/compiled/yaml', 0700, true);
    mkdir($temporary . '/cache/theme/compiled/config', 0700, true);
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-config', '', $temporary . '/config');
    $locator->addPath('gantry-cache', '', $temporary . '/cache');
    $gantry = new Gantry();
    $gantry['global'] = new Config(['compile_yaml' => 1]);
    $gantry['blueprints'] = new BlueprintSchema();
    $gantry['locator'] = static fn () => $locator;
    $gantry['theme.name'] = 'g6_fixture';
    $outlines = new Outlines($gantry, ['20' => 'Owned cache fixture']);
    // Match the real Gantry container: every lookup returns a fresh collection clone.
    $gantry['outlines'] = $gantry->factory(static fn () => $outlines->copy());
    $instance = new ReflectionProperty(Gantry::class, 'instance');
    $instance->setValue(null, $gantry);
    CompiledYamlFile::$defaultCaching = true;
    CompiledYamlFile::$defaultCachePath = $temporary . '/cache/theme/compiled/yaml';
    $filename = $temporary . '/config/20/styles.yaml';
    $before = "accent:\n  color-1: '#123456'\n";
    $after = "accent:\n  color-1: '#ee4b2b'\n";
    file_put_contents($filename, $before);
    $mtime = time() - 5;
    touch($filename, $mtime);
    clearstatcache();
    $api = new ThemeApi($gantry);
    $expect(ConfigServiceProvider::load($gantry, '20', false, false)->get('styles.accent.color-1') === '#123456', 'real combined-config cache is primed');
    $expect($readYaml($filename)['accent']['color-1'] === '#123456', 'real compiled-YAML cache is primed');
    $oldRevision = $api->getSettings('20')['revision'];
    file_put_contents($filename, $after);
    touch($filename, $mtime);
    clearstatcache();
    $expect(strlen($before) === strlen($after) && filemtime($filename) === $mtime, 'fixture changes exact color bytes at identical size and mtime');
    $expect(ConfigServiceProvider::load($gantry, '20', false, false)->get('styles.accent.color-1') === '#123456', 'native mtime-only combined cache reproduces the stale-read defect');
    $expect($readYaml($filename)['accent']['color-1'] === '#123456', 'native mtime-only YAML cache reproduces the stale-read defect');
    $settings = $api->getSettings('20');
    $expect($settings['raw']['styles']['accent']['color-1'] === '#ee4b2b', 'public API raw read sees exact new bytes despite old native caches');
    $expect($settings['effective']['styles']['accent']['color-1'] === '#ee4b2b', 'public API effective read sees exact new bytes');
    $expect($settings['revision'] !== $oldRevision, 'SHA-256 revision changes despite same size and mtime');
    $expect($gantry['global']->get('compile_yaml') === 1 && CompiledYamlFile::$defaultCaching === true, 'public API restores native cache flags exactly');
    $invalidate = new ReflectionMethod(ThemeApi::class, 'invalidateCaches');
    $invalidate->invoke($api, [$filename], ['20']);
    $expect($readYaml($filename)['accent']['color-1'] === '#ee4b2b', 'normal native YAML reader sees new value after scoped derived-cache invalidation');
    $expect(ConfigServiceProvider::load($gantry, '20', false, false)->get('styles.accent.color-1') === '#ee4b2b', 'normal native combined-config reader sees new value after scoped invalidation');
    $expect(file_get_contents($filename) === $after, 'cache invalidation preserves actual configuration bytes');
    $fresh = new ReflectionMethod(ThemeApi::class, 'fresh');
    try {
        $fresh->invoke($api, static function (): array { throw new LogicException('Owned test failure'); });
        throw new RuntimeException('Expected owned fixture exception.');
    } catch (LogicException $expected) {
        $expect($expected->getMessage() === 'Owned test failure', 'native cache scope preserves the operation exception');
    }
    $expect($gantry['global']->get('compile_yaml') === 1 && CompiledYamlFile::$defaultCaching === true, 'exception path restores exact cache flags');
    $nested = $fresh->invoke($api, static fn (): array => $api->getSettings('20'));
    $expect($nested['raw']['styles']['accent']['color-1'] === '#ee4b2b' && $gantry['global']->get('compile_yaml') === 1 && CompiledYamlFile::$defaultCaching === true, 'nested public API reads restore surrounding cache scope');
    unset($gantry['global']['compile_yaml']);
    $api->getSettings('20');
    $expect(!array_key_exists('compile_yaml', $gantry['global']->toArray()) && CompiledYamlFile::$defaultCaching === true, 'absent compile flag remains absent after native read');
    // Exercise the real installed blueprint compiler, including its nested-rule shape.
    $form = new BlueprintForm(null, ['form' => ['fields' => [
        'color-1' => ['type' => 'input.colorpicker', 'label' => 'Accent'],
        'nested.text' => ['type' => 'input.text', 'label' => 'Text'],
        'mode' => ['type' => 'select.select', 'options' => ['one' => 'One', 'two' => 'Two']],
        'html' => ['type' => 'textarea.textarea'],
        'items' => ['type' => 'collection.list', 'array' => true],
    ]]]);
    $form->init();
    $validate = new ReflectionMethod(ThemeApi::class, 'validateFields');
    $validate->invoke($api, $form, ['color-1' => '#ee4b2b', 'nested' => ['text' => 'Exact client value'], 'mode' => 'one'], true);
    $expect(true, 'real native blueprint flat and nested controls validate');
    foreach ([
        [['unknown' => 'value'], 'unknown_setting'],
        [['color-1' => 'red; color:blue'], 'unsafe_value'],
        [['color-1' => 'not-a-color'], 'invalid_setting'],
        [['mode' => 'three'], 'invalid_setting'],
        [['html' => '<p>code</p>'], 'unsupported_setting'],
        [['items' => [['name' => 'asset']]], 'unsupported_setting'],
    ] as [$patch, $code]) {
        try {
            $validate->invoke($api, $form, $patch, true);
            throw new RuntimeException('Unsafe native blueprint control was accepted.');
        } catch (ApiException $expected) {
            $expect($expected->getErrorCode() === $code && $expected->getCode() === 422, 'real native blueprint refuses ' . $code);
        }
    }
    $installed = [];
    foreach (['copyright', 'spacer', 'content', 'messages', 'position'] as $subtype) {
        $file = YamlFile::instance($source . '/engines/common/nucleus/particles/' . $subtype . '.yaml');
        try { $installed[$subtype] = $file->content(); } finally { $file->free(); }
    }
    foreach (['div', 'section', 'aside', 'nav', 'article', 'header', 'footer', 'main'] as $tag) {
        $expect(Input::nodeType('section', $tag, $installed), 'native structural section tag accepted: ' . $tag);
    }
    foreach (['copyright' => 'particle', 'spacer' => 'spacer', 'content' => 'system', 'messages' => 'system', 'position' => 'position'] as $subtype => $type) {
        $expect(!Input::nodeType($type, $subtype, $installed), 'actual native installed blueprint accepts its content type: ' . $subtype);
    }
    foreach ([['section', 'script'], ['grid', 'header'], ['particle', 'content'], ['system', 'copyright'], ['spacer', 'unknown']] as [$type, $subtype]) {
        try {
            Input::nodeType($type, $subtype, $installed);
            throw new RuntimeException('Invalid native node type was accepted.');
        } catch (ApiException $expected) {
            $expect($expected->getCode() === 422 && $expected->getErrorCode() === 'invalid_layout', 'native node type mismatch is refused');
        }
    }
    mkdir($temporary . '/layouts', 0700);
    file_put_contents($temporary . '/layouts/default.yaml', "version: 2\npreset:\n  name: default\n  timestamp: 0\n  image: gantry-admin://images/layouts/default.png\nlayout: {}\n");
    $locator->addPath('gantry-layouts', '', $temporary . '/layouts');
    $created = $api->createOutline(['title' => 'Owned API Fixture']);
    $expect($created['id'] === 'owned_api_fixture', 'native outline create returns its actual allocated ID with factory-cloned collection');
    $expect($api->getOutline($created['id'])['revision'] === $created['revision'], 'immediate native create/get revisions agree despite container clone factory');
    $expect(!isset($gantry['outlines'][$created['id']]), 'API retains its native collection without mutating the shared factory prototype');
    $expect(is_file($temporary . '/config/' . $created['id'] . '/layout.yaml') && is_file($temporary . '/config/' . $created['id'] . '/index.yaml'), 'factory-clone regression uses real native layout and index persistence');

    // Use the actual shipped preset, not a hand-shaped layout or mock reader.
    // Read entry points must derive missing/stale indexes only in memory. A read
    // must not materialize layout.yaml/index.yaml outside the change engine.
    copy($source . '/themes/hydrogen/common/layouts/default.yaml', $temporary . '/layouts/default.yaml');
    $sourceTree = static function () use ($temporary): array {
        $tree = [];
        foreach (['config', 'layouts'] as $scope) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary . '/' . $scope, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $key = $scope . '/' . substr($file->getPathname(), strlen($temporary . '/' . $scope) + 1);
                $tree[$key] = $file->isDir() ? null : ['bytes' => file_get_contents($file->getPathname()), 'mtime' => $file->getMTime(), 'mode' => $file->getPerms() & 0777];
            }
        }
        ksort($tree);
        return $tree;
    };
    $caching = CompiledYamlFile::$defaultCaching;
    CompiledYamlFile::$defaultCaching = false;
    try {
        $tree = $sourceTree();
        $index = Layout::index('default');
        $expect(isset($index['sections']['header']) && $index['preset']['name'] === 'default', 'missing index derives real installed preset sections in memory');
        $expect($sourceTree() === $tree, 'Layout::index does not materialize missing base layout or index YAML');
        $loaded = Layout::load('default');
        $expect(count($loaded->toArray()) > 0 && $loaded->preset['name'] === 'default', 'Layout::load resolves missing base layout through installed preset');
        $expect($sourceTree() === $tree, 'Layout::load preserves exact source tree with missing layout and index');

        mkdir($temporary . '/config/readonly_stale', 0700);
        file_put_contents($temporary . '/config/readonly_stale/index.yaml', "name: readonly_stale\ntimestamp: 0\nversion: 0\npreset:\n  name: default\nsections:\n  must-not-survive: stale\n");
        $tree = $sourceTree();
        $index = Layout::index('readonly_stale');
        $expect($index['version'] === Layout::VERSION && isset($index['sections']['header']) && !isset($index['sections']['must-not-survive']), 'stale preset-only index is rebuilt accurately in memory');
        $expect($sourceTree() === $tree, 'Layout::index leaves stale index bytes and missing layout untouched');
        $loaded = Layout::load('readonly_stale');
        $expect(count($loaded->toArray()) > 0 && $loaded->preset['name'] === 'default', 'Layout::load resolves installed preset despite stale source index');
        $expect($sourceTree() === $tree, 'Layout::load preserves exact stale index and absent layout YAML');

        mkdir($temporary . '/config/readonly_layout', 0700);
        copy($source . '/themes/hydrogen/common/layouts/default.yaml', $temporary . '/config/readonly_layout/layout.yaml');
        $tree = $sourceTree();
        $index = Layout::index('readonly_layout');
        $expect(isset($index['sections']['header']), 'existing native layout builds missing index sections in memory');
        $expect($sourceTree() === $tree, 'Layout::index preserves an existing layout without creating index YAML');
        $loaded = Layout::load('readonly_layout');
        $expect(count($loaded->toArray()) > 0, 'Layout::load reads actual existing native layout');
        $expect($sourceTree() === $tree, 'existing-layout reads preserve exact YAML bytes, mtimes, modes and directory paths');
    } finally { CompiledYamlFile::$defaultCaching = $caching; }
    echo 'PASS ' . $checks . ' native core API cache/blueprint regression checks on PHP ' . PHP_VERSION . "\n";
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
    restore_error_handler();
}
