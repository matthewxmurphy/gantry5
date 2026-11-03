<?php
/**
 * Native Twig runtime dispatch with real Gantry layouts/assets and isolated files.
 * php tests/php85/twig-runtime.php /path/to/gantry-vendor/autoload.php [source-root]
 * The date adapter here delegates to native Twig CoreExtension, not a Joomla CMS.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Config\Config;
use Gantry\Component\Content\Document\HtmlDocument;
use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Theme\AbstractTheme;
use Gantry\Component\Theme\ThemeTrait;
use Gantry\Component\Twig\CompiledEnvironment;
use Gantry\Component\Twig\ThemeRuntime;
use Gantry\Component\Twig\TwigExtension;
use Gantry\Framework\Gantry;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

$child = ($argv[1] ?? '') === '--child';
$vendor = $argv[$child ? 2 : 1] ?? '';
$source = realpath($argv[$child ? 3 : 2] ?? dirname(__DIR__, 2));
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !is_file($vendor) || !$source) {
    throw new RuntimeException('Use actual PHP 8.5 CLI and native Gantry Composer dependencies.');
}
$vendor = realpath($vendor);
$loader = require $vendor;
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
define('GANTRY5_PLATFORM', 'fixture');
define('GANTRY_DEBUGGER', false);
define('GRAV_ROOT', sys_get_temp_dir());
define('GANTRY5_ROOT', sys_get_temp_dir());
class_alias(HtmlDocument::class, 'Gantry\\Framework\\Document');

final class RuntimeOverrideExtension extends AbstractExtension
{
    public array $calls = [];
    public array $options = [];
    public ?Environment $environment = null;
    public function getFunctions(): array { return [new TwigFunction('parse_assets', [$this, 'assets'], ['needs_environment' => true, 'needs_context' => true])]; }
    public function getFilters(): array
    {
        return [new TwigFilter('toGrid', static fn ($value): string => 'extension-must-not-replace-staged-' . $value),
            new TwigFilter('custom_word', [$this, 'word'])];
    }
    public function assets(Environment $environment, array $context, $content, $location = 'head', $priority = 0): string
    {
        $this->calls[] = [$content, $location, $priority];
        $this->options[] = [$environment === $this->environment, $context['word'], $context['width']];
        return 'captured return values must not leak into HTML';
    }
    public function word(string $word): string { return 'extension:' . strtoupper($word); }
}

class RuntimeFixtureTheme extends AbstractTheme
{
    use ThemeTrait;
    public int $sourcePaths = 0;
    public array $dates = [];
    public array $assets = [];
    public array $assetOptions = [];
    public ?Environment $activeEnvironment = null;
    public ?RuntimeOverrideExtension $custom = null;
    public string $mode = 'native';
    protected function init() {}
    protected function setTwigLoaderPaths(LoaderInterface $loader)
    {
        if (!$loader instanceof FilesystemLoader) { return null; }
        ++$this->sourcePaths;
        $loader->setPaths([$this->path . '/source']);
        return $loader;
    }
    public function extendTwig(Environment $twig, ?LoaderInterface $loader = null)
    {
        if ($this->mode === 'staged') {
            // A custom theme owns these effective staged definitions. Native
            // Twig intentionally refuses adding the same staged name twice.
            $twig->addExtension(new TwigExtension());
            $twig->addRuntimeLoader(new FactoryRuntimeLoader([ThemeRuntime::class => fn (): ThemeRuntime => new ThemeRuntime($this)]));
            $twig->addFilter(new TwigFilter('toGrid', static fn ($width): string => 'staged-grid:' . $width));
        } else { parent::extendTwig($twig, $loader); }
        $core = $twig->getExtension(CoreExtension::class);
        $core->setTimezone('America/Los_Angeles');
        $core->setDateFormat('Y-m-d H:i T', '%d days %h hours');
        // This is the native Joomla registration contract, without a CMS mock.
        $dateCallback = $this->mode === 'staged'
            ? fn (Environment $environment, $date, $format = null, $timezone = null): string => 'staged-date:' . $this->twig_dateFilter($environment, $date, $format, $timezone)
            : [ThemeRuntime::class, 'dateFilter'];
        $twig->addFilter(new TwigFilter('date', $dateCallback, ['needs_environment' => true]));
        if ($this->mode === 'extension') {
            $this->custom = new RuntimeOverrideExtension();
            $twig->addExtension($this->custom);
        } elseif ($this->mode === 'staged') {
            $twig->addFunction(new TwigFunction('parse_assets', function (Environment $environment, array $context, $content, $location = 'head', $priority = 0): string {
                $this->assets[] = [$content, $location, $priority];
                $this->assetOptions[] = [$environment === $this->activeEnvironment, $context['word'], $context['width']];
                return 'staged return values must not leak into HTML';
            }, ['needs_environment' => true, 'needs_context' => true]));
            $twig->addFilter(new TwigFilter('custom_word', static fn (string $value): string => 'staged:' . strtoupper($value)));
        }
        return $twig;
    }
    public function twig_dateFilter(Environment $environment, $date, $format = null, $timezone = null): string
    {
        $this->dates[] = [$environment, $date, $format, $timezone];
        return $environment->getExtension(CoreExtension::class)->formatDate($date, $format, $timezone);
    }
}

$container = static function (): Gantry {
    $gantry = new Gantry();
    $gantry['global'] = new Config(['debug' => false, 'compile_twig' => false]);
    $gantry['config'] = new Config([]);
    $gantry['document'] = new HtmlDocument();
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    return $gantry;
};
$context = static fn (): array => ['width' => 33.3, 'date_string' => '2026-11-02T23:30:00Z',
    'date_object' => new DateTimeImmutable('2026-11-03T00:30:00+01:00'), 'interval' => new DateInterval('P2DT3H'),
    'timezone' => 'UTC', 'word' => 'JoomlaMafia', 'location' => 'footer', 'options' => ['priority' => 7]];
$dateSummary = static function (RuntimeFixtureTheme $theme, Environment $twig, array $values): array {
    $dates = $theme->dates;
    return ['count' => count($dates), 'environment' => count($dates) === 3 && count(array_filter($dates, static fn ($call): bool => $call[0] === $twig)) === 3,
        'values' => count($dates) === 3 && $dates[0][1] === $values['date_string'] && $dates[1][1] === $values['date_object'] && $dates[2][1] === $values['interval'],
        'formats' => array_column($dates, 2), 'timezones' => array_column($dates, 3)];
};
$render = static function (RuntimeFixtureTheme $theme, Environment $twig) use ($context, $dateSummary): array {
    $values = $context();
    $theme->dates = [];
    $theme->activeEnvironment = $twig;
    if ($theme->custom) { $theme->custom->environment = $twig; }
    $text = $twig->render('index.html.twig', $values);
    $block = HtmlDocument::pop();
    HtmlDocument::push();
    $assets = $block->toArray();
    $blockIdentity = is_string($assets['id'] ?? null) && $assets['id'] !== '';
    // Each real HtmlBlock has its own instance ID; compare every asset field.
    unset($assets['id']);
    return ['text' => $text, 'dates' => $dateSummary($theme, $twig, $values), 'assets' => $assets, 'block_identity' => $blockIdentity,
        'custom_assets' => $theme->mode === 'extension' ? $theme->custom->calls : $theme->assets,
        'callback_options' => $theme->mode === 'extension' ? $theme->custom->options : $theme->assetOptions,
        'core_timezone' => $twig->getExtension(CoreExtension::class)->getTimezone()->getName(),
        'core_formats' => $twig->getExtension(CoreExtension::class)->getDateFormat()];
};

if ($child) {
    $root = $argv[4]; $mode = $argv[5];
    $gantry = $container();
    $map = json_decode(file_get_contents($root . '/' . $mode . '-map.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest = ['schema' => 1, 'id' => $map['generation'], 'php' => '8.5', 'framework' => '6.0.2-rc.2',
        'revision' => hash('sha256', 'runtime-fixture'), 'theme' => 'g343_fixture', 'data' => [], 'outlines' => []];
    BundleRuntime::pin(new BundleRuntime($root . '/' . $mode . '-bundle', $manifest));
    $theme = new RuntimeFixtureTheme($root); $theme->mode = $mode;
    $theme->activateCompiledTwig($map, $root . '/' . $mode . '-bundle');
    $twig = $theme->renderer();
    $beforeCore = $twig->getExtension(CoreExtension::class);
    $result = $render($theme, $twig);
    $result['source_absent'] = !file_exists($root . '/source');
    $result['source_paths'] = $theme->sourcePaths;
    $result['same_core_object'] = $twig->getExtension(CoreExtension::class) === $beforeCore;
    $result['offline_compiler_loaded'] = class_exists(\Gantry\Component\Twig\TwigBundle::class, false);
    $result['compiler_registry_loaded'] = class_exists(Twig\ExpressionParser\ExpressionParsers::class, false);
    $result['compiler_families'] = array_values(array_filter(get_declared_classes(), static fn (string $class): bool =>
        str_starts_with($class, 'Twig\\TokenParser\\') || str_starts_with($class, 'Twig\\NodeVisitor\\')
        || str_starts_with($class, 'Gantry\\Component\\Twig\\TokenParser\\') || str_starts_with($class, 'Gantry\\Component\\Twig\\Node\\')));
    $result['statistics'] = $twig->statistics();
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed native Twig runtime: ' . $label); }
    ++$checks;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
        rmdir($path);
    } else { unlink($path); }
};
$root = sys_get_temp_dir() . '/g343-twig-runtime-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    mkdir($root . '/source', 0700);
    $native = '{{ width|toGrid }}|{{ date_string|date("Y-m-d H:i", timezone) }}|{{ date_object|date("Y-m-d H:i", false) }}|{{ interval|date("%d days %h hours") }};';
    $capture = '{% assets in location with options %}<script src="/fixture/runtime.js" defer="defer"></script><link rel="stylesheet" href="/fixture/runtime.css">{% endassets %}'
        . '{% scripts in "footer" with {priority:11} %}<script src="/fixture/scripts.js"></script>{% endscripts %}'
        . '{% styles in "footer" with {priority:13} %}<link rel="stylesheet" href="/fixture/styles.css">{% endstyles %}END';
    $sourceResults = [];
    $gantry = $container();
    foreach (['native', 'extension', 'staged'] as $mode) {
        $template = $native . ($mode === 'native' ? '' : '{{ word|custom_word }};') . $capture;
        file_put_contents($root . '/source/index.html.twig', $template);
        $theme = new RuntimeFixtureTheme($root); $theme->mode = $mode;
        $environment = $theme->compilationEnvironment(hash('sha256', $mode . '-source'));
        $sourceResults[$mode] = $render($theme, $environment);
        $prefix = $mode === 'staged' ? 'staged-grid:33.3|staged-date:2026-11-02 23:30|staged-date:2026-11-03 00:30|staged-date:2 days 3 hours;'
            : ' size-33-3|2026-11-02 23:30|2026-11-03 00:30|2 days 3 hours;';
        $expect(str_starts_with($sourceResults[$mode]['text'], $prefix), $mode . ' actual selected source callbacks retain grid/date semantics');
        $expect(str_ends_with($sourceResults[$mode]['text'], 'END') && !str_contains($sourceResults[$mode]['text'], 'must not leak'), $mode . ' asset return values stay captured');
        $generation = hash('sha256', $mode . '-compiled');
        $map = BundleRuntime::source(fn (): array => $theme->compileTwigBundle($generation, $root . '/' . $mode . '-bundle'));
        file_put_contents($root . '/' . $mode . '-map.json', json_encode($map, JSON_THROW_ON_ERROR));
        chmod($root . '/' . $mode . '-map.json', 0600);
    }
    rename($root . '/source', $root . '/source-removed');
    foreach ($sourceResults as $mode => $expected) {
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $vendor, $source, $root, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Could not start a fresh native runtime process.'); }
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $errors !== '') { throw new RuntimeException('Fresh native runtime failed: ' . $errors); }
        $actual = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        foreach (['text', 'dates', 'assets', 'block_identity', 'custom_assets', 'callback_options', 'core_timezone', 'core_formats'] as $key) {
            $expect($actual[$key] === $expected[$key], $mode . ' source/compiled exact ' . $key);
        }
        $expect($actual['source_absent'] && $actual['source_paths'] === 0, $mode . ' compiled render cannot discover removed sources');
        $expect($actual['same_core_object'] && $actual['core_timezone'] === 'America/Los_Angeles', $mode . ' runtime preserves native CoreExtension identity/state');
        $expect($actual['statistics']['source_attempts'] === 0 && $actual['statistics']['compiler_attempts'] === 0 && $actual['statistics']['artifact_loads'] > 0, $mode . ' runs actual immutable artifacts without interpretation');
        $expect($actual['offline_compiler_loaded'] === false, $mode . ' runtime validation does not autoload the offline bundle compiler');
        $expect($actual['compiler_registry_loaded'] === ($mode === 'staged'), $mode . ' native callbacks avoid compiler discovery while custom closures retain Twig fallback');
        $expect(($actual['compiler_families'] !== []) === ($mode === 'staged'), $mode . ' native execution does not autoload token-parser/node-visitor families');
    }
    $nativeAssets = $sourceResults['native']['assets'];
    $scripts = array_values($nativeAssets['scripts']['footer'] ?? []);
    $styles = array_values($nativeAssets['styles']['footer'] ?? []);
    $expect(count($scripts) === 2 && count($styles) === 2, 'assets/scripts/styles inherited node paths register actual HtmlBlock records');
    $expect(array_column($scripts, 'src') === ['/fixture/runtime.js', '/fixture/scripts.js'] && array_column($scripts, ':priority') === [7, 11] && $scripts[0]['defer'] === true, 'native script order/priority/defer are exact');
    $expect(array_column($styles, 'href') === ['/fixture/runtime.css', '/fixture/styles.css'] && array_column($styles, ':priority') === [7, 13], 'native style order/priority are exact');
    foreach (['extension', 'staged'] as $mode) {
        $calls = $sourceResults[$mode]['custom_assets'];
        $expect(count($calls) === 3 && array_column($calls, 1) === ['footer', 'footer', 'footer'] && array_column($calls, 2) === [7, 11, 13] && str_contains($calls[0][0], '/fixture/runtime.js'), $mode . ' selected parse_assets handles assets/scripts/styles content/location/priority');
        $expect($sourceResults[$mode]['callback_options'] === [[true, 'JoomlaMafia', 33.3], [true, 'JoomlaMafia', 33.3], [true, 'JoomlaMafia', 33.3]], $mode . ' selected callback receives native needs_environment/needs_context options');
        $expect(!isset($sourceResults[$mode]['assets']['scripts'], $sourceResults[$mode]['assets']['styles']), $mode . ' custom parse_assets override does not secretly call native collector');
    }
    $theme = new RuntimeFixtureTheme($root);
    $runtime = new ThemeRuntime($theme);
    $expect($runtime->toGrid(16.7) === $theme->toGrid(16.7) && $runtime->toGrid(100) === $theme->toGrid(100), 'runtime invokes actual native ThemeTrait implementation');
    $other = new class($root) extends RuntimeFixtureTheme { public function toGrid($text) { return 'bound:' . $text; } };
    $gantry['theme'] = $theme;
    $expect((new ThemeRuntime($other))->toGrid(50) === 'bound:50', 'runtime binds its supplied theme rather than a global theme');
    $twig = $theme->extendTwig(new Environment(new Twig\Loader\ArrayLoader()));
    $otherTwig = $other->extendTwig(new Environment(new Twig\Loader\ArrayLoader()));
    $expect($twig->getRuntime(ThemeRuntime::class)->toGrid(50) === 'size-50' && $otherTwig->getRuntime(ThemeRuntime::class)->toGrid(50) === 'bound:50', 'independent native environments resolve their own bound theme runtimes');
    $expect($twig->getRuntime(ThemeRuntime::class) !== $otherTwig->getRuntime(ThemeRuntime::class) && $twig->getRuntime(ThemeRuntime::class)->toGrid(50) === 'size-50', 'runtime identity stays isolated across independent environments');
    $core = $twig->getExtension(CoreExtension::class); $core->setTimezone('Pacific/Auckland');
    $time = new DateTimeImmutable('2026-11-02T23:30:00Z');
    foreach ([null, 'UTC', false, new DateTimeZone('America/New_York')] as $zone) {
        $expect($runtime->dateFilter($twig, $time, 'Y-m-d H:i T', $zone) === $theme->twig_dateFilter($twig, $time, 'Y-m-d H:i T', $zone), 'date runtime preserves exact bound theme/environment/timezone conversion');
    }
    $expect($runtime->dateFilter($twig, new DateInterval('P2DT3H')) === '2 days 3 hours', 'date runtime retains native interval default format');
    $expect($twig->getExtension(CoreExtension::class) === $core && $core->getTimezone()->getName() === 'Pacific/Auckland', 'runtime never replaces native CoreExtension state');
    echo "PASS twig-runtime: {$checks} native dispatch/compiled-artifact checks on PHP " . PHP_VERSION . "\n";
} finally {
    $remove($root);
    restore_error_handler();
}
