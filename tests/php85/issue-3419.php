<?php
/**
 * #3419: execute the shipped Joomla head block, never load debug.css with debug off.
 * php tests/php85/issue-3419.php /path/to/vendor/autoload.php [native-source-root]
 * The version object is a branch fixture, not an installed Joomla acceptance claim.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and the native Gantry Composer autoloader.');
}
require $argv[1];
$source = $argv[2] ?? dirname(__DIR__, 2);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$twig = new Environment(new ChainLoader([
    new ArrayLoader(['@nucleus/page_head.html.twig' => '{% block head_platform %}{% endblock %}']),
    new FilesystemLoader($source . '/engines/joomla/nucleus/twig/partials'),
]));
$twig->addFunction(new TwigFunction('url', static fn (string $url): string => $url));
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed #3419: ' . $label); }
    ++$checks;
};
foreach ([4, 5, 6] as $version) {
    $platform = new class($version) {
        public function __construct(private readonly int $version) {}
        public function checkVersion(int $minimum): bool { return $this->version >= $minimum; }
    };
    foreach ([false, true] as $debug) {
        $html = $twig->render('page_head.html.twig', ['gantry' => [
            'platform' => $platform, 'theme' => ['joomla' => true],
            'page' => ['direction' => 'ltr'], 'debug' => $debug,
        ]]);
        $expect(str_contains($html, 'debug.css') === $debug, "Joomla{$version} debug flag controls debug stylesheet");
        $expect(substr_count($html, 'debug.css') === ($debug ? 1 : 0), "Joomla{$version} has no duplicate or production debug asset");
        $expect(str_contains($html, 'bootstrap5.css'), "Joomla{$version} normal platform CSS remains available");
        if ($debug) {
            $expected = $version >= 5 ? 'media/plg_system_debug/css/debug.css' : 'media/system/css/debug.css';
            $expect(str_contains($html, $expected), "Joomla{$version} uses its native debug asset location");
        }
    }
}
$html = $twig->render('page_head.html.twig', ['gantry' => [
    'platform' => new class { public function checkVersion(int $minimum): bool { return true; } },
    'theme' => ['joomla' => false], 'page' => ['direction' => 'ltr'], 'debug' => true,
]]);
$expect(!str_contains($html, 'debug.css'), 'non-Joomla themes never receive Joomla debug assets');
echo "PASS issue-3419: {$checks} native Twig rendering checks on PHP " . PHP_VERSION . "\n";
