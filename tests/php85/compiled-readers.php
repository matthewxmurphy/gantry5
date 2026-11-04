<?php
/**
 * Native frozen configuration/layout/metadata readers, without source files/discovery.
 * php tests/php85/compiled-readers.php /path/to/vendor/autoload.php [native-source-root]
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Assignments\AbstractAssignments;
use Gantry\Component\Config\BlueprintForm;
use Gantry\Component\Config\BlueprintSchema;
use Gantry\Component\Config\Config;
use Gantry\Component\Config\ConfigFileFinder;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Streams;
use Gantry\Component\Layout\Layout;
use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Theme\AbstractTheme;
use Gantry\Component\Theme\ThemeDetails;
use Gantry\Component\Theme\ThemeTrait;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use Gantry\Framework\Services\ConfigServiceProvider;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) { throw new RuntimeException('Use actual PHP 8.5 and native dependencies.'); }
$source = realpath($argv[2] ?? dirname(__DIR__, 2));
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$temporary = realpath(sys_get_temp_dir()) . '/g6-frozen-readers-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GRAV_ROOT', $temporary);
define('GANTRY5_ROOT', $temporary);
define('GANTRY5_PLATFORM', 'fixture');
define('GANTRY_DEBUGGER', false);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed frozen readers: ' . $label); }
    ++$checks;
};
$refuse = static function (callable $operation, string $label) use ($expect): void {
    try { $operation(); throw new LogicException('An operation unexpectedly succeeded'); }
    catch (RuntimeException $error) { $expect($error->getCode() === 503, $label); }
    (new ReflectionProperty(BundleRuntime::class, 'failure'))->setValue(null, null);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $remove($entry->getPathname()); } }
        rmdir($path);
    } else { unlink($path); }
};
try {
    foreach (['source/config/default', 'source/config/20', 'source/themes/g6_fixture/gantry', 'cache', 'bundle/data'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    copy($source . '/themes/hydrogen/common/layouts/default.yaml', $temporary . '/source/config/default/layout.yaml');
    file_put_contents($temporary . '/source/config/default/styles.yaml', "accent:\n  color-1: '#123456'\n");
    file_put_contents($temporary . '/source/config/20/styles.yaml', "accent:\n  color-1: '#ee4b2b'\n");
    file_put_contents($temporary . '/source/themes/g6_fixture/gantry/theme.yaml', "details:\n  name: JoomlaMafia\n");
    file_put_contents($temporary . '/source/themes/g6_fixture/gantry/presets.yaml', "preset1:\n  name: Frozen palette\n");
    $locator = new class($temporary) extends UniformResourceLocator {
        public bool $blocked = false;
        public int $attempts = 0;
        public function findResource($uri, $absolute = true, $first = false)
        {
            if ($this->blocked) { ++$this->attempts; throw new LogicException('Source path discovery was attempted.'); }
            return parent::findResource($uri, $absolute, $first);
        }
        public function findResources($uri, $absolute = true, $all = false)
        {
            if ($this->blocked) { ++$this->attempts; throw new LogicException('Source directory discovery was attempted.'); }
            return parent::findResources($uri, $absolute, $all);
        }
    };
    $streams = new Streams($locator);
    $streams->add([
        'gantry-config' => ['paths' => [$temporary . '/source/config']],
        'gantry-cache' => ['paths' => [$temporary . '/cache']],
        'gantry-theme' => ['paths' => [$temporary . '/source/themes/g6_fixture']],
        'gantry-themes' => ['paths' => [$temporary . '/source/themes']],
        'gantry-layouts' => ['paths' => [$source . '/themes/hydrogen/common/layouts', $source . '/engines/common/nucleus/layouts']],
    ]);
    $streams->register();
    $gantry = new Gantry();
    $gantry['locator'] = static fn () => $locator;
    $gantry['global'] = new Config(['compile_yaml' => false]);
    $gantry['theme.name'] = 'g6_fixture';
    $gantry['configuration'] = '20';
    $schema = new BlueprintSchema();
    $schema->embed('styles', ['form' => ['fields' => ['accent.color-1' => ['type' => 'input.colorpicker', 'default' => '#112233']]]]);
    $gantry['blueprints'] = $schema;
    $outlines = new Outlines($gantry);
    $gantry['outlines'] = $outlines;
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    CompiledYamlFile::$defaultCachePath = $temporary . '/cache';
    CompiledYamlFile::$defaultCaching = false;
    $outlines->load();
    $child = $outlines->duplicate('default', '20', true);
    $expect($child === '20', 'native source fixture creates a real inherited outline');
    file_put_contents($temporary . '/source/config/20/styles.yaml', "accent:\n  color-1: '#ee4b2b'\n");
    file_put_contents($temporary . '/source/config/20/assignments.yaml', "menu:\n  mainmenu:\n    42: true\n");
    Layout::resetCache();
    $resolved = Layout::instance('20')->init();
    $resolvedNodes = unserialize(serialize($resolved->toArray()));
    $index = $resolved->buildIndex();
    $expect($resolved->inherit() !== [] && count($resolved->references()) > 10, 'native inheritance is resolved before artifact preparation');
    $write = static function (string $name, array $data) use ($temporary): array {
        $file = 'data/' . $name . '.php';
        $php = '<?php return ' . var_export($data, true) . ';';
        file_put_contents($temporary . '/bundle/' . $file, $php);
        return ['file' => $file, 'sha256' => hash('sha256', $php)];
    };
    $manifest = ['schema' => 1, 'id' => hash('sha256', 'frozen native readers'), 'php' => '8.5', 'framework' => '6.0.3', 'theme' => 'g6_fixture', 'revision' => hash('sha256', 'revision'), 'data' => [], 'outlines' => []];
    $configurations = [];
    foreach ([true, false] as $combine) {
        foreach ([true, false] as $defaults) {
            $kind = ($combine ? 'config' : 'config_single') . ($defaults ? '' : '_raw');
            $configurations[$kind] = ConfigServiceProvider::load($gantry, '20', $combine, $defaults)->toArray();
            $manifest['outlines']['20'][$kind] = $write($kind, $configurations[$kind]);
        }
    }
    $manifest['outlines']['20']['layout'] = $write('layout', ['nodes' => $resolvedNodes, 'preset' => $resolved->preset, 'timestamp' => $resolved->timestamp]);
    $manifest['outlines']['20']['index'] = $write('index', $index);
    $manifest['outlines']['20']['assets'] = $write('assets', ['css' => ['hydrogen' => '/media/g6/frozen-hydrogen.css']]);
    $manifest['data']['blueprints'] = $write('blueprints', $schema->getState());
    $manifest['data']['details'] = $write('details', [
        'g6_fixture' => ['name' => 'g6_fixture', 'parent' => 'parent_fixture', 'details' => ['name' => 'JoomlaMafia'], 'configuration' => ['theme' => ['parent' => 'parent_fixture']]],
        'parent_fixture' => ['name' => 'parent_fixture', 'parent' => null, 'details' => ['name' => 'Frozen parent']],
    ]);
    $manifest['data']['presets'] = $write('presets', ['preset1' => ['name' => 'Frozen palette']]);
    $assignments = new class extends AbstractAssignments { public function types() { return []; } };
    $saved = $assignments->loadSavedAssignments();
    $manifest['data']['assignments'] = $write('assignments', $saved);
    $yamlFilename = $temporary . '/source/config/20/styles.yaml';
    $yaml = CompiledYamlFile::instance($yamlFilename);
    $yamlSource = $yaml->content();
    $manifest['data']['yaml'] = $write('yaml', [$yamlFilename => $write('yaml_styles', $yamlSource)]);
    $theme = new class($temporary) extends AbstractTheme {
        use ThemeTrait;
        protected function init() {}
        public function markDerivedState(): void { $this->atoms = true; $this->cssCache = ['hydrogen' => 'stale']; }
        public function derivedState(): array { return ['atoms' => $this->atoms, 'css' => $this->cssCache, 'layout' => $this->layoutObject, 'segments' => $this->segments]; }
    };
    $theme->name = 'g6_fixture';
    BundleRuntime::pin(new BundleRuntime($temporary . '/bundle', $manifest));
    $locator->blocked = true;
    rename($temporary . '/source', $temporary . '/source-removed');
    foreach ([true, false] as $combine) {
        foreach ([true, false] as $defaults) {
            $kind = ($combine ? 'config' : 'config_single') . ($defaults ? '' : '_raw');
            $expect(ConfigServiceProvider::load($gantry, '20', $combine, $defaults)->toArray() === $configurations[$kind], 'frozen configuration matches its exact source-prepared view: ' . $kind);
        }
    }
    $expect(ConfigServiceProvider::blueprints($gantry)->getState() === $schema->getState(), 'native blueprint state restores without discovery');
    $expect($yaml->content() === $yamlSource, 'an already-instantiated YAML object reads frozen PHP values after source removal');
    $expect($assignments->loadSavedAssignments() === $saved && $assignments->loadAssignments() === $saved, 'saved assignment reuse reads the frozen rules');
    $details = new ThemeDetails('g6_fixture');
    $expect($details->get('details.name') === 'JoomlaMafia' && $details->parent()->get('details.name') === 'Frozen parent', 'theme and parent details restore without locating YAML');
    $expect($theme->presets()->get('preset1.name') === 'Frozen palette', 'theme preset values come from the pinned bundle');
    $expect($theme->css('hydrogen') === '/media/g6/frozen-hydrogen.css', 'stylesheet lookup bypasses compiler and stale source cache');
    $frozen = Layout::instance('20');
    $expect($frozen !== $resolved && $frozen->compiledGeneration() === $manifest['id'], 'source layout statics are replaced by generation-bound native objects');
    $expect(serialize($frozen->toArray()) === serialize($resolvedNodes), 'fully resolved native node tree is preserved exactly');
    $expect(count($frozen->references()) === count($resolved->references()), 'frozen native references are indexed without ancestor loading');
    $expect($frozen->positions() === $resolved->positions() && $frozen->sections() === $resolved->sections(), 'native position/section consumers retain their exact data');
    $expect($frozen->particles() === $resolved->particles() && $frozen->inherit() === $resolved->inherit(), 'particle/inheritance metadata stays usable without resolving inheritance again');
    $expect(Layout::index('20') === $index && Layout::loadIndex('20') === $index, 'native index readers consume the exact frozen index');
    $frozen->init(true);
    $expect(serialize($frozen->toArray()) === serialize($resolvedNodes), 'forced native init never reruns inheritance or renumbers IDs');
    $copy = Layout::load('20');
    $copyNodes = $copy->toArray();
    $first = reset($copyNodes);
    $first->title = 'local display transformation';
    $expect(serialize($frozen->toArray()) === serialize($resolvedNodes), 'runtime layout hydration deep-clones cached native objects');
    $expect($theme->loadLayout()->compiledGeneration() === $manifest['id'], 'native theme layout entrypoint consumes the resolved artifact');
    $expect($locator->attempts === 0, 'all valid readers perform zero source path or directory discovery');
    foreach (['locateFiles', 'getFiles', 'listFiles'] as $method) {
        $refuse(fn () => (new ConfigFileFinder())->$method([$temporary . '/source/config']), 'directory discovery is refused before touching paths: ' . $method);
    }
    $refuse(fn () => (new ConfigFileFinder())->locateFileInFolder('assignments', [$temporary . '/source/config']), 'assignment-directory discovery is refused');
    $refuse(fn () => (new ConfigFileFinder())->locateInFolders([$temporary . '/source/config']), 'folder discovery is refused');
    $refuse(fn () => (new ConfigFileFinder())->locateFile([$temporary . '/source/config'], 'styles'), 'single-file discovery is refused');
    $refuse(fn () => $yaml->content(['new' => true]), 'runtime YAML mutation is refused');
    $refuse(fn () => $yaml->raw(), 'runtime YAML source bytes cannot be read');
    $refuse(fn () => $yaml->save(), 'runtime YAML writes are refused');
    $refuse(fn () => $frozen->save(), 'runtime layout writes are refused');
    $refuse(fn () => $frozen->saveIndex(), 'runtime index writes are refused');
    $refuse(fn () => Layout::loadIndex('20', true), 'runtime source index rebuilding is refused');
    $refuse(fn () => Layout::presets(), 'runtime layout-preset discovery is refused');
    $refuse(fn () => Layout::preset('default'), 'runtime layout-preset parsing is refused');
    $refuse(fn () => $theme->compiler(), 'direct runtime SCSS compiler access is refused');
    $refuse(fn () => $theme->css('not-built'), 'unknown runtime stylesheet cannot trigger compilation');
    $refuse(fn () => $assignments->save([]), 'runtime saved-assignment mutation is refused');
    $expect($locator->attempts === 0, 'negative source gates fail before any locator discovery');
    $theme->markDerivedState();
    $theme->resetRenderState();
    $state = $theme->derivedState();
    $expect($state === ['atoms' => false, 'css' => [], 'layout' => null, 'segments' => null], 'the builder reset clears disposable native render state');
    Layout::resetCache();
    $expect(Layout::instance('20') !== $frozen && Layout::instance('20')->compiledGeneration() === $manifest['id'], 'native layout reset rebuilds from the pinned artifact without sources');
    echo "PASS compiled-readers: {$checks} native frozen-reader checks on PHP " . PHP_VERSION . "\n";
} finally {
    (new ReflectionProperty(BundleRuntime::class, 'pinned'))->setValue(null, null);
    (new ReflectionProperty(BundleRuntime::class, 'failure'))->setValue(null, null);
    foreach (['gantry-config', 'gantry-cache', 'gantry-theme', 'gantry-themes', 'gantry-layouts'] as $scheme) { if (in_array($scheme, stream_get_wrappers(), true)) { stream_wrapper_unregister($scheme); } }
    $remove($temporary);
}
