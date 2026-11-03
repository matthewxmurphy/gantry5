<?php
/**
 * Native Joomla 6.2 WAM graph/locking and Gantry head/footer regression.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
use Gantry\Component\Render\Assets\JoomlaAssets;
use Gantry\Component\Content\Block\HtmlBlock;
use Gantry\Framework\Gantry;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Document\Renderer\Html\StylesRenderer;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\CMS\WebAsset\Exception\InvalidActionException;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[3]) || !is_file($argv[1]) || !is_file($argv[2]) || !is_file($argv[3] . '/WebAsset/WebAssetManager.php')) { throw new RuntimeException('Use PHP8.5, Gantry/Joomla vendor autoloaders and the native Joomla6.2 libraries/src directory.'); }
$loader = require $argv[1]; $loader->addPsr4('Gantry\\', dirname(__DIR__, 2) . '/src/classes/Gantry', true);
$native = require $argv[2]; $native->addPsr4('Joomla\\CMS\\', rtrim($argv[3], '/'), true);
$map = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[3], FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') { $map['Joomla\\CMS\\' . str_replace('/', '\\', substr($file->getPathname(), strlen(rtrim($argv[3], '/')) + 1, -4))] = $file->getPathname(); }
}
$native->addClassMap($map);
define('_JEXEC', 1); define('JDEBUG', false);
error_reporting(E_ALL); set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) { throw new RuntimeException('Failed: ' . $message); } ++$checks; };
$wam = static fn (): WebAssetManager => new WebAssetManager(new WebAssetRegistry());
$file = static fn (string $uri, array $extra = []): array => array_replace([':type' => 'file', 'src' => $uri, 'href' => $uri, 'type' => 'text/javascript', 'defer' => false, 'async' => false], $extra);
$inline = static fn (string $text): array => [':type' => 'inline', 'content' => $text, 'type' => 'text/javascript'];
$manager = $wam();
$manager->registerAndUseScript('native', '/media/native.js', ['version' => false], ['defer' => true]);
$manager->registerAndUseStyle('native.style', '/media/native.css', ['version' => false]);
JoomlaAssets::head($manager, 'style', [$file('/compiled/a.css', ['type' => 'text/css']), $file('/compiled/b.css', ['type' => 'text/css'])], 'body{margin:0}');
$styles = $manager->getAssets('style', true);
$check(isset($styles['native.style']) && $styles['native.style']->getUri(false) === '/media/native.css', 'native stylesheet is untouched');
$styleFiles = array_filter($styles, static fn ($asset) => !$asset->getOption('inline') && $asset->getOption('gantry.location') === 'head');
$check(count($styleFiles) === 2 && array_values($styleFiles)[0]->getUri(false) === '/compiled/a.css', 'native graph preserves Gantry CSS order');
$check(!array_key_exists('media', array_values($styleFiles)[0]->getAttributes()), 'absent CSS media is not serialized by native WAM as media=null');
$inlineStyles = $manager->filterOutInlineAssets($styles); $relation = $manager->getInlineRelation($inlineStyles);
$check(count($relation[array_key_first($styleFiles)]['before'] ?? []) === 1, 'critical CSS is a native before relation');
JoomlaAssets::head($manager, 'script', [$file('/compiled/head.js', ['dependencies' => ['native'], 'element' => ['integrity' => 'sha384-test', 'crossorigin' => 'anonymous']])]);
$head = $manager->getAssets('script', true);
$check(array_key_first($head) === 'native' && count($head) === 2, 'native header dependencies remain authoritative');
$gantryHead = array_values($head)[1];
$check($gantryHead->getAttributes()['integrity'] === 'sha384-test' && $gantryHead->getAttributes()['crossorigin'] === 'anonymous', 'integrity and CORS preserved');
$check($gantryHead->getVersion() === '', 'immutable/native URI receives no second version');
$records = [$inline('before();'), $file('/compiled/footer-a.js', ['dependencies' => ['native'], 'handle' => 'gantry.footer.a']), $inline('middle();'), $file('/compiled/footer-b.js'), $inline('after();')];
$footer = JoomlaAssets::footer($manager, $records, 'nonce-fixture'); $html = implode('', $footer);
$check(count($footer) === 5, 'exactly five Gantry footer records emitted');
$check(strpos($html, 'before();') < strpos($html, 'footer-a.js') && strpos($html, 'footer-a.js') < strpos($html, 'middle();') && strpos($html, 'middle();') < strpos($html, 'footer-b.js') && strpos($html, 'footer-b.js') < strpos($html, 'after();'), 'inline footer barriers preserve execution order');
$check(substr_count($html, 'nonce="nonce-fixture"') === 5, 'native CSP nonce retained in footer');
$active = $manager->getAssets('script', true);
$check(count($active) === 2 && isset($active['native']) && !isset($active['gantry.footer.a']), 'footer handles removed from head with native dependencies preserved');
$check(implode('', JoomlaAssets::footer($manager, $records)) !== '', 'repeated footer materialization is deterministic');
$check(count($manager->getAssets('script', true)) === 2, 'repeated footer does not duplicate head registrations');
$conflict = false;
try { JoomlaAssets::head($manager, 'script', [$file('/compiled/bad-head.js', ['dependencies' => ['gantry.footer.a']])]); } catch (RuntimeException $error) { $conflict = str_contains($error->getMessage(), 'footer'); }
$check($conflict, 'head-to-footer dependency is refused');
$manager = $wam(); $manager->registerAndUseScript('native', '/native.js'); $manager->lock();
$before = (new ReflectionProperty(WebAssetRegistry::class, 'assets'))->getValue($manager->getRegistry()); $locked = false;
try { JoomlaAssets::head($manager, 'script', [$file('/late.js')]); } catch (InvalidActionException) { $locked = true; }
$check($locked, 'native WAM lock is enforced');
$check($before === (new ReflectionProperty(WebAssetRegistry::class, 'assets'))->getValue($manager->getRegistry()), 'locked registration leaves native registry unchanged');
$manager = $wam(); $manager->registerAndUseScript('native', '/native.js');
$refused = false;
try { JoomlaAssets::head($manager, 'script', [$file('/replace.js', ['handle' => 'native'])]); } catch (RuntimeException) { $refused = true; }
$check($refused && $manager->getAsset('script', 'native')->getUri(false) === '/native.js', 'native handle cannot be replaced');
$manager = $wam();
$deferred = JoomlaAssets::footer($manager, [$file('/a.js', ['defer' => true]), $file('/b.js', ['defer' => true])]);
$check(count($deferred) === 2 && substr_count(implode('', $deferred), ' defer') === 2, 'declared dependency-ordered defer preserved');

// Exercise getUri(true) through the actual Joomla renderer, rather than only
// inspecting stored getUri(false) values. Native mediaPath would otherwise turn
// an already resolved /media/... path into protocol-relative //media/....
$coreSource = dirname(rtrim($argv[3], '/'), 2) . '/media/system/css/joomla-fontawesome.min.css';
if (!is_file($coreSource) || !is_file($argv[3] . '/Document/Renderer/Html/StylesRenderer.php')) { throw new RuntimeException('Prepare the pinned full Joomla fixture, including its official Font Awesome stylesheet.'); }
$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-wam-render-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$remove = static function (string $path) use (&$remove): void { if (is_dir($path) && !is_link($path)) { foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) { $remove($child->getPathname()); } rmdir($path); } else { unlink($path); } };
try {
    mkdir($temporary . '/media/system/css', 0700, true);
    $corePath = $temporary . '/media/system/css/joomla-fontawesome.min.css';
    copy($coreSource, $corePath); touch($corePath, 1700000000);
    file_put_contents($temporary . '/origin-root.css', 'body{color:inherit}');
    define('JPATH_ROOT', $temporary); define('JPATH_PUBLIC', $temporary); define('GANTRY5_ROOT', $temporary);
    $loader->addClassMap(['Gantry\\Framework\\Document' => dirname(__DIR__, 2) . '/src/platforms/joomla/classes/Gantry/Framework/Document.php']);
    $gantry = new Gantry();
    $locator = new UniformResourceLocator($temporary);
    $gantry['locator'] = static fn () => $locator;
    $gantry['document'] = new \Gantry\Framework\Document();
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    $check(hash_file('sha256', $corePath) === hash_file('sha256', $coreSource) && filesize($corePath) > 100000, 'URL regression uses the actual pinned Joomla core stylesheet');
    foreach (['', '/cms'] as $prefix) {
        (new ReflectionProperty(Uri::class, 'root'))->setValue(null, ['prefix' => 'https://joomlamafia.example', 'path' => $prefix]);
        $manager = $wam();
        $manager->registerAndUseStyle('native.core', 'media/system/css/joomla-fontawesome.min.css', ['version' => false]);
        $block = new HtmlBlock();
        $inputs = [
            'media/system/css/joomla-fontawesome.min.css',
            $prefix . '/compiled/fixture.css?g6=fixture',
            '/origin-root.css',
            'https://joomlamafia.example' . $prefix . '/absolute.css',
            '//cdn.example/explicit.css',
            'https://cdn.example/absolute.css',
        ];
        foreach ($inputs as $uri) { $block->addStyle(['href' => $uri]); }
        $records = array_values($block->getStyles());
        $resolved = array_column($records, 'href');
        $expected = $inputs; $expected[0] = $prefix . '/media/system/css/joomla-fontawesome.min.css';
        $check($resolved === $expected, 'native Gantry document preserves root/subdirectory/absolute/protocol-relative URL meanings: ' . ($prefix ?: '/'));
        JoomlaAssets::head($manager, 'style', $records, 'body{margin:0}');
        $nativeAsset = $manager->getAsset('style', 'native.core');
        $check($nativeAsset::class === \Joomla\CMS\WebAsset\WebAssetItem::class && $nativeAsset->getUri(false) === $inputs[0], 'mixed queue retains the original native core asset and URI: ' . ($prefix ?: '/'));
        $doc = new HtmlDocument(['webAssetManager' => $manager, 'mediaversion' => 'fixture']);
        $html = (new StylesRenderer($doc))->render('styles');
        $dom = new DOMDocument(); $dom->loadHTML('<html><head>' . $html . '</head><body></body></html>');
        $hrefs = []; foreach ($dom->getElementsByTagName('link') as $link) { $hrefs[] = $link->getAttribute('href'); }
        $check($hrefs === $expected, 'actual native StylesRenderer emits exact ordered same-origin and external URLs without duplicate core CSS: ' . ($prefix ?: '/') . ' got ' . json_encode($hrefs, JSON_UNESCAPED_SLASHES));
        $check(!str_contains($html, 'href="//media/') && !str_contains($html, 'href="http://media/') && substr_count($html, 'joomla-fontawesome.min.css') === 1, 'rendered core CSS never becomes host media and is emitted exactly once: ' . ($prefix ?: '/'));
        $check(strpos($html, 'body{margin:0}') < strpos($html, 'compiled/fixture.css') && $manager->getAsset('style', 'native.core') === $nativeAsset, 'native WAM still owns inline placement, ordering and core handle identity: ' . ($prefix ?: '/'));
    }
    Uri::reset();
} finally { $remove($temporary); }
echo "PASS $checks native Joomla WAM checks on PHP " . PHP_VERSION . "\n";
