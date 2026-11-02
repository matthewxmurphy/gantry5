<?php
/**
 * Native Joomla early-document admission in a real PHP HTTP process.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Render\RenderRegistry;
use Gantry5\Loader;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Application\WebApplication;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Document\RawDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Input\Input;

if (PHP_SAPI === 'cli-server') {
    if (!isset($admissionFixture) || !is_array($admissionFixture)) { http_response_code(404); exit; }
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        define('_JEXEC', 1);
        define('JDEBUG', false);
        define('GANTRY5_PLATFORM', 'joomla');
        define('JPATH_SITE', $admissionFixture['site']);
        $loader = require $admissionFixture['gantry'];
        $loader->addPsr4('Gantry\\', $admissionFixture['source'] . '/src/classes/Gantry', true);
        $native = require $admissionFixture['joomla'];
        $native->addPsr4('Joomla\\CMS\\', $admissionFixture['cms'], true);
        $checks = 0;
        $check = static function (bool $condition, string $label) use (&$checks): void {
            if (!$condition) { throw new RuntimeException('Failed admission: ' . $label); }
            ++$checks;
        };
        $check((new ReflectionClass(Factory::class))->getFileName() === $admissionFixture['cms'] . '/Factory.php', 'actual native Joomla Factory is loaded');
        $check((new ReflectionClass(SiteApplication::class))->getFileName() === $admissionFixture['cms'] . '/Application/SiteApplication.php', 'actual final Joomla SiteApplication is loaded');
        // These are real native objects at the exact pre-initialisation stage.
        // The full constructor would bootstrap sessions/DB; admission only needs
        // Joomla's real client, input and nullable document accessors.
        $application = static function (string $class, string $client, array $query = [], ?Document $document = null): CMSApplication {
            $app = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(CMSApplication::class, 'name'))->setValue($app, $client);
            (new ReflectionProperty(WebApplication::class, 'input'))->setValue($app, new Input($query));
            (new ReflectionProperty(WebApplication::class, 'document'))->setValue($app, $document);
            Factory::$application = $app;
            return $app;
        };
        $app = $application(SiteApplication::class, 'site');
        $check($app->getDocument() === null && $app->getInput() instanceof Input, 'native early routing has real input and no document');
        $check(BundleRuntime::isSource() === false, 'early default HTML is strict before document creation');
        $application(SiteApplication::class, 'site', ['format' => 'html']);
        $check(BundleRuntime::isSource() === false, 'early explicit HTML is strict');
        $application(SiteApplication::class, 'site', ['format' => 'json']);
        $check(BundleRuntime::isSource() === true, 'early JSON remains source mode');
        $application(SiteApplication::class, 'site', ['format' => 'raw']);
        $check(BundleRuntime::isSource() === true, 'early raw remains source mode');
        $documentOptions = static fn (): array => ['webAssetManager' => new WebAssetManager(new WebAssetRegistry())];
        $html = new HtmlDocument($documentOptions());
        $application(SiteApplication::class, 'site', ['format' => 'json'], $html);
        $check(BundleRuntime::isSource() === false, 'initialized native HTML wins over JSON query');
        $raw = new RawDocument($documentOptions());
        $application(SiteApplication::class, 'site', ['format' => 'html'], $raw);
        $check(BundleRuntime::isSource() === true, 'initialized native raw wins over HTML query');
        $application(AdministratorApplication::class, 'administrator');
        $check(BundleRuntime::isSource() === true, 'native administrator client remains source mode without a document');
        $application(ApiApplication::class, 'api');
        $check(BundleRuntime::isSource() === true, 'native API client remains source mode without a document');
        $application(SiteApplication::class, 'site');
        BundleRuntime::source(static function () use ($check): void {
            $check(BundleRuntime::isSource() === true, 'explicit build scope overrides early HTML admission');
        });
        $check(BundleRuntime::isSource() === false, 'leaving build scope restores strict early HTML');
        try { BundleRuntime::source(static function (): never { throw new RuntimeException('owned scope interruption'); }); }
        catch (RuntimeException $error) { if ($error->getMessage() !== 'owned scope interruption') { throw $error; } }
        $check(BundleRuntime::isSource() === false, 'exceptional build-scope exit also restores strict admission');

        // Use the real plugin and native Loader with its real Composer loader.
        // Nothing here bootstraps a site or substitutes a Factory/app/Loader.
        require_once $admissionFixture['source'] . '/src/Loader.php';
        (new ReflectionProperty(Loader::class, 'loader'))->setValue(null, $loader);
        if (Loader::get() !== $loader || (new ReflectionClass(Loader::class))->getFileName() !== $admissionFixture['source'] . '/src/Loader.php') {
            throw new RuntimeException('The native Gantry Loader was not admitted.');
        }
        require_once $admissionFixture['source'] . '/platforms/joomla/plg_system_gantry5/gantry5.php';
        $plugin = (new ReflectionClass(plgSystemGantry5::class))->newInstanceWithoutConstructor();
        $recognizes = new ReflectionMethod(plgSystemGantry5::class, 'isGantryTemplate');
        mkdir(JPATH_SITE . '/templates/unregistered', 0700, true);
        $compiled = JPATH_SITE . '/' . RenderRegistry::DIRECTORY . '/compiled';
        mkdir($compiled, 0700, true);
        $check(!file_exists(JPATH_SITE . '/templates/compiled/gantry/theme.yaml') && !file_exists($compiled . '/current.php')
            && $recognizes->invoke($plugin, 'compiled') === true, 'registered compiled theme remains recognized without source YAML or a current pointer');
        file_put_contents($compiled . '/current.php', "<?php exit; ?>\ncorrupt-pointer\n");
        $check($recognizes->invoke($plugin, 'compiled') === true, 'a corrupt compiled pointer stays on the strict Gantry route for runtime refusal');
        $check($recognizes->invoke($plugin, 'unregistered') === false, 'unregistered theme without source YAML is not Gantry');
        mkdir(JPATH_SITE . '/templates/source_only/gantry', 0700, true);
        file_put_contents(JPATH_SITE . '/templates/source_only/gantry/theme.yaml', "details:\n  name: Native fixture\n");
        $check($recognizes->invoke($plugin, 'source_only') === true, 'original source-only installed theme remains recognized');
        foreach (['../compiled', 'compiled/child', '', 'invalid.name', "compiled\0x", ['compiled']] as $name) {
            $check($recognizes->invoke($plugin, $name) === false, 'invalid or traversing native template name is refused');
        }
        $symlinkChecked = PHP_OS_FAMILY !== 'Windows' && function_exists('symlink');
        if ($symlinkChecked) {
            symlink($compiled, dirname($compiled) . '/linked');
            $check($recognizes->invoke($plugin, 'linked') === false, 'linked compiled theme directory cannot establish Gantry admission');
        }
        header('Content-Type: application/json');
        echo json_encode(['checks' => $checks, 'sapi' => PHP_SAPI, 'php' => PHP_VERSION, 'symlink_checked' => $symlinkChecked], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => get_class($error) . ': ' . $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[3])
    || !is_file($argv[1]) || !is_file($argv[2]) || !is_file($argv[3] . '/Application/SiteApplication.php') || !extension_loaded('curl')) {
    throw new RuntimeException('Use PHP8.5 with curl, Gantry/Joomla vendor autoloaders and the verified native Joomla libraries/src directory.');
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-native-admission-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$server = null;
try {
    $fixture = ['gantry' => realpath($argv[1]), 'joomla' => realpath($argv[2]), 'cms' => realpath($argv[3]), 'source' => dirname(__DIR__, 2), 'site' => $temporary . '/site'];
    $router = $temporary . '/router.php';
    file_put_contents($router, '<?php $admissionFixture = ' . var_export($fixture, true) . '; require ' . var_export(__FILE__, true) . ";\n");
    chmod($router, 0600);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!$socket) { throw new RuntimeException('Could not reserve the isolated admission HTTP port.'); }
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $log = $temporary . '/http.log';
    $server = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $temporary, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($server)) { throw new RuntimeException('Could not start the isolated admission HTTP process.'); }
    fclose($pipes[0]);
    $body = false;
    $status = 0;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $curl = curl_init('http://127.0.0.1:' . $port . '/admission');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT_MS => 3000]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($body !== false) { break; }
        usleep(10000);
    }
    if ($status !== 200 || !is_string($body)) { throw new RuntimeException('Native admission HTTP fixture failed: ' . (string) $body); }
    $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    $expected = 24 + (!empty($result['symlink_checked']) ? 1 : 0);
    if (($result['sapi'] ?? null) !== 'cli-server' || ($result['checks'] ?? null) !== $expected) { throw new RuntimeException('Native HTTP admission checks were incomplete.'); }
    proc_terminate($server);
    proc_close($server);
    $server = null;
    if (preg_match('/PHP (?:Warning|Fatal|Parse error|Deprecated)|Uncaught/i', file_get_contents($log))) {
        throw new RuntimeException('The native admission HTTP process emitted a PHP diagnostic.');
    }
    echo 'PASS ' . $result['checks'] . ' native Joomla HTTP render-admission checks on PHP ' . $result['php'] . "\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) { $remove($file->getPathname()); }
        rmdir($path);
    };
    $remove($temporary);
}
