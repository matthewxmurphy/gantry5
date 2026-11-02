<?php
/**
 * Joomla change-state adapter against actual Joomla Database/DI/Factory classes.
 * php tests/php85/joomla-change-state.php <gantry-vendor/autoload.php>
 *     <joomla-libraries/vendor/autoload.php> [gantry-source-root] [joomla-Factory.php]
 * Uses an isolated SQLite memory database and private temporary theme paths.
 * It does not bootstrap a CMS site, use credentials, or certify staging MariaDB.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Api\ApiException;
use Gantry\Framework\ChangeState;
use Gantry\Framework\Gantry;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Sqlite\SqliteDriver;
use Joomla\DI\Container;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !extension_loaded('pdo_sqlite')
    || !isset($argv[1], $argv[2]) || !is_file($argv[1]) || !is_file($argv[2])) {
    throw new RuntimeException('Use actual PHP 8.5 with PDO SQLite and the existing Gantry/Joomla Composer autoloaders.');
}
$source = $argv[3] ?? dirname(__DIR__, 2);
$factory = $argv[4] ?? dirname($argv[2], 2) . '/src/Factory.php';
if (!is_file($factory)) { throw new RuntimeException('Supply the actual Joomla CMS Factory.php source path.'); }
define('_JEXEC', 1);
define('GANTRY_DEBUGGER', false);
require $argv[2];
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
require_once $factory;
// Explicitly test the real Joomla overlay, rather than the standalone adapter.
require $source . '/src/platforms/joomla/classes/Gantry/Framework/ChangeState.php';
error_reporting(E_ALL);
$strictDiagnostics = static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
};
set_error_handler($strictDiagnostics);

$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-joomla-state-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GANTRY5_ROOT', $temporary);
$oldContainer = Factory::$container;
$db = null;
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$conflict = static function (callable $operation, string $code, string $label) use ($expect): void {
    try { $operation(); }
    catch (ApiException $error) {
        $expect($error->getCode() === 409 && $error->getErrorCode() === $code, $label);
        return;
    }
    throw new RuntimeException('Failed to refuse: ' . $label);
};
try {
    $expect((new ReflectionMethod(DatabaseInterface::class, 'insertObject'))->getParameters()[1]->isPassedByReference(), 'actual Joomla insertObject requires a reference variable');
    $expect((new ReflectionMethod(DatabaseInterface::class, 'updateObject'))->getParameters()[1]->isPassedByReference(), 'actual Joomla updateObject requires a reference variable');
    $expect((new ReflectionMethod(DatabaseInterface::class, 'updateObject'))->getParameters()[3]->getDefaultValue() === false, 'actual Joomla updates omit NULL fields by default');
    $db = new SqliteDriver(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'g342_']);
    $upstreamDeprecations = [];
    $sqliteSource = realpath((new ReflectionClass(SqliteDriver::class))->getFileName());
    // The installed Joomla SQLite bootstrap still registers ROW_NUMBER through
    // PDO's deprecated 8.5 alias. Classify that exact upstream setup diagnostic;
    // no vendor code is changed and every other diagnostic remains an exception.
    set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($strictDiagnostics, $sqliteSource, &$upstreamDeprecations): bool {
        if ($severity === E_DEPRECATED && realpath($file) === $sqliteSource && $message === 'Method PDO::sqliteCreateFunction() is deprecated since 8.5, use Pdo\\Sqlite::createFunction() instead') {
            $upstreamDeprecations[] = $message;
            return true;
        }
        return $strictDiagnostics($severity, $message, $file, $line);
    });
    try { $db->connect(); } finally { restore_error_handler(); }
    $expect(count($upstreamDeprecations) <= 1, 'native SQLite bootstrap diagnostic is specifically counted, never broadly suppressed');
    $db->setQuery('CREATE TABLE #__template_styles (id INTEGER PRIMARY KEY, template TEXT NOT NULL, client_id INTEGER NOT NULL, home TEXT NOT NULL, title TEXT NOT NULL, params TEXT NOT NULL, checked_out INTEGER NULL, checked_out_time TEXT NULL)')->execute();
    $db->setQuery('CREATE TABLE #__menu (id INTEGER PRIMARY KEY, template_style_id INTEGER NOT NULL)')->execute();
    foreach ([
        ['id' => 19, 'template' => 'g6_fixture', 'client_id' => 0, 'home' => '1', 'title' => 'G342 protected default', 'params' => '{"configuration":"19","preset":"default"}', 'checked_out' => null, 'checked_out_time' => null],
        ['id' => 20, 'template' => 'g6_fixture', 'client_id' => 0, 'home' => '0', 'title' => 'G342 JoomlaMafia café', 'params' => '{"configuration":"20","preset":"default"}', 'checked_out' => null, 'checked_out_time' => null],
        ['id' => 30, 'template' => 'other_template', 'client_id' => 0, 'home' => '0', 'title' => 'Unrelated fixture', 'params' => '{}', 'checked_out' => null, 'checked_out_time' => null],
    ] as $row) {
        $record = (object) $row;
        $db->insertObject('#__template_styles', $record);
    }
    $db->setQuery('INSERT INTO #__menu (id, template_style_id) VALUES (1,19)')->execute();
    $container = new Container();
    $container->set(DatabaseInterface::class, $db);
    Factory::$container = $container;
    $theme = $temporary . '/theme';
    mkdir($theme . '/custom/config', 0700, true);
    mkdir($theme . '/custom/language/en-GB', 0700, true);
    file_put_contents($theme . '/templateDetails.xml', '<extension><positions><position>header</position></positions></extension>');
    $positionPath = $theme . '/custom/language/en-GB/en-GB.tpl_g6_fixture_positions.ini';
    file_put_contents($positionPath, 'TPL_G6_FIXTURE_POSITION_HEADER="Header"' . "\n");
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-theme', '', [$theme . '/custom', $theme]);
    $gantry = new Gantry();
    $gantry['theme.name'] = 'g6_fixture';
    $gantry['theme.path'] = $theme;
    $gantry['locator'] = static fn () => $locator;
    $adapter = new ChangeState($gantry);
    $expect($adapter->files()['template-details'] === $theme . '/templateDetails.xml', 'native manifest scope matches the Joomla manifest writer');
    $expect($adapter->files()['position-language'] === $positionPath, 'native language scope resolves the actual writable custom override');
    $baseline = $adapter->capture();
    $expect(array_column($baseline['styles'], 'id') === [19, 20], 'native capture selects only this theme frontend styles');
    $expect($baseline['assignments'] === [['id' => 1, 'template_style_id' => 19]], 'native capture records only assignments for selected styles');
    $expect($baseline['styles'][1]['checked_out'] === null && $baseline['styles'][1]['checked_out_time'] === null, 'native database retains original NULL values');
    $files = [file_get_contents($theme . '/templateDetails.xml'), file_get_contents($positionPath)];

    $adapter->begin();
    $db->setQuery('DELETE FROM #__template_styles WHERE id=20')->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $expect($adapter->capture() === $baseline, 'undo deletion reinserts the exact native row through by-reference insertObject');

    $adapter->begin();
    $db->setQuery("UPDATE #__template_styles SET title='G342 renamed', checked_out=943, checked_out_time='2026-11-02 20:00:00' WHERE id=20")->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $restored = $adapter->capture();
    $expect($restored === $baseline, 'undo rename restores every native style field');
    $expect($restored['styles'][1]['checked_out'] === null && $restored['styles'][1]['checked_out_time'] === null, 'undo passes nulls=true and restores both NULL fields');

    $adapter->begin();
    $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'0','G342 created','{}',NULL,NULL)")->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $expect($adapter->capture() === $baseline, 'undo creation deletes only the extra unassigned nondefault native row');
    $expect((int) $db->setQuery('SELECT COUNT(*) FROM #__template_styles WHERE id=30')->loadResult() === 1, 'native restore preserves another template row');

    $adapter->begin();
    $db->setQuery("UPDATE #__template_styles SET title='G342 transaction failure' WHERE id=20")->execute();
    $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'0','G342 rollback','{}',NULL,NULL)")->execute();
    $adapter->rollback();
    $expect($adapter->capture() === $baseline, 'actual Joomla database transaction rollback removes partial metadata changes');

    $adapter->begin();
    try {
        $db->setQuery('INSERT INTO #__menu (id,template_style_id) VALUES (2,20)')->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'revision_conflict', 'changed native menu assignments block undo');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'assignment-conflict transaction rollback preserves original metadata');

    $adapter->begin();
    try {
        $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'1','G342 protected new default','{}',NULL,NULL)")->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'protected_outline', 'undo cannot delete a newly protected default row');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'protected-default refusal preserves the original native state');

    $adapter->begin();
    try {
        $db->setQuery('DELETE FROM #__template_styles WHERE id=20')->execute();
        $db->setQuery("INSERT INTO #__template_styles VALUES (20,'another_template',0,'0','Unrelated ID reuse','{}',NULL,NULL)")->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'outline_conflict', 'undo cannot overwrite an outline ID reused by another theme');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'ID-reuse refusal leaves original native rows after rollback');
    $expect([file_get_contents($theme . '/templateDetails.xml'), file_get_contents($positionPath)] === $files, 'direct native database adapter operations leave theme files unchanged');
    echo 'PASS ' . $checks . ' real Joomla Database SQLite adapter checks on PHP ' . PHP_VERSION
        . '; observed ' . count($upstreamDeprecations) . " upstream SQLite bootstrap deprecation(s)\n";
} finally {
    Factory::$container = $oldContainer;
    $db?->disconnect();
    if (is_dir($temporary)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
    restore_error_handler();
}
