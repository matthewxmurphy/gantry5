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
$schemes = ['gantry-config', 'gantry-cache', 'gantry-theme', 'gantry-themes', 'gantry-layouts', 'gantry-engine', 'gantry-assets', 'gantry-particles', 'gantry-blueprints', 'gantry-admin'];
try {
    foreach (['themes/g6_fixture/gantry', 'themes/g6_fixture/config/default', 'themes/g6_fixture/config/20', 'themes/g6_fixture/custom', 'themes/g6_fixture/scss', 'themes/g6_fixture/css-compiled', 'themes/g6_fixture/templates', 'engine/css-compiled', 'engine/templates', 'engine/particles', 'engine/blueprints', 'engine/layouts', 'assets/css', 'assets/js', 'cache', 'render'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    $themeRoot = $temporary . '/themes/g6_fixture';
    copy($source . '/themes/hydrogen/common/layouts/default.yaml', $themeRoot . '/config/default/layout.yaml');
    foreach (['_body_only', '_error', '_offline'] as $system) { copy($source . '/themes/hydrogen/common/layouts/' . $system . '.yaml', $temporary . '/engine/layouts/' . $system . '.yaml'); }
    file_put_contents($themeRoot . '/config/default/styles.yaml', "accent:\n  color-1: '#123456'\n");
    file_put_contents($themeRoot . '/config/default/page.yaml', "fontawesome:\n  enable: false\nassets:\n  version: true\n  minify: true\n  combine: true\n  css:\n    - location: 'gantry-assets://css/extra.css'\n  javascript:\n    - location: 'gantry-assets://js/first.js'\n    - location: 'gantry-assets://js/second.js'\n");
    file_put_contents($themeRoot . '/config/default/page.yaml', "head:\n  atoms:\n    - id: fixture-head-assets\n      type: assets\n      attributes:\n        css:\n          - inline: '.fixture-head { color: red; }'\n    - id: fixture-head-empty\n      type: assets\n      attributes: {}\n", FILE_APPEND);
    file_put_contents($themeRoot . '/config/default/particles.yaml', "copyright:\n  enabled: true\n  owner: 0\n  date:\n    start: '2005'\n    end: '2026'\n  caching:\n    type: dynamic\nassets:\n  enabled: true\n");
    foreach (['copyright', 'assets'] as $particle) { copy($source . '/engines/common/nucleus/particles/' . $particle . '.yaml', $temporary . '/engine/particles/' . $particle . '.yaml'); }
    mkdir($temporary . '/admin', 0700);
    mkdir($themeRoot . '/templates/content', 0700);
    foreach (['particle', 'atom'] as $type) { file_put_contents($themeRoot . '/templates/content/' . $type . '.html.twig', '{{ particle|json_encode|raw }}'); }
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
        'gantry-admin' => ['paths' => ['admin']],
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
    $baseLayout = Layout::instance('default')->init();
    $baseCopyrights = $baseLayout->referencesByType('particle', 'copyright');
    $baseCopyright = reset($baseCopyrights);
    $baseCopyright->attributes->date = ['start' => '2011'];
    $baseLayout->save(false);
    Layout::resetCache();
    $gantry['outlines']->load()->duplicate('default', '20', true);
    file_put_contents($themeRoot . '/config/20/styles.yaml', "accent:\n  color-1: '#ee4b2b'\n");
    $theme = new class($themeRoot, 'g6_fixture') extends AbstractTheme {
        use ThemeTrait;
        public ?Closure $afterTwig = null;
        // This CLI fixture creates separate real request containers. Avoid the
        // trait's process-wide memo while retaining the actual native singleton.
        public static function gantry() { return Gantry::instance(); }
        protected function init() {}
        protected function setTwigLoaderPaths(LoaderInterface $loader) {
            if (!$loader instanceof FilesystemLoader) { return null; }
            $loader->setPaths($this->path . '/templates');
            $loader->setPaths($this->path . '/templates', 'nucleus');
            return $loader;
        }
        public function compileTwigBundle(string $generation, string $directory, array $inlineSources = []): array {
            $map = parent::compileTwigBundle($generation, $directory, $inlineSources);
            if ($this->afterTwig) { ($this->afterTwig)(); }
            return $map;
        }
    };
    $gantry['theme'] = $theme;
    // Use the real native schema/default merge as the reference before compiling.
    $sourceConfig = ConfigServiceProvider::load($gantry, '20');
    $sourceSchema = $sourceConfig->blueprint();
    $nativeCopyrights = Layout::instance('20')->init()->referencesByType('particle', 'copyright');
    $copyright = reset($nativeCopyrights);
    $expect($copyright instanceof stdClass && (array) $copyright->attributes === ['date' => ['start' => '2011'], 'enabled' => 1], 'shipped Hydrogen copyright supplies a real native leaf with nested array attributes');
    $copyrightAttributes = (array) $copyright->attributes;
    $nativeParticle = $sourceConfig->getJoined('particles.copyright', $copyright->attributes);
    $head = $sourceConfig->get('page.head.atoms')[0];
    $headAtom = ['type' => 'atom', 'subtype' => $head['type']] + $head;
    $pageAtom = ['id' => 'page-assets', 'type' => 'atom', 'subtype' => 'assets', 'attributes' => (array) $sourceConfig->get('page.assets') + ['enabled' => 1]];
    $nativeHead = $sourceConfig->getJoined('particles.assets', $headAtom['attributes']);
    $nativePage = $sourceConfig->getJoined('particles.assets', $pageAtom['attributes']);
    $registry = new RenderRegistry($gantry);
    $gantry['render.registry'] = $registry;
    $freshRequest = static function (BundleRuntime $runtime) use ($gantry, $locator, $streams, $loader, $theme, $registry, $reset): Gantry {
        $reset();
        BundleRuntime::pin($runtime);
        $request = new Gantry();
        $request['locator'] = static fn () => $locator;
        $request['streams'] = $streams;
        $request['loader'] = $loader;
        $request['global'] = $gantry['global'];
        $request['theme.name'] = 'g6_fixture';
        $request['configuration'] = '20';
        $request['platform'] = $gantry['platform'];
        $request['document'] = new HtmlDocument();
        $request['theme'] = $theme;
        $request['render.registry'] = $registry;
        $request->register(new ConfigServiceProvider());
        (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $request);
        $request['config'] = ConfigServiceProvider::load($request, '20');
        $theme->resetRenderState();
        return $request;
    };
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
    $sourceGantry = $gantry;
    $gantry = $freshRequest($runtimeA);
    $renderParticle = static fn ($item): array => json_decode($theme->getContent($item)->toString(), true, 512, JSON_THROW_ON_ERROR);
    $expect($renderParticle($copyright) === $nativeParticle, 'prepared real copyright equals native schema/default merge exactly');
    $expect($renderParticle($headAtom) === $nativeHead && $renderParticle($pageAtom) === $nativePage, 'head atoms and page-assets use their exact native prepared merges');
    $theme->loadAtoms();
    $manifestA = require $runtimeA->root() . '/bundle.php';
    $loadedA = (new ReflectionProperty(BundleRuntime::class, 'loaded'))->getValue($runtimeA);
    $expect(ConfigServiceProvider::canUsePreparedParticles($gantry, $gantry['config']) && !array_key_exists($manifestA['data']['blueprints']['file'], $loadedA), 'known layout leaves/head atoms/page-assets render without loading the frozen blueprint artifact');
    $invalid = (object) ['id' => 'fixture-head-empty-invalid', 'type' => 'atom', 'subtype' => 'assets', 'attributes' => false];
    try { $theme->getContent($invalid); throw new LogicException('Scalar particle attributes were admitted'); }
    catch (RuntimeException $error) { $expect(str_contains($error->getMessage(), 'Value is not an array'), 'scalar false cannot hit the prepared empty-attribute entry'); }
    $changed = clone $copyright;
    $changed->attributes = (object) ['owner' => 'JoomlaMafia changed attributes'];
    $expectedChanged = $sourceConfig->getJoined('particles.copyright', $changed->attributes);
    $expect($renderParticle($changed) === $expectedChanged && !ConfigServiceProvider::canUsePreparedParticles($gantry, $gantry['config']), 'changed runtime attributes retain the actual frozen-schema fallback');
    $gantry['config']->set('particles.copyright.owner', 'JoomlaMafia changed defaults');
    $expectedConfig = new Config($gantry['config']->toArray(), static fn () => $sourceSchema);
    $expect($runtimeA->particleConfig('20', 'copyright', $copyrightAttributes, $gantry['config']->get('particles.copyright')) === null, 'changed defaults miss the prepared key before native fallback');
    $expect($renderParticle($copyright) === $expectedConfig->getJoined('particles.copyright', $copyright->attributes), 'changed runtime defaults cannot reuse a stale prepared particle');
    unset($gantry['config']);
    $gantry['config'] = ConfigServiceProvider::load($gantry, '20');
    $defaults = $gantry['config']->get('particles.copyright');
    $resource = fopen('php://memory', 'w+');
    try {
        $resourceDefaults = $defaults; $resourceDefaults['owner'] = $resource;
        $expect($runtimeA->particleConfig('20', 'copyright', $copyrightAttributes, $resourceDefaults) === null, 'resource inputs cannot collide with a prepared native integer-zero default');
        $resourceConfig = new Config(['particles' => ['copyright' => $resourceDefaults]], static fn () => $sourceSchema);
        $expect($resourceConfig->getJoined('particles.copyright', [])['owner'] === $resource, 'resource fallback keeps the native live resource value');
    } finally { fclose($resource); }
    $object = (object) ['name' => 'JoomlaMafia live object'];
    $objectDefaults = $defaults; $objectDefaults['owner'] = $object;
    $expect($runtimeA->particleConfig('20', 'copyright', $copyrightAttributes, $objectDefaults) === null, 'nested objects use native merging rather than a cached snapshot object');
    $objectConfig = new Config(['particles' => ['copyright' => $objectDefaults]], static fn () => $sourceSchema);
    $objectMerge = $objectConfig->getJoined('particles.copyright', []);
    $object->name = 'JoomlaMafia mutated object';
    $expect($objectMerge['owner'] === $object && $objectMerge['owner']->name === 'JoomlaMafia mutated object', 'native nested-object identity and later mutation stay live');
    $hook = new class { public int $calls = 0; public function __serialize(): array { ++$this->calls; return []; } };
    $expect($runtimeA->particleConfig('20', 'copyright', ['owner' => $hook], $defaults) === null && $hook->calls === 0, 'unsupported custom runtime objects never invoke serialization hooks');
    $referenced = 0;
    $referenceDefaults = $defaults; $referenceDefaults['owner'] =& $referenced;
    $expect($runtimeA->particleConfig('20', 'copyright', $copyrightAttributes, $referenceDefaults) === null, 'live scalar references cannot collide with a prepared plain scalar');
    $referenceConfig = new Config(['particles' => ['copyright' => $referenceDefaults]], static fn () => $sourceSchema);
    $referenceMerge = $referenceConfig->getJoined('particles.copyright', []); $referenced = 7;
    $expect($referenceMerge['owner'] === 7, 'native referenced defaults retain their live alias');
    $request = $freshRequest($registry->open($a));
    $expect(ConfigServiceProvider::canUsePreparedParticles($request, $request['config']), 'a fresh real native provider/config admits prepared particle values');
    $schema = $request['config']->blueprint();
    $schema->set('particles.copyright.date.*', ['type' => 'input.text']);
    $mutatedSchemaValue = $request['config']->getJoined('particles.copyright', $copyright->attributes);
    $expect(!ConfigServiceProvider::canUsePreparedParticles($request, $request['config']) && !isset($mutatedSchemaValue['date']['end']) && $renderParticle($copyright) === $mutatedSchemaValue, 'resolved and publicly mutated native schema rules override the previously prepared merge');
    $request = $freshRequest($registry->open($a));
    $schema = BundleRuntime::source(static fn () => $request['blueprints']);
    $schema->set('particles.copyright.date.*', ['type' => 'input.text']);
    $sourceScopedMerge = $request['config']->getJoined('particles.copyright', $copyright->attributes);
    $expect(!ConfigServiceProvider::canUsePreparedParticles($request, $request['config']) && !isset($sourceScopedMerge['date']['end']) && $renderParticle($copyright) === $sourceScopedMerge, 'native schema accessed under an explicit source scope cannot resume a stale prepared merge');
    $request = $freshRequest($registry->open($a));
    $factoryCalls = 0;
    unset($request['blueprints']);
    $request['blueprints'] = static function () use ($sourceSchema, &$factoryCalls) { ++$factoryCalls; return clone $sourceSchema; };
    $expect(!ConfigServiceProvider::canUsePreparedParticles($request, $request['config']) && $factoryCalls === 0, 'replaced blueprint factory refuses prepared values without instantiating it');
    $expect($renderParticle($copyright) === $nativeParticle && $factoryCalls === 1, 'custom blueprint factory is used by the actual native merge');
    $request = $freshRequest($registry->open($a));
    $customBlueprintCalls = 0;
    $customConfig = new Config($request['config']->toArray(), static function () use ($sourceSchema, &$customBlueprintCalls) { ++$customBlueprintCalls; return $sourceSchema; });
    unset($request['config']); $request['config'] = $customConfig;
    $expect(!ConfigServiceProvider::canUsePreparedParticles($request, $customConfig) && $renderParticle($copyright) === $nativeParticle && $customBlueprintCalls === 1, 'unattested replacement Config keeps its actual custom blueprint callback');
    $request = $freshRequest($registry->open($a));
    $customConfig = new class($request['config']->toArray(), static fn () => $sourceSchema) extends Config {
        public int $joins = 0;
        public function getJoined($name, $value, $separator = '.') { ++$this->joins; return ['owner' => 'JoomlaMafia custom getJoined'] + parent::getJoined($name, $value, $separator); }
    };
    unset($request['config']); $request['config'] = $customConfig;
    $expect(!ConfigServiceProvider::canUsePreparedParticles($request, $customConfig) && $renderParticle($copyright)['owner'] === 'JoomlaMafia custom getJoined' && $customConfig->joins === 1, 'Config subclass merge overrides are never bypassed by prepared values');
    $gantry = $sourceGantry;
    $reset(); BundleRuntime::pin($runtimeA);
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    unset($gantry['config']);
    $gantry['config'] = ConfigServiceProvider::load($gantry, '20');
    $theme->resetRenderState();
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
    $expect($manifest['particle_configs'] === true && isset($manifest['outlines']['20']['particles']), 'new native bundles declare required per-outline particle configuration artifacts');
    $mutateManifest(static function (array $value): array { unset($value['outlines']['20']['particles']); return $value; }, fn () => $registry->open($b), 'declared particle capability refuses an absent outline descriptor');
    foreach ([false, 1, []] as $badCapability) {
        $mutateManifest(static function (array $value) use ($badCapability): array { $value['particle_configs'] = $badCapability; $value['outlines'] = []; return $value; }, fn () => $registry->open($b), 'invalid particle capability is refused even with no outlines');
    }
    $legacyManifest = $manifest; unset($legacyManifest['particle_configs']);
    $legacyRuntime = new BundleRuntime($runtimeB->root(), $legacyManifest);
    $expect($legacyRuntime->particleConfig('20', 'copyright', $copyrightAttributes, $defaults) === null, 'older native bundles without the capability retain the frozen-schema fallback');
    $particleFile = $runtimeB->root() . '/' . $manifest['outlines']['20']['particles']['file'];
    $particleBytes = file_get_contents($particleFile);
    unlink($particleFile);
    try { $refuse(fn () => $registry->open($b)->particleConfig('20', 'copyright', $copyrightAttributes, $defaults), 'a missing declared particle artifact fails503 without fallback'); }
    finally { file_put_contents($particleFile, $particleBytes); }
    file_put_contents($particleFile, $particleBytes . "\n// altered particle artifact\n");
    try { $refuse(fn () => $registry->open($b)->particleConfig('20', 'copyright', $copyrightAttributes, $defaults), 'corrupt particle artifact bytes fail503 without fallback'); }
    finally { file_put_contents($particleFile, $particleBytes); }
    $badPayload = '<?php return ' . var_export([BundleRuntime::particleKey('copyright', $defaults, $copyrightAttributes) => 'invalid-particle-data'], true) . ';';
    file_put_contents($particleFile, $badPayload);
    try {
        $mutateManifest(static function (array $value) use ($badPayload): array { $value['outlines']['20']['particles']['sha256'] = hash('sha256', $badPayload); return $value; }, fn () => $registry->open($b)->particleConfig('20', 'copyright', $copyrightAttributes, $defaults), 'a hash-valid invalid prepared particle value fails503');
    } finally { file_put_contents($particleFile, $particleBytes); }
    foreach ([['malformed-key' => []], [BundleRuntime::particleKey('copyright', $defaults, $copyrightAttributes) => ['owner' => (object) ['live' => 'not-a-value-only-payload']]]] as $invalidEntries) {
        $badPayload = '<?php return ' . var_export($invalidEntries, true) . ';';
        file_put_contents($particleFile, $badPayload);
        try {
            $mutateManifest(static function (array $value) use ($badPayload): array { $value['outlines']['20']['particles']['sha256'] = hash('sha256', $badPayload); return $value; }, fn () => $registry->open($b)->particleConfig('20', 'copyright', $copyrightAttributes, $defaults), 'hash-valid malformed keys/shared-object prepared payload fails503');
        } finally { file_put_contents($particleFile, $particleBytes); }
    }
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
    $gantry = $freshRequest($registry->current());
    $expect($renderParticle($copyright) === $nativeParticle && $renderParticle($headAtom) === $nativeHead && $renderParticle($pageAtom) === $nativePage, 'real prepared particle/head/page merges remain exact with every source tree removed');
    $finalManifest = require BundleRuntime::current()->root() . '/bundle.php';
    $finalLoaded = (new ReflectionProperty(BundleRuntime::class, 'loaded'))->getValue(BundleRuntime::current());
    $expect(ConfigServiceProvider::canUsePreparedParticles($gantry, $gantry['config']) && !array_key_exists($finalManifest['data']['blueprints']['file'], $finalLoaded), 'source-absent known merges load no blueprint artifact through the real native provider');
    $expect($renderParticle($changed) === $expectedChanged && !ConfigServiceProvider::canUsePreparedParticles($gantry, $gantry['config']), 'source-absent dynamic attributes use the exact frozen-schema fallback');
    $expect(str_contains($theme->render('page.html.twig'), '#456789') && str_contains($theme->render('page.html.twig'), 'Generation B'), 'real prepared B renders after all Twig/YAML/SCSS/config input directories are removed');
    $expect($theme->css('hydrogen') === $cssB && is_file($assetPath($cssB)) && is_file($assetPath($cssA)), 'compiled rendering keeps both asset generations without SCSS inputs');
    $expect(BundleRuntime::current()->yaml('gantry-theme://sample.yaml')['selected'] === 'override-B', 'frozen override data remains B after sources changed and disappeared');
    $expect(count(Layout::instance('20')->references()) > 10 && count(Layout::instance('20')->inherit()) > 0, 'real resolved Hydrogen inheritance hydrates after layout sources disappear');
    $expect($locator->attempts === 0, 'full compiled path performs zero source file or directory discovery');
    $statistics = $theme->renderer()->statistics();
    $expect($statistics['source_attempts'] === 0 && $statistics['compiler_attempts'] === 0, 'subsequent compiled Twig rendering performs zero source reads or compiler calls');
    unset($finalManifest['particle_configs']);
    $gantry = $freshRequest(new BundleRuntime($runtimeB->root(), $finalManifest));
    $expect($renderParticle($copyright) === $nativeParticle && !ConfigServiceProvider::canUsePreparedParticles($gantry, $gantry['config']) && $locator->attempts === 0, 'old capability-absent bundles still render exact native particle merges using frozen blueprints without source discovery');
    BundleRuntime::assertHealthy();
    echo "PASS compiled-bundle: {$checks} real compiler/registry integration checks on PHP " . PHP_VERSION . "\n";
} finally {
    $reset();
    foreach ($schemes as $scheme) { if (in_array($scheme, stream_get_wrappers(), true)) { stream_wrapper_unregister($scheme); } }
    $remove($temporary);
}
