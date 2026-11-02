<?php
/**
 * #2894: render the shipped Font Awesome selectors and resolve actual asset bytes.
 * php tests/php85/issue-2894.php /path/to/vendor/autoload.php [native-source-root]
 * Browser glyph/font/SVG assertions are in tests/frontend/issue-2894.js.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Twig\TokenParser\TokenParserAssets;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and the native Gantry Composer autoloader.');
}
$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$twig = new Environment(new FilesystemLoader($source . '/engines/common/nucleus/templates'));
$twig->addExtension(new class extends AbstractExtension {
    public function getTokenParsers(): array { return [new TokenParserAssets()]; }
});
$twig->addFunction(new TwigFunction('url', static fn (string $url): string => $url));
$twig->addFunction(new TwigFunction('parse_assets', static function (): void {}));
$twig->addFilter(new TwigFilter('html', static fn (string $html): string => $html));
$template = $twig->load('page_head.html.twig');
$checks = [];
$expect = static function (bool $condition, string $label) use (&$checks): void {
    $checks[] = ['name' => $label, 'pass' => $condition];
    if (!$condition) { throw new RuntimeException('Failed #2894: ' . $label); }
};
$selections = [];
foreach (['fa5css', 'fa5js', 'fa7css', 'fa7js'] as $version) {
    $context = ['faEnabled' => true, 'faVersion' => $version, 'gantry' => [
        'config' => ['page' => ['fontawesome' => ['enable' => 1, 'version' => $version,
            'fa4_compatibility' => 1, 'content_compatibility' => 1, 'html_js_import' => '', 'html_css_import' => '']]],
        'theme' => ['configuration' => ['css' => ['overrides' => []]]],
    ]];
    $html = $template->renderBlock('head_scripts', $context) . $template->renderBlock('head_overrides', $context);
    preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);
    $expect(count($matches[1]) > 0, $version . ' actually emits its selected library');
    $assets = ['css' => [], 'js' => []];
    foreach ($matches[1] as $uri) {
        $expect(str_starts_with($uri, 'gantry-assets://'), $version . ' uses the native asset stream');
        $path = $source . '/assets/common/' . substr($uri, strlen('gantry-assets://'));
        $expect(is_file($path) && filesize($path) > 0, $version . ' emitted asset exists: ' . $uri);
        $type = str_ends_with($path, '.css') ? 'css' : 'js';
        $assets[$type][] = $path;
        if ($type !== 'css') { continue; }
        $fa6 = str_contains(basename($path), 'font-awesome6-');
        if ($fa6) {
            $expect(str_contains(substr(file_get_contents($path), 0, 300), 'Font Awesome Free 6.5.1'),
                'every emitted FA6 stylesheet identifies its actual matching version');
        }
        preg_match_all('/url\(([^)]+)\)/', file_get_contents($path), $fonts);
        foreach (array_unique($fonts[1]) as $url) {
            $url = trim($url, " \"'");
            if (str_starts_with($url, 'data:')) { continue; }
            $clean = preg_split('/[?#]/', $url, 2)[0];
            $font = realpath(dirname($path) . '/' . $clean);
            $expect($font !== false && str_starts_with($font, $source . '/assets/common/webfonts/') && filesize($font) > 0,
                $version . ' stylesheet font resolves to shipped bytes: ' . $url);
            if ($fa6) {
                $expect(str_contains($font, '/webfonts/fa6/'), 'every FA6 font reference uses its matching versioned font bytes');
            }
        }
    }
    if ($version === 'fa5js') {
        $expect(count($assets['js']) === 2, 'FA6 SVG mode includes its library and optional v4 shim');
        foreach ($assets['js'] as $path) {
            $expect(str_contains(substr(file_get_contents($path), 0, 300), '6.5.1'), 'FA6 SVG asset is the matching official 6.5.1 distribution');
        }
    }
    $selections[$version] = $assets;
}
foreach (['fa-solid-900', 'fa-regular-400', 'fa-brands-400', 'fa-v4compatibility'] as $font) {
    $path = $source . '/assets/common/webfonts/fa6/' . $font . '.ttf';
    $expect(is_file($path) && str_contains(file_get_contents($path), mb_convert_encoding('6.5.1', 'UTF-16BE', 'UTF-8')),
        $font . ' contains actual matching 6.5.1 native font metadata');
}
$license = $source . '/assets/common/font-awesome6-LICENSE.txt';
$expect(is_file($license) && filesize($license) > 7000, 'complete official Font Awesome Free license is shipped');
echo json_encode(['issue' => 2894, 'checks' => $checks, 'selections' => $selections, 'php' => PHP_VERSION], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
