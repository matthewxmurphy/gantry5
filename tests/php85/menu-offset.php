<?php
/**
 * Native menu key admission under actual PHP 8.5; no installed CMS mutation.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1])) { throw new RuntimeException('Use PHP 8.5 and native dependencies.'); }
$root = $argv[2] ?? dirname(__DIR__, 2);
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $root . '/src/classes/Gantry', true);
$loader->addPsr4('Gantry\\', $root . '/src/platforms/joomla/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$menu = (new ReflectionClass(Gantry\Framework\Menu::class))->newInstanceWithoutConstructor();
$active = (object) ['enabled' => true];
$disabled = (object) ['enabled' => false];
(new ReflectionProperty($menu, 'items'))->setValue($menu, [2 => $active, 3 => $disabled]);
(new ReflectionProperty($menu, 'isAdmin'))->setValue($menu, false);
$checks = [
    $menu->offsetGet(null) === null,
    !$menu->offsetExists(null),
    $menu->offsetGet(2) === $active,
    $menu->offsetGet('2') === $active,
    $menu->offsetGet(3) === null,
    $menu->offsetGet(999) === null,
];
(new ReflectionProperty($menu, 'isAdmin'))->setValue($menu, true);
$checks[] = $menu->offsetGet(3) === $disabled;
if (in_array(false, $checks, true)) { throw new RuntimeException('Native menu lookup regression.'); }
echo 'PASS menu-offset: ' . count($checks) . ' native PHP ' . PHP_VERSION . " checks\n";
