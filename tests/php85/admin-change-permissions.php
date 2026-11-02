<?php
/**
 * Native admin adapter ACL regressions with real Gantry request/container/engine.
 * php tests/php85/admin-change-permissions.php /installed/vendor/autoload.php [source-root]
 * The platform identity is a fixture; this does not claim a second Joomla user's
 * runtime acceptance. No CMS site, account, token or real configuration is used.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Config\Config;
use Gantry\Component\Request\Input;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Request;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and an installed native Gantry Composer autoloader.');
}
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', ($argv[2] ?? dirname(__DIR__, 2)) . '/src/classes/Gantry', true);
define('_JEXEC', 1);
define('GANTRY_DEBUGGER', false);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});

final class NativeChangePermissionFixture extends HtmlController
{
    public function attempt(string $label, callable $writer, callable $planner): array { return $this->changeSave($label, $writer, $planner); }
}

function fixturePost(array $items): Input { return new Input($items); }

$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-native-acl-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GANTRY5_ROOT', $temporary);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
try {
    mkdir($temporary . '/config/20', 0700, true);
    file_put_contents($temporary . '/config/20/styles.yaml', "accent:\n  color-1: '#123456'\n");
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-config', '', $temporary . '/config');
    $locator->addPath('gantry-cache', '', $temporary . '/cache');
    $request = new Request();
    $identity = new class {
        public bool $settingsAdministrator = false;
        public function authorize(string $action): bool { return $action === 'platform.settings.manage' && $this->settingsAdministrator; }
    };
    $gantry = new Gantry();
    $gantry['request'] = $request;
    $gantry['platform'] = $identity;
    $gantry['locator'] = static fn() => $locator;
    $gantry['theme.name'] = 'g6_native_acl_fixture';
    $gantry['global'] = new Config(['compile_yaml' => 1]);
    $outlines = new Outlines($gantry, ['20' => 'Owned native ACL fixture']);
    $gantry['outlines'] = $gantry->factory(static fn() => $outlines->copy());
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    $controller = new NativeChangePermissionFixture($gantry);
    $engine = new ChangeEngine($gantry);
    $initial = $engine->revision();
    $called = 0;
    $callback = static function () use (&$called): array { ++$called; return ['owned' => true]; };
    foreach (['admin.outline.create', 'admin.outline.duplicate', 'admin.outline.rename', 'admin.outline.delete',
        'admin.layout', 'admin.styles', 'admin.page', 'admin.particles', 'admin.designpack', 'admin.restore', 'admin.client.policy', 'client.text.extra'] as $label) {
        foreach (['save', 'preview'] as $mode) {
            $request->post = fixturePost(['g6_revision' => $initial, 'g6_mode' => $mode]);
            try { $controller->attempt($label, $callback, $callback); throw new RuntimeException('Unauthorized adapter accepted ' . $label); }
            catch (RuntimeException $error) { $expect($error->getCode() === 403, $label . ' ' . $mode . ' denied'); }
            $expect($called === 0, 'denied operation invokes neither planner nor writer');
        }
    }
    $expect($engine->revision() === $initial, 'denied operations leave authoritative state and generation unchanged');
    $identity->settingsAdministrator = true;
    $request->post = fixturePost(['g6_revision' => $initial, 'g6_mode' => 'preview']);
    $preview = $controller->attempt('admin.styles', static function (): never { throw new RuntimeException('Preview invoked a writer.'); }, $callback);
    $expect($preview['preview'] === true && $called === 1, 'authorized native preview reaches only its pure planner');
    $expect($engine->revision() === $initial, 'authorized preview leaves the revision unchanged');
    $request->post = fixturePost(['g6_revision' => $initial]);
    $save = $controller->attempt('admin.styles', $callback, $callback);
    $expect($called === 2 && $save['revision'] !== $initial && isset($save['change_id']), 'authorized native save reaches the common engine');
    $identity->settingsAdministrator = false;
    $request->post = fixturePost(['g6_revision' => $save['revision']]);
    $client = $controller->attempt('client.text', $callback, $callback);
    $expect($called === 3 && isset($client['change_id']), 'exact approved-client adapter exception reaches engine after dedicated controller policy checks');
    $identity->settingsAdministrator = true;
    $request->post = fixturePost([]);
    try { $controller->attempt('admin.layout', $callback, $callback); throw new RuntimeException('Missing revision was accepted.'); }
    catch (RuntimeException $error) { $expect($error->getCode() === 409, 'authorized editor still requires its loaded revision'); }
    echo 'PASS ' . $checks . ' native admin change permission checks on PHP ' . PHP_VERSION . "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($temporary);
    restore_error_handler();
}
