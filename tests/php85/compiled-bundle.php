<?php
/**
 * Full offline render registry integration using native SCSS, Twig and Minify.
 * php tests/php85/compiled-bundle.php /path/to/vendor/autoload.php [source-root]
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Config\Config;
use Gantry\Component\Content\Document\HtmlDocument;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Streams;
use Gantry\Component\Layout\Layout;
use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Render\RenderRegistry;
use Gantry\Component\Theme\AbstractTheme;
use Gantry\Component\Theme\ThemeTrait;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Services\ConfigServiceProvider;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) { throw new RuntimeException('Use actual PHP 8.5 and native dependencies.'); }
$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$temporary = realpath(sys_get_temp_dir()) . '/g6-bundle-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GRAV_ROOT', $temporary);
define('GANTRY5_ROOT', $temporary);
define('GANTRY5_PLATFORM', 'joomla');
define('GANTRY5_VERSION', '6.0.2-rc.1');
define('GANTRY5_LIBRARY', dirname(realpath($argv[1]), 2));
define('GANTRY_DEBUGGER', false);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed full bundle: ' . $label); }
    ++$checks;
};
$reset = static function (): void {
    // Request boundary only in this isolated CLI fixture; no product reset bypass.
    foreach (['pinned', 'failure'] as $field) { (new ReflectionProperty(BundleRuntime::class, $field))->setValue(null, null); }
    Layout::resetCache();
};
$refuse = static function (callable $operation, string $label, int $code = 503) use ($expect): void {
    try { $operation(); throw new LogicException('An operation unexpectedly succeeded: ' . $label); }
    catch (RuntimeException $error) { $expect($error->getCode() === $code, $label . ' (observed ' . $error->getMessage() . ')'); }
    (new ReflectionProperty(BundleRuntime::class, 'failure'))->setValue(null, null);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $remove($entry->getPathname()); } }
        rmdir($path);
    } else { unlink($path); }
};
$schemes = ['gantry-config', 'gantry-cache', 'gantry-theme', 'gantry-themes', 'gantry-layouts', 'gantry-engine', 'gantry-assets', 'gantry-particles', 'gantry-blueprints'];
try {
    foreach (['themes/g6_fixture/gantry', 'themes/g6_fixture/config/default', 'themes/g6_fixture/config/20', 'themes/g6_fixture/custom', 'themes/g6_fixture/scss', 'themes/g6_fixture/css-compiled', 'themes/g6_fixture/templates', 'engine/css-compiled', 'engine/templates', 'engine/particles', 'engine/blueprints', 'engine/layouts', 'assets/css', 'assets/js', 'cache', 'render'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    $themeRoot = $temporary . '/themes/g6_fixture';
    copy($source . '/themes/hydrogen/common/layouts/default.yaml', $themeRoot . '/config/default/layout.yaml');
    foreach (['_body_only', '_error', '_offline'] as $system) { copy($source . '/themes/hydrogen/common/layouts/' . $system . '.yaml', $temporary . '/engine/layouts/' . $system . '.yaml'); }
    file_put_contents($themeRoot . '/config/default/styles.yaml', "accent:\n  color-1: '#123456'\n");
    file_put_contents($themeRoot . '/config/default/page.yaml', "fontawesome:\n  enable: false\nassets:\n  version: true\n  minify: true\n  combine: true\n  css:\n    - location: 'gantry-assets://css/extra.css'\n  javascript:\n    - location: 'gantry-assets://js/first.js'\n    - location: 'gantry-assets://js/second.js'\n");
    file_put_contents($themeRoot . '/config/20/styles.yaml', "accent:\n  color-1: '#ee4b2b'\n");
    $themeYaml = "details:\n  name: JoomlaMafia Hydrogen fixture\n  version: 6.0.2-rc.1\nconfiguration:\n  dependencies:\n    gantry: 6.0.1\n  css:\n    compiler: '\\Gantry\\Component\\Stylesheet\\ScssCompiler'\n    target: gantry-theme://css-compiled\n    paths: [gantry-theme://scss]\n    files: [hydrogen]\n";
    file_put_contents($themeRoot . '/gantry/theme.yaml', $themeYaml);
    file_put_contents($themeRoot . '/gantry/presets.yaml', "preset1:\n  name: JoomlaMafia palette\n");
    file_put_contents($themeRoot . '/scss/hydrogen.scss', 'body { color: $accent-color-1; .g-container { width: 88%; } }');
    file_put_contents($temporary . '/engine/css-compiled/nucleus.css', 'html { box-sizing: border-box; } body { margin: 0; }');
    file_put_contents($temporary . '/assets/css/extra.css', "/*! Fixture asset license */\n.g-grid { display: flex; }\n");
    file_put_contents($temporary . '/assets/js/first.js', '/*! Fixture first license */ (function () { window.firstFixture = 1 + 2; }());');
    file_put_contents($temporary . '/assets/js/second.js', '(function () { window.secondFixture = 4 + 5; }());');
    file_put_contents($themeRoot . '/templates/page.html.twig', '<main class="g-container">JoomlaMafia {{ gantry.config.get("styles.accent.color-1") }} {% include "message.html.twig" %}</main>');
    file_put_contents($themeRoot . '/templates/message.html.twig', 'Generation A');
    file_put_contents($themeRoot . '/sample.yaml', "selected: base\n");
    file_put_contents($themeRoot . '/custom/sample.yaml', "selected: override-A\n");
    $locator = new class($temporary) extends UniformResourceLocator {
        public bool $blocked = false;
        public int $attempts = 0;
        private function guard(): void { if ($this->blocked) { ++$this->attempts; throw new LogicException('Runtime source discovery was attempted.'); } }
        public function findResource($uri, $absolute = true, $first = false) { $this->guard(); return parent::findResource($uri, $absolute, $first); }
        public function findResources($uri, $absolute = true, $all = false) { $this->guard(); return parent::findResources($uri, $absolute, $all); }
    };
    $streams = new Streams($locator);
    $streams->add([
        'gantry-config' => ['paths' => ['themes/g6_fixture/config']],
        'gantry-cache' => ['paths' => ['cache']],
        'gantry-theme' => ['paths' => ['themes/g6_fixture/custom', 'themes/g6_fixture']],
        'gantry-themes' => ['paths' => ['themes']],
        'gantry-layouts' => ['paths' => ['engine/layouts']],
        'gantry-engine' => ['paths' => ['engine']],
        'gantry-assets' => ['paths' => ['assets']],
        'gantry-particles' => ['paths' => ['engine/particles']],
        'gantry-blueprints' => ['paths' => ['engine/blueprints']],
    ]);
    $streams->register();
    $gantry = new Gantry();
    $gantry['locator'] = static fn () => $locator;
    $gantry['loader'] = $loader;
    $gantry['streams'] = $streams;
    $gantry['global'] = new Config(['compile_yaml' => false, 'compile_twig' => false, 'production' => true]);
    $gantry['theme.name'] = 'g6_fixture';
    $gantry['configuration'] = '20';
    $gantry['platform'] = new class($temporary) { public function __construct(private string $root) {} public function getRenderPath(): string { return $this->root . '/render'; } };
    $gantry['document'] = new class extends HtmlDocument { public static function rootUri() { return '/'; } };
    $gantry->register(new ConfigServiceProvider());
    $gantry['outlines'] = new Outlines($gantry);
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    CompiledYamlFile::$defaultCachePath = $temporary . '/cache';
    CompiledYamlFile::$defaultCaching = false;
    $gantry['outlines']->load()->duplicate('default', '20', true);
    file_put_contents($themeRoot . '/config/20/styles.yaml', "accent:\n  color-1: '#ee4b2b'\n");
    $theme = new class($themeRoot, 'g6_fixture') extends AbstractTheme {
        use ThemeTrait;
        public ?Closure $afterTwig = null;
        protected function init() {}
        protected function setTwigLoaderPaths(LoaderInterface $loader) {
            if (!$loader instanceof FilesystemLoader) { return null; }
            $loader->setPaths($this->path . '/templates');
            return $loader;
        }
        public function compileTwigBundle(string $generation, string $directory, array $inlineSources = []): array {
            $map = parent::compileTwigBundle($generation, $directory, $inlineSources);
            if ($this->afterTwig) { ($this->afterTwig)(); }
            return $map;
        }
    };
    $gantry['theme'] = $theme;
    $registry = new RenderRegistry($gantry);
    $gantry['render.registry'] = $registry;
    $expect($registry->activeId() === null, 'initial registry has no active generation');
    $revisionA = hash('sha256', 'fixture revision A');
    $a = $registry->prepare($revisionA);
    $expect($registry->activeId() === null, 'preparing a full real bundle does not activate it');
    $runtimeA = $registry->open($a);
    $expect($runtimeA->revision() === $revisionA, 'prepared revision is preserved');
    $expect($runtimeA->yaml('gantry-theme://sample.yaml')['selected'] === 'override-A', 'native override precedence is preserved in frozen URI aliases');
    $registry->activate($a, $revisionA);
    $expect($registry->activeId() === $a, 'initial activation atomically selects the complete generation');
    $assetsA = $runtimeA->outlineData('20', 'assets');
    $cssA = $assetsA['css']['hydrogen'];
    $assetPath = static fn (string $url): string => $temporary . '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    $cssABytes = file_get_contents($assetPath($cssA));
    $expect(str_contains($cssABytes, '#ee4b2b'), 'native SCSS resolves the inherited outline configuration into public immutable CSS');
    $expect(count($assetsA['pairs']) >= 2, 'real asset compiler precompiles adjacent CSS and classic-script combinations');
    $expect(isset($assetsA['files']['gantry-assets://js/first.js']), 'real Minify output is indexed by the declared stream URI');
    $firstAsset = $assetsA['files']['gantry-assets://js/first.js'];
    $firstBytes = file_get_contents($assetPath($firstAsset['url']));
    $expect(str_contains($firstBytes, 'Fixture first license') && strlen($firstBytes) < filesize($temporary . '/assets/js/first.js'), 'maintained JS minifier reduces bytes while preserving license notices');
    $expect(str_contains($assetsA['critical'], 'box-sizing') && str_contains($assetsA['critical'], 'margin'), 'real invariant critical CSS is prepared offline');
    $published = [];
    foreach (array_merge(array_values($assetsA['files']), array_values($assetsA['pairs'])) as $record) { $published[$record['url']] = $record['bytes']; }
    $validPublished = $published !== [];
    foreach ($published as $url => $bytes) {
        $path = $assetPath($url);
        $validPublished = $validPublished && is_file($path) && filesize($path) === $bytes && hash_file('sha256', $path) === pathinfo($path, PATHINFO_FILENAME);
    }
    $expect($validPublished, 'every actual published asset/combination has exact bytes and its content-addressed filename');
    BundleRuntime::pin($runtimeA);
    unset($gantry['config']);
    $gantry['config'] = ConfigServiceProvider::load($gantry, '20');
    $htmlA = $theme->render('page.html.twig');
    $expect(str_contains($htmlA, '#ee4b2b') && str_contains($htmlA, 'Generation A'), 'activated native config and precompiled Twig render together');
    $firstStatistics = $theme->renderer()->statistics();
    $expect($firstStatistics['artifact_loads'] >= 2 && $firstStatistics['source_attempts'] === 0 && $firstStatistics['compiler_attempts'] === 0, 'first request loads real immutable PHP templates without interpretation');

    // Explicit source build may prepare B while a current page remains pinned to A.
    file_put_contents($themeRoot . '/config/20/styles.yaml', "accent:\n  color-1: '#456789'\n");
    file_put_contents($themeRoot . '/templates/message.html.twig', 'Generation B');
    file_put_contents($themeRoot . '/custom/sample.yaml', "selected: override-B\n");
    file_put_contents($themeRoot . '/scss/hydrogen.scss', 'body { color: $accent-color-1; .g-container { width: 91%; } }');
    $locator->clearCache();
    $revisionB = hash('sha256', 'fixture revision B');
    $b = $registry->prepare($revisionB);
    $expect($registry->activeId() === $a, 'preparing B leaves A active');
    $runtimeB = $registry->open($b);
    $cssB = $runtimeB->outlineData('20', 'assets')['css']['hydrogen'];
    $expect($a !== $b && $cssA !== $cssB, 'a source/config edit produces a new immutable SCSS asset');
    $expect(str_contains(file_get_contents($assetPath($cssB)), '#456789') && str_contains(file_get_contents($assetPath($cssB)), '91%'), 'B contains both the changed variables and changed SCSS source');
    $registry->activate($b, $revisionB);
    $expect($registry->activeId() === $b && BundleRuntime::current()->id() === $a, 'activation of B cannot change a page pinned to A');
    $expect(str_contains($theme->render('page.html.twig'), 'Generation A') && $theme->css('hydrogen') === $cssA, 'pinned A still renders A templates and A assets after B activates');
    $expect(file_get_contents($assetPath($cssA)) === $cssABytes && is_file($assetPath($cssB)), 'old assets remain byte-exact for concurrent and cached A pages');
    $reset();
    BundleRuntime::pin($registry->current());
    unset($gantry['config']);
    $gantry['config'] = ConfigServiceProvider::load($gantry, '20');
    $expect(str_contains($theme->render('page.html.twig'), 'Generation B') && $theme->css('hydrogen') === $cssB, 'the next request pins B and renders its exact template/config/assets');

    $theme->afterTwig = static function () use ($themeRoot): void { file_put_contents($themeRoot . '/custom/sample.yaml', "selected: changed-during-build\n"); };
    $beforeDirectories = glob($registry->directory() . '/*', GLOB_ONLYDIR);
    $refuse(fn () => $registry->prepare(hash('sha256', 'racing source')), 'a source change during a real build is refused', 409);
    $theme->afterTwig = null;
    $expect($registry->activeId() === $b && glob($registry->directory() . '/*', GLOB_ONLYDIR) === $beforeDirectories, 'failed preparation removes its private generation and preserves the active pointer');
    $refuse(fn () => $registry->activate($a, $revisionB), 'a stale activation revision is refused', 409);
    $expect($registry->activeId() === $b, 'a stale activation cannot replace B');

    // Corrupt only actual compiler outputs, restoring exact original bytes after each probe.
    $manifestPath = $runtimeB->root() . '/bundle.php';
    $manifestBytes = file_get_contents($manifestPath);
    $manifest = require $manifestPath;
    $mutateManifest = static function (callable $change, callable $probe, string $label) use ($manifestPath, $manifestBytes, $manifest, $refuse): void {
        $changed = $change($manifest);
        file_put_contents($manifestPath, '<?php return ' . var_export($changed, true) . ';');
        try { $refuse($probe, $label); }
        finally { file_put_contents($manifestPath, $manifestBytes); }
    };
    foreach (['schema' => 99, 'php' => '8.4', 'framework' => '6.0.1', 'theme' => 'another_theme', 'id' => hash('sha256', 'other generation'), 'revision' => [], 'data' => false, 'outlines' => false] as $key => $bad) {
        $mutateManifest(static function (array $value) use ($key, $bad): array { $value[$key] = $bad; return $value; }, fn () => $registry->open($b), 'manifest identity/shape drift is refused: ' . $key);
    }
    foreach ([['file' => '../outside.php', 'sha256' => str_repeat('a', 64)], ['file' => 'data/config.php', 'sha256' => 'not-a-digest'], 'not-a-descriptor'] as $bad) {
        $mutateManifest(static function (array $value) use ($bad): array { $value['data']['twig'] = $bad; return $value; }, fn () => $registry->open($b), 'malformed artifact descriptor is refused');
    }
    $mutateManifest(static function (array $value): array { $value['data']['presets']['sha256'] = str_repeat('f', 64); return $value; }, fn () => $registry->open($b)->data('presets'), 'well-formed descriptor digest drift is refused before inclusion');
    $mutateManifest(static function (array $value): array { unset($value['outlines']['20']['layout']); return $value; }, fn () => $registry->open($b)->outlineData('20', 'layout'), 'a missing declared outline artifact is refused');
    $artifact = $runtimeB->root() . '/' . $manifest['outlines']['20']['layout']['file'];
    $artifactBytes = file_get_contents($artifact);
    unlink($artifact);
    try { $refuse(fn () => $registry->open($b)->outlineData('20', 'layout'), 'a missing artifact file is refused'); }
    finally { file_put_contents($artifact, $artifactBytes); }
    file_put_contents($artifact, $artifactBytes . "\n// corrupt bytes\n");
    try { $refuse(fn () => $registry->open($b)->outlineData('20', 'layout'), 'a corrupt artifact file is refused'); }
    finally { file_put_contents($artifact, $artifactBytes); }
    $twigEntry = $runtimeB->data('twig')['templates']['message.html.twig'];
    $twigArtifact = $runtimeB->root() . '/' . $twigEntry['file'];
    $twigBytes = file_get_contents($twigArtifact);
    file_put_contents($twigArtifact, $twigBytes . "\n// corrupt Twig bytes\n");
    try {
        $theme->resetRenderState();
        $refuse(static function () use ($theme): void {
            try { $theme->render('page.html.twig'); }
            catch (\Twig\Error\RuntimeError $error) {
                // Native Twig wraps included-template errors. The request's sticky
                // health check must still surface the original controlled 503.
                BundleRuntime::assertHealthy();
                throw $error;
            }
        }, 'corrupt included Twig leaves a controlled sticky 503 even after its class loaded earlier');
    } finally { file_put_contents($twigArtifact, $twigBytes); }
    $pointer = $registry->directory() . '/current.php';
    $pointerBytes = file_get_contents($pointer);
    file_put_contents($pointer, "<?php exit; ?>\nmalformed\n");
    try { $refuse(fn () => $registry->activeId(), 'a malformed active pointer is refused'); }
    finally { file_put_contents($pointer, $pointerBytes); }

    // Remove every input source category, retaining only prepared public/private artifacts.
    $reset();
    BundleRuntime::pin($registry->current());
    foreach (['gantry', 'config', 'custom', 'scss', 'templates', 'css-compiled'] as $name) { rename($themeRoot . '/' . $name, $themeRoot . '/' . $name . '-removed'); }
    rename($temporary . '/engine', $temporary . '/engine-removed');
    rename($temporary . '/assets', $temporary . '/assets-removed');
    $locator->blocked = true;
    $theme->resetRenderState();
    unset($gantry['config'], $gantry['blueprints']);
    $gantry['blueprints'] = ConfigServiceProvider::blueprints($gantry);
    $gantry['config'] = ConfigServiceProvider::load($gantry, '20');
    $expect(str_contains($theme->render('page.html.twig'), '#456789') && str_contains($theme->render('page.html.twig'), 'Generation B'), 'real prepared B renders after all Twig/YAML/SCSS/config input directories are removed');
    $expect($theme->css('hydrogen') === $cssB && is_file($assetPath($cssB)) && is_file($assetPath($cssA)), 'compiled rendering keeps both asset generations without SCSS inputs');
    $expect(BundleRuntime::current()->yaml('gantry-theme://sample.yaml')['selected'] === 'override-B', 'frozen override data remains B after sources changed and disappeared');
    $expect(count(Layout::instance('20')->references()) > 10 && count(Layout::instance('20')->inherit()) > 0, 'real resolved Hydrogen inheritance hydrates after layout sources disappear');
    $expect($locator->attempts === 0, 'full compiled path performs zero source file or directory discovery');
    $statistics = $theme->renderer()->statistics();
    $expect($statistics['source_attempts'] === 0 && $statistics['compiler_attempts'] === 0, 'subsequent compiled Twig rendering performs zero source reads or compiler calls');
    BundleRuntime::assertHealthy();
    echo "PASS compiled-bundle: {$checks} real compiler/registry integration checks on PHP " . PHP_VERSION . "\n";
} finally {
    $reset();
    foreach ($schemes as $scheme) { if (in_array($scheme, stream_get_wrappers(), true)) { stream_wrapper_unregister($scheme); } }
    $remove($temporary);
}
