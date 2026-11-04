<?php
/**
 * Compile shipped SCSS for the issue-named real-browser regressions.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
    throw new RuntimeException('Run the Gantry 6 regression compiler on PHP 8.5.x.');
}
$autoload = $argv[1] ?? '';
$root = realpath($argv[2] ?? '');
$issue = $argv[3] ?? '';
if (!is_file($autoload) || !$root || !in_array($issue, ['2282', '2519', 'nucleus'], true)) {
    throw new InvalidArgumentException('Usage: php issue-css.php GANTRY_AUTOLOAD SOURCE_ROOT 2282|2519|nucleus');
}
require $autoload;

$compiler = new ScssPhp\ScssPhp\Compiler();
$compiler->setImportPaths([
    $root . '/themes/helium/common/scss',
    $root . '/engines/joomla/nucleus/scss',
    $root . '/engines/common/nucleus/scss',
]);
$source = match ($issue) {
    '2282' => '@import "joomla";',
    '2519' => '@import "dependencies"; @import "helium/particles/owlcarousel";',
    'nucleus' => '@import "nucleus";',
};
echo $compiler->compileString($source)->getCss();
