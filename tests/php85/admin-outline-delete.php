<?php
/**
 * Native delete controller with the real Gantry request/container/change engine.
 * php tests/php85/admin-outline-delete.php /installed/vendor/autoload.php [source-root]
 * Assignment/outline adapters are bounded fixtures; no real CMS menu is changed.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Admin\Controller\Html\Configurations;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Changes\StateAdapter;
use Gantry\Component\Config\Config;
use Gantry\Component\Request\Input;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Request;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and installed native Gantry Composer dependencies.');
}
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', ($argv[2] ?? dirname(__DIR__, 2)) . '/src/classes/Gantry', true);
define('_JEXEC', 1); define('GANTRY_DEBUGGER', false); define('GANTRY5_DEBUG', false);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});

final class NativeDeleteStateFixture extends StateAdapter
{
    public static bool $assigned = true;
    public static bool $transaction = false;
    public static int $queries = 0;
    public function __construct(Gantry $gantry) {}
    public function capture(): array { return ['assigned' => self::$assigned]; }
    public function begin(): void { self::$transaction = true; }
    public function commit(): void { self::$transaction = false; }
    public function rollback(): void { self::$transaction = false; }
    public function assigned(string $id): bool
    {
        if (!self::$transaction) { throw new RuntimeException('The assignment check escaped the engine transaction.'); }
        ++self::$queries;
        return self::$assigned;
    }
}
// A CMS overlay is selected before the first engine is constructed, as in a
// real installation. The fixture can therefore detect checking before locking.
if (class_exists('Gantry\\Framework\\ChangeState', false)) { throw new RuntimeException('The CMS fixture overlay was already loaded.'); }
class_alias(NativeDeleteStateFixture::class, 'Gantry\\Framework\\ChangeState');

final class NativeDeleteOutlinesFixture extends Outlines
{
    public static int $deletes = 0;
    public static string $file;
    public function delete($id)
    {
        ++self::$deletes;
        if (!unlink(self::$file)) { throw new RuntimeException('Could not remove the owned fixture.'); }
        unset($this[$id]);
    }
}

function deleteFixturePost(array $data): Input { return new Input($data); }

$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-delete-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700); define('GANTRY5_ROOT', $temporary);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); } ++$checks;
};
try {
    mkdir($temporary . '/config/20', 0700, true);
    $file = $temporary . '/config/20/styles.yaml';
    $bytes = "accent:\n  color-1: '#123456'\n";
    file_put_contents($file, $bytes);
    NativeDeleteOutlinesFixture::$file = $file;
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-config', '', $temporary . '/config');
    $locator->addPath('gantry-cache', '', $temporary . '/cache');
    $request = new Request();
    $identity = new class {
        public bool $mayDelete = true;
        public function authorize(string $action): bool { return $action === 'platform.settings.manage' || ($action === 'outline.delete' && $this->mayDelete); }
    };
    $gantry = new Gantry(); $gantry['request'] = $request; $gantry['platform'] = $identity;
    $gantry['theme.name'] = 'g6_delete_fixture'; $gantry['global'] = new Config(['compile_yaml' => 1]);
    $gantry['locator'] = static fn() => $locator;
    $outlines = new NativeDeleteOutlinesFixture($gantry, ['20' => 'Owned delete fixture']);
    $gantry['outlines'] = $gantry->factory(static fn() => $outlines->copy());
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    $controller = new Configurations($gantry); $engine = new ChangeEngine($gantry);
    $initial = $engine->revision();
    foreach (['save', 'preview'] as $mode) {
        $request->post = deleteFixturePost(['g6_revision' => $initial, 'g6_mode' => $mode]);
        try { $controller->delete('20'); throw new RuntimeException('Assigned outline deletion was accepted.'); }
        catch (RuntimeException $error) { $expect($error->getCode() === 409 && str_contains($error->getMessage(), 'Unassign it through the CMS'), 'assigned ' . $mode . ' is refused clearly'); }
        $expect(NativeDeleteOutlinesFixture::$deletes === 0 && file_get_contents($file) === $bytes, 'assigned ' . $mode . ' never reaches native delete or changes files');
        $expect($engine->revision() === $initial && !$engine->history()['items'], 'refused ' . $mode . ' leaves revision/history unchanged');
    }
    $expect(NativeDeleteStateFixture::$queries === 2, 'both native paths checked assignments inside their engine transaction');
    NativeDeleteStateFixture::$assigned = false;
    $current = $engine->revision();
    $request->post = deleteFixturePost(['g6_revision' => $initial]);
    try { $controller->delete('20'); throw new RuntimeException('Stale assignment revision was accepted.'); }
    catch (RuntimeException $error) { $expect($error->getCode() === 409 && NativeDeleteStateFixture::$queries === 2, 'stale revisions fail before assignment lookup or delete'); }
    $request->post = deleteFixturePost(['g6_revision' => $current, 'g6_mode' => 'preview']);
    $preview = json_decode((string) $controller->delete('20'), true, 32, JSON_THROW_ON_ERROR);
    $expect($preview['success'] && $preview['preview'] && NativeDeleteOutlinesFixture::$deletes === 0, 'unassigned preview remains a no-write plan');
    $expect($engine->revision() === $current && file_get_contents($file) === $bytes, 'unassigned preview preserves exact saved state');
    $identity->mayDelete = false;
    try { $controller->delete('20'); throw new RuntimeException('Native finer delete permission was bypassed.'); }
    catch (RuntimeException $error) { $expect($error->getCode() === 403 && NativeDeleteStateFixture::$queries === 3, 'finer native outline-delete permission is retained'); }
    $identity->mayDelete = true;
    $request->post = deleteFixturePost(['g6_revision' => $current]);
    $save = json_decode((string) $controller->delete('20'), true, 32, JSON_THROW_ON_ERROR);
    $expect($save['success'] && NativeDeleteOutlinesFixture::$deletes === 1 && !is_file($file), 'unassigned native delete runs only through the common engine');
    $expect($save['revision'] !== $current && isset($save['change_id']), 'successful native delete returns a new undoable revision');
    echo 'PASS ' . $checks . ' native outline delete checks on PHP ' . PHP_VERSION . "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($temporary); restore_error_handler();
}
