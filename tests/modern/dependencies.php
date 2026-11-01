<?php
/**
 * Actual modern event, YAML, Twig parser/capture and filesystem-cache acceptance.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Usage: php tests/modern/dependencies.php /path/to/modern/vendor/autoload.php
 * This exercises shared services; it does not boot or impersonate a CMS.
 */
use Gantry\Component\Event\Event;
use Gantry\Component\Twig\TwigCacheFilesystem;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFunction;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.3/8.4/8.5 and the resolved modern dependency autoloader.');
}
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', dirname(__DIR__, 2) . '/src/classes/Gantry', true);
// The shared container declaration has an entry marker, not a CMS dependency.
// Instantiate that real container without calling its CMS-initializing static init.
define('GRAV_ROOT', sys_get_temp_dir());
define('GANTRY_DEBUGGER', false);
define('GANTRY5_DEBUG', false);
$checks = 0;
$expect = static function ($condition, $label) use (&$checks) {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$root = sys_get_temp_dir() . '/gantry-modern-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$remove = static function ($path) use (&$remove) {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $remove($entry->getPathname()); } }
        rmdir($path);
    } else { unlink($path); }
};
try {
    $dispatcher = new EventDispatcher();
    $gantry = new class extends \Gantry\Framework\Base\Gantry {};
    $gantry['events'] = $dispatcher;
    $gantry['global'] = new \Gantry\Component\Config\Config(['debug' => false]);
    $seen = [];
    $dispatcher->addListener('native.event', static function (Event $event, $name, $actual) use (&$seen, $dispatcher) {
        $seen[] = $name;
        if ($actual !== $dispatcher) { throw new RuntimeException('Wrong native dispatcher argument.'); }
        // Toolbox 1 and 2 both expose value getters, not references.
        $event['counter'] = $event['counter'] + 1;
        $event->stopPropagation();
    }, 10);
    $dispatcher->addListener('native.event', static function () use (&$seen) { $seen[] = 'should not run'; }, 0);
    $event = new Event(['counter' => 1]);
    $returned = $gantry->fireEvent('native.event', $event);
    $expect($returned === $event, 'native event-first dispatch returns same Gantry payload');
    $expect($event['counter'] === 2 && $event->toArray() === ['counter' => 2], 'retained Toolbox array payload mutation/export');
    $expect($seen === ['native.event'] && $event->isPropagationStopped(), 'listener priority and propagation stop preserved');
    $expect($gantry->fireEvent('empty.event') instanceof Event, 'omitted payload creates a real Gantry event');
    $subscriber = new class implements EventSubscriberInterface {
        public $seen = false;
        public static function getSubscribedEvents() { return ['subscriber.event' => ['receive', 4]]; }
        public function receive(Event $event) { $this->seen = $event['text']; }
    };
    $dispatcher->addSubscriber($subscriber);
    $gantry->fireEvent('subscriber.event', new Event(['text' => 'real subscriber']));
    $expect($subscriber->seen === 'real subscriber', 'maintained Symfony subscriber interface receives Gantry payload');
    $styleEvent = new \Gantry\Admin\Events\StylesEvent();
    $styleEvent->data = ['header' => ['background' => '#123abc']];
    $expect($styleEvent instanceof Event && $styleEvent->data['header']['background'] === '#123abc', 'existing public admin event fields remain usable');

    $yaml = ['palette' => ['accent' => '#123abc'], 'enabled' => false, 'names' => ['one', 'two'], 'text' => "Synthetic: café\nsecond line"];
    $expect(Yaml::parse(Yaml::dump($yaml, 8, 2)) === $yaml, 'actual Symfony 7 YAML preserves nested values and types');

    foreach ([false, true] as $yield) {
        $captured = [];
        $cache = new TwigCacheFilesystem($root . ($yield ? '/yield' : '/buffer'), TwigCacheFilesystem::FORCE_BYTECODE_INVALIDATION);
        $environment = new Environment(new ArrayLoader([
            'switch' => "{% switch kind %}{% case 'a' or 'b' %}match{% default %}fallback{% endswitch %}:{{ text }}",
            'capture' => "before{% assets in 'head' with {priority: 4} %}<style>{{ color }}</style>{% endassets %}after",
            'try' => "{% try %}{% throw 409 'synthetic failure' %}{% catch %}caught:{{ e.code }}{% endcatch %}",
        ]), ['cache' => $cache, 'autoescape' => 'html', 'use_yield' => $yield]);
        $environment->addExtension(new class extends AbstractExtension {
            public function getTokenParsers(): array {
                return [new \Gantry\Component\Twig\TokenParser\TokenParserSwitch(), new \Gantry\Component\Twig\TokenParser\TokenParserAssets(),
                    new \Gantry\Component\Twig\TokenParser\TokenParserPageblock(), new \Gantry\Component\Twig\TokenParser\TokenParserMarkdown(),
                    new \Gantry\Component\Twig\TokenParser\TokenParserThrow(), new \Gantry\Component\Twig\TokenParser\TokenParserTryCatch()];
            }
        });
        // A normal Twig callback records actual output capture; no Gantry class is mocked.
        $environment->addFunction(new TwigFunction('parse_assets', static function ($content, $location, $priority) use (&$captured) {
            $captured[] = [$content, $location, $priority];
        }));
        $expect($environment->render('switch', ['kind' => 'b', 'text' => '<tag>']) === 'match:&lt;tag&gt;', 'actual switch multiple-case branch and escaping');
        $expect($environment->render('switch', ['kind' => 'z', 'text' => 'plain']) === 'fallback:plain', 'actual switch default branch');
        $expect($environment->render('capture', ['color' => '#123abc']) === 'beforeafter', 'captured assets never leak into page output');
        $expect($captured === [['<style>#123abc</style>', 'head', 4]], 'real yield-aware capture passes full body/location/priority');
        $expect($environment->render('try', ['gantry' => $gantry]) === 'caught:409', 'actual throw/try/catch preserves the exception value');
        // Compile/evaluate declarations only; Pageblock/Markdown CMS destinations
        // are exercised by native CMS rendering, not represented by fake services.
        foreach (['pageblock' => "{% pageblock head with {} %}<meta name='synthetic'>{% endpageblock %}",
            'markdown' => "{% markdown %}**synthetic**{% endmarkdown %}"] as $name => $source) {
            $templateName = $name . ($yield ? '-yield' : '-buffer');
            $environment->getLoader()->setTemplate($templateName, $source);
            $php = $environment->compileSource(new Source($source, $templateName));
            eval('?>' . $php);
            $expect(true, 'native PHP accepts compiled ' . $name . ' node');
        }
        $key = $cache->generateKey('switch', $environment->getTemplateClass('switch'));
        $expect(is_file($key) && $cache->getTimestamp($key) > 0, 'actual typed filesystem cache writes compiled template');
        $expect($environment->render('switch', ['kind' => 'a', 'text' => 'cached']) === 'match:cached', 'compiled cache remains reusable');
    }
    echo 'PASS ' . $checks . ' modern shared-service checks on PHP ' . PHP_VERSION . '; Twig ' . Environment::VERSION . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Failed after ' . $checks . " completed checks.\n");
    throw $error;
} finally {
    $remove($root);
}
