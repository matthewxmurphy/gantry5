<?php
/**
 * Strict compiled Twig execution with native dependencies and isolated real files/HTTP.
 * php tests/php85/compiled-twig.php /path/to/vendor/autoload.php [native-source-root]
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Config\Config;
use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Theme\AbstractTheme;
use Gantry\Component\Twig\CompiledEnvironment;
use Gantry\Component\Twig\GenerationFilesystemLoader;
use Gantry\Framework\Gantry;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;
use Twig\TwigFilter;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 and native Gantry Composer dependencies.');
}
$source = $argv[2] ?? dirname(__DIR__, 2);
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$temporary = sys_get_temp_dir() . '/g6-compiled-twig-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GRAV_ROOT', $temporary);
define('GANTRY5_ROOT', $temporary);
define('GANTRY5_PLATFORM', 'fixture');
define('GANTRY_DEBUGGER', false);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed compiled Twig: ' . $label); }
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
$server = null;
try {
    foreach (['source/main', 'source/nucleus', 'source/particles', 'bundle'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    file_put_contents($temporary . '/source/nucleus/base.html.twig', 'base:{% block body %}{% endblock %}');
    file_put_contents($temporary . '/source/particles/card.html.twig', '{{ name|stamp }}|{{ site.title }}|{{ joomla.adapter }}|{{ gantry.config.label }}|{{ "2026-11-02T20:30:00Z"|date("H:i") }}');
    file_put_contents($temporary . '/source/main/index.html.twig', "{% extends '@nucleus/base.html.twig' %}{% block body %}{% include ['missing-override.html.twig', '@particles/card.html.twig'] %}{% endblock %}");
    file_put_contents($temporary . '/source/main/embed.html.twig', "{% embed '@nucleus/base.html.twig' %}{% block body %}embedded:{{ name }}{% endblock %}{% endembed %}");
    file_put_contents($temporary . '/source/main/unused.html.twig', 'unused');
    $gantry = new Gantry();
    $gantry['global'] = new Config(['debug' => false]);
    $gantry['config'] = new Config(['label' => 'live-context']);
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    $theme = new class($temporary) extends AbstractTheme {
        public int $sourcePathCalls = 0;
        protected function init() {}
        protected function setTwigLoaderPaths(LoaderInterface $loader)
        {
            if (!$loader instanceof FilesystemLoader) { return null; }
            ++$this->sourcePathCalls;
            $loader->setPaths([$this->path . '/source/main']);
            $loader->setPaths([$this->path . '/source/main'], 'alias');
            $loader->setPaths([$this->path . '/source/nucleus'], 'nucleus');
            $loader->setPaths([$this->path . '/source/particles'], 'particles');
            return $loader;
        }
        public function extendTwig(Environment $twig, ?LoaderInterface $loader = null)
        {
            parent::extendTwig($twig, $loader);
            $twig->getExtension(CoreExtension::class)->setTimezone('America/Los_Angeles');
            $twig->addFilter(new TwigFilter('stamp', static fn (string $value): string => 'context:' . strtoupper($value)));
            return $twig;
        }
        public function getContext(array $context)
        {
            return parent::getContext($context) + ['site' => ['title' => 'JoomlaMafia'], 'joomla' => ['adapter' => 'native']];
        }
    };
    $generation = hash('sha256', 'first native compiled generation');
    $inline = '<b>{{ name|upper }} {{ site.title }}</b>';
    $map = BundleRuntime::source(fn (): array => $theme->compileTwigBundle($generation, $temporary . '/bundle', [$inline, $inline]));
    $expect($map['generation'] === $generation && $map['twig'] === Environment::VERSION && $map['php'] === '8.5', 'bundle binds generation and actual compiler runtime');
    $expect(count($map['strings']) === 1, 'identical registered inline strings deduplicate');
    $expect(isset($map['templates']['@particles/card.html.twig'], $map['templates']['@nucleus/base.html.twig'], $map['templates']['index.html.twig']), 'every native loader namespace is compiled');
    $expect($map['templates']['index.html.twig']['class'] === $map['templates']['@alias/index.html.twig']['class'], 'physical template aliases keep the native class identity');
    $expect($map['templates']['index.html.twig']['file'] === $map['templates']['@alias/index.html.twig']['file'], 'physical aliases share one immutable PHP artifact');
    foreach ($map['templates'] as $entry) {
        $expect(str_starts_with($entry['key'], 'gantry:' . $generation . ':'), 'template class inputs include the generation');
        $expect(is_file($temporary . '/bundle/' . $entry['file']) && hash_file('sha256', $temporary . '/bundle/' . $entry['file']) === $entry['sha256'], 'each generated PHP artifact matches its immutable map');
    }
    // A direct HTTP request runs without the Twig/Gantry autoloaders or a CMS.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!$socket) { throw new RuntimeException('Could not reserve the isolated HTTP fixture port.'); }
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $log = $temporary . '/http.log';
    $server = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-S', '127.0.0.1:' . $port, '-t', $temporary . '/bundle'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($server)) { throw new RuntimeException('Could not start the isolated HTTP fixture.'); }
    fclose($pipes[0]);
    $body = false; $status = 0;
    for ($i = 0; $i < 100; ++$i) {
        $curl = curl_init('http://127.0.0.1:' . $port . '/' . $map['templates']['index.html.twig']['file']);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100]);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($body !== false) { break; }
        usleep(10000);
    }
    $expect($status === 404 && $body === '', 'direct HTTP compiled-PHP admission returns empty404 without autoload');
    proc_terminate($server); proc_close($server); $server = null;
    $expect(!preg_match('/PHP (?:Warning|Fatal|Parse error|Deprecated)|Uncaught/i', file_get_contents($log)), 'direct HTTP artifact guard emits no PHP diagnostics or class-load fatal');

    BundleRuntime::pin(new BundleRuntime($temporary . '/bundle', ['schema' => 1, 'id' => $generation, 'php' => '8.5',
        'framework' => '6.0.2-rc.1', 'theme' => 'g6_fixture', 'revision' => hash('sha256', 'revision'), 'data' => [], 'outlines' => []]));
    $theme->activateCompiledTwig($map, $temporary . '/bundle');
    $paths = $theme->sourcePathCalls;
    rename($temporary . '/source', $temporary . '/source-removed');
    $expect($theme->renderer() instanceof CompiledEnvironment, 'theme retains the strict execution environment');
    $expect($theme->renderer()->statistics()['artifact_loads'] === 0, 'compiled templates are loaded lazily');
    $expect($theme->render('index.html.twig', ['name' => 'Alice']) === 'base:context:ALICE|JoomlaMafia|native|live-context|12:30', 'native inheritance/includes/filters/timezone/context execute with every source directory removed');
    $expect($theme->render('@alias/index.html.twig', ['name' => 'Bob']) === 'base:context:BOB|JoomlaMafia|native|live-context|12:30', 'compiled aliases retain fresh person context');
    $expect($theme->render('embed.html.twig', ['name' => 'Alice']) === 'base:embedded:Alice', 'embedded native template classes load from their immutable main file');
    $expect($theme->compile($inline, ['name' => 'Alice']) === '<b>ALICE JoomlaMafia</b>', 'registered inline Twig uses its compiled hash lookup');
    $expect($theme->compile('<p>literal HTML</p>') === '<p>literal HTML</p>', 'plain unprocessed HTML remains literal');
    $expect($theme->sourcePathCalls === $paths, 'runtime construction and execution never rediscover template paths');
    $statistics = $theme->renderer()->statistics();
    $expect($statistics['source_attempts'] === 0 && $statistics['compiler_attempts'] === 0, 'valid runtime performs zero source reads/tokenization/compilation');
    $expect($statistics['artifact_loads'] < count(array_unique(array_column($map['templates'], 'file'))), 'unused compiled templates are never eagerly included');
    $refuse(fn () => $theme->compile('{{ unknown }}'), 'unknown runtime inline Twig is refused');
    $refuse(fn () => $theme->render('unregistered.html.twig'), 'unknown required template becomes a sticky controlled failure');
    $refuse(fn () => $theme->renderer()->compileSource(new Source('{{ unregistered }}', 'unregistered')), 'direct runtime compileSource is refused');
    $refuse(fn () => $theme->renderer()->tokenize(new Source('{{ unregistered }}', 'unregistered')), 'direct runtime tokenization is refused');
    $refuse(fn () => $theme->renderer()->getLoader()->getSourceContext('index.html.twig'), 'runtime loader cannot return source text');
    $refuse(fn () => $theme->renderer()->setLoader(new ArrayLoader()), 'runtime loader cannot be replaced');
    $refuse(fn () => $theme->renderer()->setCache(false), 'runtime cache cannot be disabled');
    $refuse(fn () => $theme->renderer()->enableAutoReload(), 'runtime source autoreload cannot be enabled');
    $refuse(fn () => $theme->compilationEnvironment($generation), 'offline compiler factory refuses frontend execution outside source scope');
    $expect($theme->sourcePathCalls === $paths, 'compiler escape refusals occur before any source discovery');
    try { $theme->compile('{{ unknown_sticky }}'); } catch (RuntimeException) {}
    $refuse(fn () => BundleRuntime::assertHealthy(), 'a native optional-block catch cannot hide a strict Twig failure');

    $filename = $temporary . '/bundle/' . $map['templates']['index.html.twig']['file'];
    $bytes = file_get_contents($filename);
    file_put_contents($filename, $bytes . "\n/* unexpected alteration */");
    $theme->activateCompiledTwig($map, $temporary . '/bundle');
    $refuse(fn () => $theme->render('index.html.twig'), 'fresh runtime rejects altered artifact bytes even with its PHP class already loaded');
    file_put_contents($filename, $bytes);
    rename($filename, $filename . '.absent');
    $theme->activateCompiledTwig($map, $temporary . '/bundle');
    $refuse(fn () => $theme->render('index.html.twig'), 'missing artifact fails without source fallback or interpretation');
    rename($filename . '.absent', $filename);
    $bad = $map; $bad['templates']['index.html.twig']['file'] = '../source/index.php';
    $refuse(fn () => $theme->activateCompiledTwig($bad, $temporary . '/bundle'), 'artifact traversal is rejected before file access');
    $bad = $map; $bad['options']['debug'] = true;
    $theme->activateCompiledTwig($bad, $temporary . '/bundle');
    $refuse(fn () => $theme->render('index.html.twig'), 'compiler/runtime option drift is refused');
    rename($temporary . '/source-removed', $temporary . '/source');
    file_put_contents($temporary . '/source/particles/card.html.twig', 'new-generation:{{ name }}');
    $refuse(fn () => BundleRuntime::source(fn () => $theme->compileTwigBundle($generation, $temporary . '/bundle', [$inline])), 'an existing generation cannot be overwritten with changed source');
    $newGeneration = hash('sha256', 'second native compiled generation');
    $newMap = BundleRuntime::source(fn () => $theme->compileTwigBundle($newGeneration, $temporary . '/second-bundle', [$inline]));
    $expect($newMap['templates']['index.html.twig']['class'] !== $map['templates']['index.html.twig']['class'], 'a new generation cannot reuse a stale globally loaded Twig class');
    $theme->activateCompiledTwig($newMap, $temporary . '/second-bundle');
    rename($temporary . '/source', $temporary . '/source-removed');
    $expect($theme->render('index.html.twig', ['name' => 'Next']) === 'base:new-generation:Next', 'the next immutable generation renders its exact new output without source');
    echo "PASS compiled-twig: {$checks} native immutable Twig/HTTP checks on PHP " . PHP_VERSION . "\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    (new ReflectionProperty(BundleRuntime::class, 'pinned'))->setValue(null, null);
    (new ReflectionProperty(BundleRuntime::class, 'failure'))->setValue(null, null);
    $remove($temporary);
}
