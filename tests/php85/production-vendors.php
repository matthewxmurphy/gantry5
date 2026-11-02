<?php
/**
 * Regression for development vendors copied into a production framework ZIP.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 * Usage: php tests/php85/production-vendors.php [runtime-package-directory ...]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) { throw new RuntimeException('Use actual PHP 8.5 CLI.'); }
require dirname(__DIR__, 2) . '/bin/verify-production-vendors.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
error_reporting(E_ALL);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$root = sys_get_temp_dir() . '/gantry-production-vendors-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
        rmdir($path);
    } else { unlink($path); }
};
$json = static function (string $file, array $data): void { file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR)); };
$metadata = static function (string $folder): array { return require $folder . '/vendor/composer/installed.php'; };
$save = static function (string $folder, array $data): void { file_put_contents($folder . '/vendor/composer/installed.php', '<?php return ' . var_export($data, true) . ';'); };
$fixture = static function (string $name) use ($root, $json, $save): string {
    $folder = $root . '/' . $name;
    mkdir($folder . '/vendor/composer', 0700, true);
    $json($folder . '/composer.lock', ['packages' => [['name' => 'runtime/library', 'version' => '1.0.0']], 'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '9.6.0']]]);
    $save($folder, ['root' => ['name' => 'gantry/fixture', 'dev' => false], 'versions' => ['gantry/fixture' => ['dev_requirement' => false], 'runtime/library' => ['pretty_version' => '1.0.0', 'dev_requirement' => false, 'install_path' => $folder . '/vendor/runtime/library']]]);
    $json($folder . '/vendor/composer/installed.json', ['dev' => false, 'packages' => [['name' => 'runtime/library', 'version' => '1.0.0']]]);
    file_put_contents($folder . '/vendor/autoload.php', '<?php $GLOBALS["gantry_vendor_guard_loaded_autoloader"] = true;');
    file_put_contents($folder . '/vendor/composer/autoload_static.php', '<?php /* native generated map fixture */');
    return $folder;
};
$rejects = static function (string $label, callable $change, string $message) use ($fixture, $expect): void {
    $folder = $fixture('reject-' . bin2hex(random_bytes(4)));
    $change($folder);
    try { GantryProductionVendors::verify($folder); }
    catch (RuntimeException $error) { $expect(str_contains($error->getMessage(), $message), $label); return; }
    throw new RuntimeException('Guard admitted ' . $label);
};
try {
    $folder = $fixture('clean');
    $expect(GantryProductionVendors::verify($folder) === 1, 'clean native Composer 2 production metadata');
    $expect(!isset($GLOBALS['gantry_vendor_guard_loaded_autoloader']), 'admission never executes runtime autoloader');
    unlink($folder . '/vendor/composer/installed.json');
    $expect(GantryProductionVendors::verify($folder) === 1, 'native installed.php alone is sufficient');
    foreach (['provide' => 'provided', 'replace' => 'replaced'] as $kind => $field) {
        $folder = $fixture('virtual-' . $kind);
        $lock = json_decode(file_get_contents($folder . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $lock['packages'][0][$kind] = ['psr/event-dispatcher-implementation' => '1.0'];
        $json($folder . '/composer.lock', $lock);
        $data = $metadata($folder);
        $data['versions']['psr/event-dispatcher-implementation'] = ['dev_requirement' => false, $field => ['1.0']];
        $save($folder, $data);
        $expect(GantryProductionVendors::verify($folder) === 1, 'locked runtime virtual ' . $kind . ' is not a missing physical package');
    }
    $rejects('unlocked virtual package', static function ($f) use ($metadata, $save) { $m = $metadata($f); $m['versions']['unknown/virtual'] = ['dev_requirement' => false, 'provided' => ['1.0']]; $save($f, $m); }, 'unlocked or development package');
    $rejects('provided name cannot hide a physical package', static function ($f) use ($metadata, $save, $json) {
        $lock = json_decode(file_get_contents($f . '/composer.lock'), true); $lock['packages'][0]['provide'] = ['unknown/physical' => '1.0']; $json($f . '/composer.lock', $lock);
        $m = $metadata($f); $m['versions']['unknown/physical'] = ['dev_requirement' => false, 'provided' => ['1.0'], 'install_path' => '/physical']; $save($f, $m);
    }, 'unlocked or development package');
    $rejects('root development mode', static function ($f) use ($metadata, $save) { $m = $metadata($f); $m['root']['dev'] = true; $save($f, $m); }, 'not a --no-dev installation');
    $rejects('native dev_requirement flag', static function ($f) use ($metadata, $save) { $m = $metadata($f); $m['versions']['runtime/library']['dev_requirement'] = true; $save($f, $m); }, 'development package metadata');
    $rejects('locked dev package despite false flag', static function ($f) use ($metadata, $save) { $m = $metadata($f); $m['versions']['phpunit/phpunit'] = ['dev_requirement' => false]; $save($f, $m); }, 'unlocked or development package');
    $rejects('unknown installed runtime package', static function ($f) use ($metadata, $save) { $m = $metadata($f); $m['versions']['unknown/package'] = ['dev_requirement' => false]; $save($f, $m); }, 'unlocked or development package');
    $rejects('missing locked runtime dependency', static function ($f) use ($metadata, $save) { $m = $metadata($f); unset($m['versions']['runtime/library']); $save($f, $m); }, 'missing locked runtime packages');
    $rejects('JSON development mode', static function ($f) use ($json) { $json($f . '/vendor/composer/installed.json', ['dev' => true, 'packages' => []]); }, 'installed.json is not a --no-dev installation');
    $rejects('JSON does not match actual packages', static function ($f) use ($json) { $json($f . '/vendor/composer/installed.json', ['dev' => false, 'packages' => []]); }, 'does not match the locked runtime packages');
    $rejects('stale dev directory with production metadata', static function ($f) { mkdir($f . '/vendor/phpunit/phpunit', 0700, true); }, 'leftover development package');
    foreach (['files', 'static', 'classmap'] as $map) {
        $rejects('stale optimized ' . $map . ' reference despite production metadata', static function ($f) use ($map) {
            file_put_contents($f . '/vendor/composer/autoload_' . $map . '.php', "<?php return [__DIR__ . '/../phpunit/phpunit/src/Framework/Assert/Functions.php'];");
        }, 'development reference in autoload_' . $map);
    }
    $rejects('missing native Composer metadata', static function ($f) { unlink($f . '/vendor/composer/installed.php'); }, 'missing Composer 2');
    $rejects('malformed locked metadata', static function ($f) { file_put_contents($f . '/composer.lock', '{invalid'); }, 'invalid Composer metadata');
    $rejects('missing locked dev list fails closed', static function ($f) use ($json) { $json($f . '/composer.lock', ['packages' => []]); }, 'incomplete locked');
    $rejects('missing actual optimized map', static function ($f) { unlink($f . '/vendor/composer/autoload_static.php'); }, 'missing generated runtime autoloader');
    $expect(count(GantryProductionVendors::folders('/source', [])) === 6, 'default prod checks all shipped runtime/compat trees');
    $expect(GantryProductionVendors::folders('/source', ['joomla-prod']) === ['/source/platforms/joomla/lib_gantry5', '/source/platforms/joomla/lib_gantry5/compat'], 'selected Joomla production build checks only its vendors');
    $expect(GantryProductionVendors::folders('/source', ['dev']) === [] && GantryProductionVendors::folders('/source', ['joomla-dev']) === [], 'development targets preserve test vendors');
    $expect(count(GantryProductionVendors::folders('/source', ['dev', 'joomla-prod'])) === 2, 'dev argument cannot hide an explicit production target');
    mkdir($root . '/source/bin/builder', 0700, true);
    mkdir($root . '/other', 0700);
    $expect(GantryProductionVendors::sourceRoot($root . '/source', ['joomla-prod', '-Dproject_dir=' . $root . '/other']) === realpath($root . '/other'), 'absolute Phing project source override is verified');
    $expect(GantryProductionVendors::sourceRoot($root . '/source', ['joomla-prod', '-Dproject_dir=../../../other']) === realpath($root . '/other'), 'relative project override uses Phing builder working directory');
    foreach ([['-Dpath.platforms=/unverified'], ['-Dproject_dir=/does-not-exist'], ['-Dproject_dir=', '-Dproject_dir=/other']] as $arguments) {
        try { GantryProductionVendors::sourceRoot($root . '/source', ['joomla-prod', ...$arguments]); }
        catch (RuntimeException $error) { $expect(true, 'unverifiable source override fails before packaging'); continue; }
        throw new RuntimeException('Unverifiable production source override was admitted.');
    }
    foreach (array_slice($argv, 1) as $runtime) {
        $expect(GantryProductionVendors::verify($runtime) > 0, 'actual production runtime admission');
        require $runtime . '/vendor/autoload.php';
        $expect(!function_exists('PHPUnit\\Framework\\assertTrue') && !class_exists('PHPUnit\\Framework\\TestCase'), 'production autoload never exposes PHPUnit');
        $expect(ltrim(Composer\InstalledVersions::getPrettyVersion('matthiasmullie/minify') ?? '', 'v') === '1.3.75', 'locked real minifier remains installed');
        $expect((new MatthiasMullie\Minify\CSS('body { color: red; }'))->minify() === 'body{color:red}', 'actual production CSS minifier executes');
        $expect((new MatthiasMullie\Minify\JS('/* remove */ var x = 1;'))->minify() === 'var x=1', 'actual production JavaScript minifier executes');
        $expect(Twig\Environment::VERSION === '3.30.0' && function_exists('twig_cycle'), 'real Twig and its required resource bootstrap remain available');
    }
    echo 'PASS ' . $checks . ' production vendor admission checks on PHP ' . PHP_VERSION . "\n";
} finally {
    $remove($root);
    restore_error_handler();
}
