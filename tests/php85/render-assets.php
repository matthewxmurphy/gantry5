<?php
/**
 * Actual PHP minifier/GD and native-record immutable asset regressions.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Render\Assets\AssetCompiler;
use Gantry\Component\Render\Assets\AssetRuntime;
use Gantry\Component\Render\Assets\ImagesRuntime;
use Gantry\Component\Render\Assets\LiteralCssMinifier;
use Gantry\Component\Render\Assets\LiteralJsMinifier;
use Gantry\Component\Render\Assets\PublicSources;
use Gantry\Framework\Gantry;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) { throw new RuntimeException('Use PHP 8.5 and the real Gantry Composer autoloader.'); }
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', dirname(__DIR__, 2) . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-assets-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('_JEXEC', 1); define('GANTRY5_ROOT', $temporary); define('GANTRY_DEBUGGER', false);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) { throw new RuntimeException('Failed: ' . $message); } ++$checks; };
$remove = static function (string $path) use (&$remove): void { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) { $remove($item->getPathname()); } rmdir($path); };
$style = static fn (string $uri, array $extra = []): array => array_replace([':type' => 'file', ':priority' => 0, 'href' => $uri, 'type' => 'text/css', 'media' => null, 'element' => []], $extra);
$script = static fn (string $uri, array $extra = []): array => array_replace([':type' => 'file', ':priority' => 0, 'src' => $uri, 'type' => 'text/javascript', 'defer' => false, 'async' => false, 'element' => [], 'dependencies' => [], 'handle' => ''], $extra);
try {
    foreach (['engine/css-compiled', 'assets/css', 'assets/js', 'assets/webfonts', 'theme/custom/css-compiled', 'theme/js', 'theme/particles', 'images/restricted', 'administrator/protected', 'private', 'images/.hidden'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-engine', '', $temporary . '/engine');
    $locator->addPath('gantry-assets', '', $temporary . '/assets');
    $locator->addPath('gantry-theme', '', [$temporary . '/theme/custom', $temporary . '/theme']);
    $locator->addPath('gantry-particles', '', $temporary . '/theme/particles');
    $locator->addPath('gantry-media', '', $temporary . '/images');
    $gantry = new Gantry(); $gantry['locator'] = static fn () => $locator;
    $gantry['theme.name'] = 'fixture'; $gantry['configuration'] = '19';
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    file_put_contents($temporary . '/engine/css-compiled/nucleus.css', 'html { box-sizing: border-box; } body { margin: 0; } @media(max-width:600px){body{background:red}}');
    file_put_contents($temporary . '/assets/css/a.css', '/*! native license */ /** @license native license two */ /** @preserve native notice */ .a { color: red; background:url("../../images/main.png"); }');
    file_put_contents($temporary . '/assets/css/b.css', '.b { color: blue; }');
    file_put_contents($temporary . '/assets/css/import.css', '@import "b.css"; .c { color: green; }');
    file_put_contents($temporary . '/assets/css/same.css', '.b { color: blue; }');
    file_put_contents($temporary . '/assets/js/a.js', '/*! @gantry-combine-safe */ (function(){ window.fixture = (window.fixture || "") + "a"; })();');
    file_put_contents($temporary . '/assets/js/b.js', '/*! @gantry-combine-safe */ (function(){ window.fixture += "b"; })();');
    file_put_contents($temporary . '/assets/js/module.js', 'import x from "./other.js"; export default x;');
    file_put_contents($temporary . '/assets/js/self.js', 'window.fixture = document.currentScript.src;');
    file_put_contents($temporary . '/theme/custom/css-compiled/helium_19.css', 'body { color: #4883b0; }');
    file_put_contents($temporary . '/theme/particles/photo.html.twig', '<script src="gantry-assets://js/a.js"></script><script src="gantry-assets://js/b.js"></script>');
    file_put_contents($temporary . '/administrator/protected/private.css', 'PRIVATE-CSS-SENTINEL{color:red}');
    file_put_contents($temporary . '/private/private.js', 'PRIVATE-JS-SENTINEL');
    file_put_contents($temporary . '/images/restricted/.htaccess', 'Require all denied');
    file_put_contents($temporary . '/assets/css/denied.css', '.x{background:url("../../administrator/protected/private.png")}');
    foreach (['images/main.png' => [960, 480, 72], 'images/secondary.png' => [32, 16, 120], 'administrator/protected/private.png' => [48, 24, 201], 'images/restricted/denied.png' => [40, 20, 207], 'images/.hidden/denied.png' => [40, 20, 209]] as $file => [$width, $height, $red]) {
        $image = imagecreatetruecolor($width, $height); imagefill($image, 0, 0, imagecolorallocate($image, $red, 83, 176)); imagepng($image, $temporary . '/' . $file);
    }
    symlink($temporary . '/administrator/protected/private.png', $temporary . '/images/escape.png');
    $nativeCss = '/theme/custom/css-compiled/helium_19.css';
    $page = ['fontawesome' => ['enable' => false], 'assets' => ['css' => [['location' => 'gantry-assets://css/a.css'], ['location' => 'gantry-assets://css/b.css'], ['location' => 'gantry-assets://css/import.css'], ['location' => 'gantry-assets://css/same.css'], ['location' => 'gantry-assets://css/denied.css'], ['location' => '/administrator/protected/private.css']],
        'javascript' => [['location' => 'gantry-assets://js/module.js'], ['location' => 'gantry-assets://js/self.js'], ['location' => '/private/private.js']], 'main_image' => 'gantry-media://main.png']];
    $outlines = ['19' => ['config' => ['page' => $page], 'layout' => [(object) ['id' => 'photo-1', 'type' => 'particle', 'subtype' => 'photo', 'attributes' => ['image' => 'gantry-media://main.png']]], 'css' => ['helium' => $nativeCss]]];
    $html = '<img src="/images/main.png" alt="JoomlaMafia &amp; friends"><img src="/images/secondary.png" width="16" alt="Second"><img src="/administrator/protected/private.png"><img src="/images/restricted/denied.png"><img src="/images/.hidden/denied.png"><img src="/images/escape.png"><img src="https://example.invalid/remote.png">';
    $compiler = new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets');
    $manifest = $compiler->prepare($outlines, [$html])['19'];
    AssetRuntime::validate($manifest);
    $check($manifest['schema'] === 1 && isset($manifest['css']['helium']), 'manifest contains mandatory compiled theme CSS');
    $check(str_starts_with($manifest['css']['helium'], '/theme/custom/compiled-assets/') && $manifest['css']['helium'] !== $nativeCss, 'native theme CSS is frozen');
    $check(isset($manifest['particles']['photo-1']) && count($manifest['particles']['photo-1']['assets']) === 2, 'per-particle asset references recorded');
    $check(!isset($manifest['files']['/administrator/protected/private.css'], $manifest['files']['/private/private.js']), 'private CSS/JS sources have no prepared aliases');
    $check(!isset($manifest['files']['gantry-assets://css/denied.css']), 'stylesheet with unproven dependency keeps native location');
    $check(!isset($manifest['files']['gantry-assets://js/module.js'], $manifest['files']['gantry-assets://js/self.js']), 'module and self-location scripts keep native locations');
    foreach (['/administrator/protected/private.png', '/images/restricted/denied.png', '/images/.hidden/denied.png', '/images/escape.png', 'https://example.invalid/remote.png'] as $uri) { $check(!isset($manifest['images'][$uri]), 'denied image has no public derivative alias: ' . $uri); }
    $check(isset($manifest['images']['/images/main.png'], $manifest['images']['gantry-media://main.png']), 'local public image aliases prepared');
    $check($manifest['images']['/images/main.png']['width'] === 960 && $manifest['images']['/images/main.png']['height'] === 480, 'native image dimensions retained');
    $check(count($manifest['images']['/images/main.png']['avif']) === 3 && count($manifest['images']['/images/main.png']['webp']) === 3, 'responsive AVIF/WebP sizes encoded');
    $check($compiler->statistics()['image_encodes'] === 8, 'only admitted local raster images encoded');
    $check(str_contains($manifest['critical'], 'box-sizing') && !str_contains($manifest['critical'], 'background:red'), 'critical CSS never lifts media-scoped declarations');
    $a = $manifest['files']['gantry-assets://css/a.css']; $b = $manifest['files']['gantry-assets://css/b.css'];
    $check($b['url'] !== $manifest['files']['gantry-assets://css/same.css']['url'], 'equal file bytes retain distinct logical URL identities');
    $prepared = file_get_contents($temporary . parse_url($a['url'], PHP_URL_PATH));
    $check(str_contains($prepared, '/theme/custom/compiled-assets/') && !str_contains($prepared, '../../images'), 'relative CSS dependencies frozen and rebased');
    $check(str_contains($prepared, 'native license') && str_contains($prepared, '@license native license two') && str_contains($prepared, '@preserve native notice'), 'important source license notices retained');
    $records = [$style('gantry-assets://css/a.css'), $style('gantry-assets://css/b.css')];
    $combined = AssetRuntime::substitute($records, 'style', $manifest);
    $check(count($combined) === 1 && $combined[0]['href'] !== $a['url'], 'prepared adjacent style pair used');
    $inline = [':type' => 'inline', ':priority' => 0, 'content' => '.inline{color:black}', 'type' => 'text/css'];
    $check(count(AssetRuntime::substitute([$records[0], $inline, $records[1]], 'style', $manifest)) === 3, 'inline style is a combination barrier');
    foreach ([['element' => ['integrity' => 'sha384-test']], ['element' => ['crossorigin' => 'anonymous']], ['id' => 'specific'], ['dependencies' => ['native.style']]] as $barrier) {
        $input = $style('gantry-assets://css/a.css', $barrier);
        $check(AssetRuntime::substitute([$input], 'style', $manifest)[0] === $input, 'attributes and dependencies preserve native URL');
    }
    $check(count(AssetRuntime::substitute([$records[0], $style('gantry-assets://css/b.css', [':priority' => 1])], 'style', $manifest)) === 2, 'priority boundary retained');
    foreach ([['async' => true], ['type' => 'module'], ['element' => ['nomodule' => true]], ['dependencies' => ['jquery']]] as $barrier) {
        $input = $script('gantry-assets://js/a.js', $barrier);
        $check(AssetRuntime::substitute([$input], 'script', $manifest)[0] === $input, 'script barrier retained');
    }
    $scripts = AssetRuntime::substitute([$script('gantry-assets://js/a.js', ['defer' => true]), $script('gantry-assets://js/b.js', ['defer' => true])], 'script', $manifest);
    $check(count($scripts) === 1 && $scripts[0]['defer'], 'declared ordered-defer pair preserved');
    $check(count(AssetRuntime::substitute([$script('gantry-assets://js/a.js', ['defer' => true]), $script('gantry-assets://js/b.js')], 'script', $manifest)) === 2, 'defer boundary retained');
    $remote = $style('https://example.invalid/style.css');
    $check(AssetRuntime::substitute([$remote], 'style', $manifest)[0] === $remote, 'external assets remain exact');
    $changed = ImagesRuntime::html('<html><body>' . $html . '</body></html>', $manifest);
    $check(substr_count($changed, '<picture>') === 2 && str_contains($changed, 'type="image/avif"') && str_contains($changed, 'type="image/webp"'), 'responsive picture formats emitted');
    $check(substr_count($changed, 'loading="eager"') === 1 && substr_count($changed, 'loading="lazy"') === 1 && str_contains($changed, 'fetchpriority="high"'), 'main image eager and secondary lazy');
    $parsed = \Dom\HTMLDocument::createFromString($changed, LIBXML_NOERROR);
    $first = $parsed->getElementsByTagName('img')->item(0); $second = $parsed->getElementsByTagName('img')->item(1);
    $check($first->getAttribute('width') === '960' && $first->getAttribute('height') === '480' && $second->getAttribute('width') === '16' && $second->getAttribute('height') === '8', 'dimensions and explicit-width ratio retained');
    $check(str_contains($changed, 'alt="JoomlaMafia &amp; friends"') && str_contains($changed, '<img src="/administrator/protected/private.png">'), 'alt and denied markup retained');
    $check(ImagesRuntime::html($changed, $manifest) === $changed, 'responsive image conversion idempotent');
    $protected = '<script>const x="<img src=\'/images/main.png\'>";</script><pre><img src="/images/main.png"></pre><!--<img src="/images/main.png">--><img src="/images/main.png" srcset="/custom.png 2x">';
    $check(ImagesRuntime::html($protected, $manifest) === $protected, 'script/code/comment/authored image markup untouched');
    $indexTimes = [];
    foreach (glob($temporary . '/theme/custom/compiled-assets/.prepared/*.php') as $file) { touch($file, 1234567890); $indexTimes[$file] = filemtime($file); }
    $again = new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets');
    $repeated = $again->prepare($outlines, [$html])['19'];
    $check($again->statistics()['image_encodes'] === 0 && $again->statistics()['minifications'] === 0 && $again->statistics()['cache_hits'] > 0, 'fresh compiler reuses verified encodes/minification');
    $check($repeated['css'] === $manifest['css'] && $repeated['images'] === $manifest['images'], 'reuse preserves exact immutable resources');
    foreach ($indexTimes as $file => $mtime) { clearstatcache(true, $file); $check(filemtime($file) === $mtime, 'verified metadata is not rewritten'); }
    $disabled = $outlines; $disabled['19']['config']['page']['assets']['version'] = false;
    $off = (new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets'))->prepare($disabled, [$html])['19'];
    $check($off['css']['helium'] === $manifest['css']['helium'], 'production CSS remains frozen with optional optimization off');
    $check(AssetRuntime::substitute($records, 'style', $off) === $records, 'optional version toggle preserves native optional assets');
    $policy = new PublicSources($gantry, $temporary);
    file_put_contents($temporary . '/images/.htaccess', 'RewriteRule ^main\\.png$ - [F,L]');
    $check($policy->resolve('/images/main.png', 'image') === null, 'native unconditional rewrite denial respected');
    file_put_contents($temporary . '/images/.htaccess', "RewriteCond %{QUERY_STRING} badpayload\nRewriteRule .* - [F]");
    $check($policy->resolve('/images/main.png', 'image') !== null, 'known false query denial does not reject public clean URL');
    unlink($temporary . '/images/.htaccess');
    foreach (['RewriteCond %{REQUEST_URI} ^/images/main\.png$' . "\n" . 'RewriteRule .* - [F]', 'RewriteRule .* - [R=403,L]', 'Redirect 403 /images/main.png', 'RedirectMatch 404 .*', 'Order deny,allow' . "\n" . 'Allow from all'] as $denial) {
        file_put_contents($temporary . '/images/.htaccess', $denial);
        $check($policy->resolve('/images/main.png', 'image') === null, 'native path/redirect/legacy access denial respected');
    }
    unlink($temporary . '/images/.htaccess');
    mkdir($temporary . '/images/member'); copy($temporary . '/images/main.png', $temporary . '/images/member/member.png');
    file_put_contents($temporary . '/images/member/.htaccess', "RewriteCond %{REQUEST_URI} ^/images/member/\nRewriteRule .* - [F]");
    $check($policy->resolve('/images/member/member.png', 'image') === null, 'nested policy sees complete request URI');
    $subsite = new PublicSources($gantry, $temporary, '/cms');
    file_put_contents($temporary . '/images/member/.htaccess', "RewriteCond %{REQUEST_URI} ^/cms/images/member/\nRewriteRule .* - [F]");
    $check($subsite->resolve('/cms/images/member/member.png', 'image') === null, 'nested policy sees CMS prefix');
    $check($policy->resolve('gantry-unknown://hidden.png', 'image') === null, 'unknown optional stream is safely refused');
    $check($subsite->dependency('/cms/images/main.png', $temporary . '/assets/css/a.css')[0] === $temporary . '/images/main.png', 'absolute CSS dependency respects CMS prefix');
    $check($subsite->dependency('../../images/main.png', $temporary . '/assets/css/a.css')[0] === $temporary . '/images/main.png', 'relative CSS dependency respects CMS prefix');
    file_put_contents($temporary . '/assets/css/subsite.css', '.subsite{background:url("/cms/images/main.png")}');
    $subOutlines = ['19' => ['config' => ['page' => ['fontawesome' => ['enable' => false]]], 'layout' => [], 'css' => ['subsite' => '/cms/assets/css/subsite.css']]];
    $subManifest = (new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/cms/theme/custom/compiled-assets'))->prepare($subOutlines)['19'];
    $check(str_starts_with($subManifest['css']['subsite'], '/cms/theme/custom/compiled-assets/'), 'subdirectory CMS CSS freezes and rebases into its own URL prefix');
    $imageBytes = file_get_contents($temporary . '/images/main.png');
    $profileHtml = '';
    foreach (['animated.png' => 'acTL', 'profile.png' => 'iCCP'] as $name => $chunk) {
        file_put_contents($temporary . '/images/' . $name, substr($imageBytes, 0, 8) . pack('N', 0) . $chunk . pack('N', crc32($chunk)) . substr($imageBytes, 8));
        $profileHtml .= '<img src="/images/' . $name . '">';
    }
    file_put_contents($temporary . '/images/profile.webp', 'RIFF' . str_repeat("\0", 300) . 'ICCP');
    $profileHtml .= '<img src="/images/profile.webp">';
    $profileManifest = (new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets'))->prepare($outlines, [$profileHtml])['19'];
    foreach (['animated.png', 'profile.png', 'profile.webp'] as $name) { $check(!isset($profileManifest['images']['/images/' . $name]), 'animation/color profile retains original source: ' . $name); }
    $corrupt = $manifest; $corrupt['images']['/images/main.png']['width'] = 0;
    $refusedImage = false;
    try { ImagesRuntime::html('<img src="/images/main.png">', $corrupt); } catch (RuntimeException) { $refusedImage = true; }
    $check($refusedImage, 'malformed dimensions fail before division or PHP warning');
    foreach (glob($temporary . '/theme/custom/compiled-assets/.prepared/*.php') as $file) {
        $guard = "<?php exit; ?>\n"; $value = json_decode(substr(file_get_contents($file), strlen($guard)), true);
        if (isset($value['fallback'])) { $value['width'] = 0; file_put_contents($file, $guard . json_encode($value)); }
    }
    $rebuilt = new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets');
    $repaired = $rebuilt->prepare($outlines, [$html])['19'];
    $check($rebuilt->statistics()['image_encodes'] === 8 && $repaired['images']['/images/main.png']['width'] === 960, 'syntactically valid malformed image cache is rejected and rebuilt');
    $failed = $outlines; $failed['19']['css']['private'] = '/administrator/protected/private.css'; $refusedPrivate = false;
    try { (new AssetCompiler($gantry, $temporary . '/theme/custom/compiled-assets', '/theme/custom/compiled-assets'))->prepare($failed, [$html]); } catch (RuntimeException) { $refusedPrivate = true; }
    $check($refusedPrivate && !is_file($temporary . '/theme/custom/compiled-assets/' . hash_file('sha256', $temporary . '/administrator/protected/private.png') . '.png'), 'failed preparation never republishes a protected image');
    file_put_contents($temporary . '/images/.htaccess', "RewriteCond %{QUERY_STRING} ^v=blocked$\nRewriteRule .* - [F]");
    $check($policy->resolve('/images/main.png?v=blocked', 'image') === null && $policy->resolve('/images/main.png?v=public', 'image') !== null, 'access policy sees admitted version query');
    unlink($temporary . '/images/.htaccess');
    foreach (['files' => ['bad' => ['id' => []]], 'pairs' => ['bad' => ['url' => []]], 'images' => ['bad' => ['width' => 0]], 'options' => ['version' => []]] as $key => $value) {
        $invalid = $manifest; $invalid[$key] = $value; $refused = false;
        try { AssetRuntime::validate($invalid); } catch (RuntimeException) { $refused = true; }
        $check($refused, 'malformed manifest rejected without diagnostics: ' . $key);
    }
    mkdir($temporary . '/images/Public'); copy($temporary . '/images/main.png', $temporary . '/images/Public/x.png');
    file_put_contents($temporary . '/images/.htaccess', "RewriteCond %{REQUEST_URI} !^/images/public/\nRewriteRule .* - [F]");
    $check($policy->resolve('/images/Public/x.png', 'image') === null, 'RewriteCond remains case-sensitive without NC');
    file_put_contents($temporary . '/images/.htaccess', "RewriteCond %{REQUEST_URI} !^/images/public/ [NC]\nRewriteRule .* - [F]");
    $check($policy->resolve('/images/Public/x.png', 'image') !== null, 'explicit RewriteCond NC is honored');
    file_put_contents($temporary . '/images/.htaccess', 'RewriteRule ^MAIN\.PNG$ - [F,NC]');
    $check($policy->resolve('/images/main.png', 'image') === null, 'RewriteRule NC denial is honored');
    foreach (['-s', '-U', '=protected', '[invalid'] as $test) {
        file_put_contents($temporary . '/images/.htaccess', 'RewriteCond %{REQUEST_FILENAME} ' . $test . "\nRewriteRule .* - [F]");
        $check($policy->resolve('/images/main.png', 'image') === null, 'unknown or invalid conditional denial is conservative: ' . $test);
    }
    unlink($temporary . '/images/.htaccess');
    $privatePath = $temporary . '/administrator/protected/private.css';
    $check(!str_contains((new LiteralCssMinifier($privatePath))->minify(), 'PRIVATE-CSS-SENTINEL'), 'CSS minifier never treats literal payload as file path');
    $check(!str_contains((new LiteralJsMinifier($privatePath))->minify(), 'PRIVATE-CSS-SENTINEL'), 'JS minifier never treats literal payload as file path');
    foreach (new FilesystemIterator($temporary . '/theme/custom/compiled-assets', FilesystemIterator::SKIP_DOTS) as $file) {
        if (!$file->isFile()) { continue; }
        $check(!str_contains(file_get_contents($file->getPathname()), 'PRIVATE-CSS-SENTINEL') && !str_contains(file_get_contents($file->getPathname()), 'PRIVATE-JS-SENTINEL'), 'no denied source bytes published');
    }
    echo "PASS $checks immutable asset/image checks on PHP " . PHP_VERSION . "\n";
} finally { $remove($temporary); }
