<?php
/**
 * Joomla database and Gantry API inventory contract fixture.
 * Usage: php tests/php85/joomla-extension-api-inventory.php <Gantry autoload> <Joomla autoload>
 */
declare(strict_types=1);

use Gantry\Admin\Controller\Html\Api;
use Gantry\Component\Api\OpenApi;
use Gantry\Framework\Gantry;
use Gantry\Framework\Request;
use Gantry\Joomla\ExtensionApiInventory;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Sqlite\SqliteDriver;
use Joomla\DI\Container;
use Joomla\Registry\Registry;

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !extension_loaded('pdo_sqlite')
    || !isset($argv[1], $argv[2]) || !is_file($argv[1]) || !is_file($argv[2])) {
    throw new RuntimeException('Use PHP 8.5, PDO SQLite, and installed Gantry and Joomla autoloaders.');
}
$source = dirname(__DIR__, 2);
define('_JEXEC', 1);
define('GANTRY_DEBUGGER', false);
define('GANTRY5_DEBUG', false);
require $argv[2];
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
$loader->addPsr4('Joomla\\CMS\\', dirname(realpath($argv[2]), 2) . '/src', true);
require $source . '/src/platforms/joomla/classes/Gantry/Joomla/ExtensionApiInventory.php';

error_reporting(-1);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
};
$db = new SqliteDriver(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'fixture_']);
$plugins = sys_get_temp_dir() . '/gantry-api-inventory-' . bin2hex(random_bytes(8));
define('JPATH_PLUGINS', $plugins);
$schema = json_encode([
    'openapi' => '3.1.0',
    'info' => ['title' => 'Widget API', 'version' => '1.0'],
    'paths' => ['/widgets' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]],
], JSON_THROW_ON_ERROR);
$declarations = [
    'declared' => $schema,
    'invalid' => '{',
    'disabled' => $schema,
    'oversized' => $schema . str_repeat(' ', 262144),
];
foreach ($declarations as $element => $content) {
    $directory = "$plugins/webservices/$element";
    mkdir($directory, 0700, true);
    file_put_contents("$directory/openapi.json", $content);
}
$linked = "$plugins/webservices/linked";
mkdir($linked, 0700);
symlink("$plugins/webservices/declared/openapi.json", "$linked/openapi.json");
$sqliteFile = realpath((new ReflectionClass(SqliteDriver::class))->getFileName());
$platformEvents = 0;
set_error_handler(static function (int $level, string $message, string $file, int $line) use ($sqliteFile, &$platformEvents): bool {
    if ($level === E_DEPRECATED && realpath($file) === $sqliteFile && str_contains($message, 'PDO::sqliteCreateFunction() is deprecated')) {
        ++$platformEvents;
        return true;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});
try {
    $gantry = new Gantry();
    $gantry['request'] = new Request();
    $gantry['platform'] = new class {
        public function authorize($action, $id): bool { return false; }
    };
    foreach (['index', 'document', 'inventory'] as $method) {
        try {
            (new Api($gantry))->$method();
            throw new RuntimeException('Unauthorized request was accepted.');
        } catch (RuntimeException $exception) {
            $check($exception->getCode() === 403, $method . ' denied before inventory or schema reads');
        }
    }

    $db->setQuery('CREATE TABLE #__extensions (extension_id INTEGER PRIMARY KEY, name TEXT, type TEXT, folder TEXT, element TEXT, enabled INTEGER, manifest_cache TEXT)')->execute();
    foreach ([
        [8, '<script>alert(1)</script>', 'component', '', 'com_example', 1, '{"version":"<bad>"}'],
        [7, 'Disabled provider', 'plugin', 'webservices', 'disabled', 0, '{"version":"1.0"}'],
        [5, 'Gantry', 'plugin', 'system', 'gantry5', 1, '{"version":"6.0.3"}'],
        [6, 'Active provider', 'plugin', 'webservices', 'content', 1, 'invalid JSON'],
        [9, 'Declared provider', 'plugin', 'webservices', 'declared', 1, '{}'],
        [10, 'Invalid declaration', 'plugin', 'webservices', 'invalid', 1, '{}'],
        [11, 'Unsafe element', 'plugin', 'webservices', 'declared/../declared', 1, '{}'],
        [12, 'Oversized declaration', 'plugin', 'webservices', 'oversized', 1, '{}'],
        [13, 'Linked declaration', 'plugin', 'webservices', 'linked', 1, '{}'],
    ] as [$id, $name, $type, $folder, $element, $enabled, $manifest]) {
        $record = (object) ['extension_id' => $id, 'name' => $name,
            'type' => $type, 'folder' => $folder, 'element' => $element, 'enabled' => $enabled, 'manifest_cache' => $manifest];
        $db->insertObject('#__extensions', $record);
    }
    $rows = ExtensionApiInventory::read($db, OpenApi::document());
    $check(array_column($rows, 'id') === [5, 6, 7, 8, 9, 10, 11, 12, 13], 'one ordered row per extension');
    $check(array_column($rows, 'state') === [
        'documented', 'discovered', 'none', 'none', 'documented', 'discovered', 'discovered', 'discovered', 'discovered',
    ], 'evidence states and disabled provider');
    $check(array_column($rows, 'version') === ['6.0.3', null, '1.0', '<bad>', null, null, null, null, null], 'invalid manifest is safely treated as unknown');
    $check(count($rows[0]['operations']) === 12 && in_array('POST /v1/gantry/themes/{theme}/changes/undo', $rows[0]['operations'], true), 'real OpenAPI operations');
    $check($rows[1]['operations'] === [] && $rows[2]['operations'] === [], 'no invented endpoints');
    $check($rows[4]['operations'] === ['GET /widgets'] && $rows[4]['contract'] === [
        'format' => 'openapi', 'version' => '3.1.0', 'source' => 'installed-plugin',
    ], 'packaged contract exposes declared operations without claiming access details');
    $check($rows[2]['contract'] === null && $rows[5]['contract'] === null && $rows[6]['contract'] === null,
        'disabled, malformed and unsafe declarations expose no contract');
    $check($rows[7]['contract'] === null && $rows[8]['contract'] === null, 'oversized and linked declarations expose no contract');
    $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($source . '/platforms/common/templates/pages'), ['autoescape' => false]);
    $html = $twig->render('api-extension-inventory.html.twig', ['api_extension_inventory' => $rows,
        'gantry' => new class { public function route($controller, $path): string { return '/administrator/' . $controller . '/' . $path; } }]);
    $check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, '&lt;bad&gt;'), 'untrusted manifest fields are escaped');
    $check(substr_count($html, 'Documented operations') === 2 && substr_count($html, 'Discovered Web Services provider') === 5, 'rendered evidence states');
    $check(str_contains($html, 'Joomla API token and Gantry settings-administrator permission'), 'native auth and ACL disclosed');
    $check(str_contains($html, 'Declared by installed plugin') && str_contains($html, 'GET /widgets')
        && substr_count($html, 'Joomla API token and Gantry settings-administrator permission') === 1,
        'HTML identifies the third-party declaration separately from Gantry authentication');
    $check(ExtensionApiInventory::read($db, OpenApi::document()) === $rows, 'read-only repeated inventory');
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/fixture/administrator/index.php';
    $nativeContainer = new Container();
    $nativeContainer->set(DatabaseInterface::class, $db);
    $nativeContainer->set('config', new Registry(['live_site' => 'http://localhost/fixture']));
    Factory::$container = $nativeContainer;
    $gantry['theme.name'] = 'fixture';
    $gantry['platform'] = new class {
        public function getName(): string { return 'joomla'; }
        public function authorize($action, $id): bool { return $action === 'platform.settings.manage'; }
    };
    $page = new class ($gantry) extends Api {
        public array $context;
        public function render($file, array $context = []): string
        {
            $this->context = $context;
            return $file;
        }
    };
    $response = $page->index();
    $check($response->getStatusCode() === 200 && array_column($page->context['api_extension_inventory'], 'id') === array_column($rows, 'id'), 'authorized controller exposes the real inventory');
    $check(json_decode($page->context['api_spec_json'], true)['servers'][0]['url'] === '/fixture/api/index.php', 'subdirectory API URL is preserved');
    $document = $page->document();
    $check($document->getStatusCode() === 200, 'existing OpenAPI JSON action remains available');
    $inventory = $page->execute('GET', ['inventory'], []);
    $body = json_decode((string) $inventory, true, flags: JSON_THROW_ON_ERROR);
    $check($inventory->getStatusCode() === 200 && $body['schema_version'] === 1, 'versioned native GET inventory response');
    $check($body['extensions'] === $page->context['api_extension_inventory'], 'HTML and JSON use the same installed rows');
    $review = $body['template_library_review'];
    $check($review === $page->context['template_library_review'], 'HTML and JSON share the template review separately from installed extensions');
    $check($review['report'] === 'TEMPLATE-PACKS-PRECHECK-2026-11-03' && $review['source'] === 'owner_relay'
        && $review['reconciled_packs'] === 670 && $review['render_checks'] === ['passed' => 669, 'total' => 669]
        && $review['authenticated_acceptance'] === 'pending', 'review identifies its source and leaves authenticated acceptance pending');
    $check($review['lineages'] === [
        'tc-faq' => 'drop', 'tc-gallery' => 'merge_partial', 'tc-hero' => 'merge_partial',
        'tc-pricing' => 'drop', 'tc-testimonials' => 'merge_partial', 'tc-text-block' => 'merge_partial',
    ] && $review['partial_merge_scope'] === 'feature_ports_only'
        && array_keys($review['remainders']) === ['R1', 'R5', 'R6'], 'all six lineage decisions and packaging remainders');
    $reviewHtml = $twig->render('api-extension-inventory.html.twig', $page->context + [
        'gantry' => new class { public function route($controller, $path): string { return '/administrator/' . $controller . '/' . $path; } },
    ]);
    $check(substr_count($reviewHtml, 'Merge partial, feature ports only.') === 4
        && substr_count($reviewHtml, 'Drop.') === 2 && str_contains($reviewHtml, 'Authenticated acceptance is pending.'), 'admin shows lineage scope and pending acceptance');
    $contract = $body['extensions'][0]['contract'];
    $check($contract['version'] === '3.0.3' && $contract['server'] === '/fixture/api/index.php'
        && $contract['security_schemes']['JoomlaToken']['name'] === 'X-Joomla-Token'
        && $contract['permission'] === 'platform.settings.manage', 'Gantry declaration uses shipped OpenAPI metadata');
    $check($body['extensions'][1]['contract'] === null && $body['extensions'][3]['contract'] === null, 'unknown declarations are explicitly null');
    foreach (['OpenAPI' => $document, 'inventory' => $inventory] as $label => $json) {
        $headers = array_change_key_case($json->getHeaders());
        $check($headers['cache-control'] === ['private, no-store']
            && $headers['x-content-type-options'] === ['nosniff'], $label . ' declares the exact JSON header policy');
    }


    $check($platformEvents <= 1 && error_reporting() === -1, 'only known Joomla SQLite event and inherited native mask');
    echo "PASS {$checks} Joomla extension inventory checks on PHP " . PHP_VERSION . "; Joomla SQLite events {$platformEvents}\n";
} finally {
    restore_error_handler();
    unlink("$linked/openapi.json");
    rmdir($linked);
    foreach (array_keys($declarations) as $element) {
        unlink("$plugins/webservices/$element/openapi.json");
        rmdir("$plugins/webservices/$element");
    }
    rmdir("$plugins/webservices");
    rmdir($plugins);
}
