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
    ] as [$id, $name, $type, $folder, $element, $enabled, $manifest]) {
        $record = (object) ['extension_id' => $id, 'name' => $name,
            'type' => $type, 'folder' => $folder, 'element' => $element, 'enabled' => $enabled, 'manifest_cache' => $manifest];
        $db->insertObject('#__extensions', $record);
    }
    $rows = ExtensionApiInventory::read($db, OpenApi::document());
    $check(array_column($rows, 'id') === [5, 6, 7, 8], 'one ordered row per extension');
    $check(array_column($rows, 'state') === ['documented', 'discovered', 'none', 'none'], 'evidence states and disabled provider');
    $check(array_column($rows, 'version') === ['6.0.3', null, '1.0', '<bad>'], 'invalid manifest is safely treated as unknown');
    $check(count($rows[0]['operations']) === 12 && in_array('POST /v1/gantry/themes/{theme}/changes/undo', $rows[0]['operations'], true), 'real OpenAPI operations');
    $check($rows[1]['operations'] === [] && $rows[2]['operations'] === [], 'no invented endpoints');
    $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($source . '/platforms/common/templates/pages'), ['autoescape' => false]);
    $html = $twig->render('api-extension-inventory.html.twig', ['api_extension_inventory' => $rows,
        'gantry' => new class { public function route($controller, $path): string { return '/administrator/' . $controller . '/' . $path; } }]);
    $check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, '&lt;bad&gt;'), 'untrusted manifest fields are escaped');
    $check(substr_count($html, 'Documented operations') === 1 && substr_count($html, 'Discovered Web Services provider') === 1, 'rendered evidence states');
    $check(str_contains($html, 'Joomla API token and Gantry settings-administrator permission'), 'native auth and ACL disclosed');
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
    $check($page->document()->getStatusCode() === 200, 'existing OpenAPI JSON action remains available');
    $inventory = $page->execute('GET', ['inventory'], []);
    $body = json_decode((string) $inventory, true, flags: JSON_THROW_ON_ERROR);
    $check($inventory->getStatusCode() === 200 && $body['schema_version'] === 1, 'versioned native GET inventory response');
    $check($body['extensions'] === $page->context['api_extension_inventory'], 'HTML and JSON use the same installed rows');
    $contract = $body['extensions'][0]['contract'];
    $check($contract['version'] === '3.0.3' && $contract['server'] === '/fixture/api/index.php'
        && $contract['security_schemes']['JoomlaToken']['name'] === 'X-Joomla-Token'
        && $contract['permission'] === 'platform.settings.manage', 'Gantry declaration uses shipped OpenAPI metadata');
    $check($body['extensions'][1]['contract'] === null && $body['extensions'][3]['contract'] === null, 'unknown declarations are explicitly null');
    $headers = array_change_key_case($inventory->getHeaders());
    $check(in_array('private, no-store', (array) $headers['cache-control'], true), 'inventory cannot be shared-cached');


    $check($platformEvents <= 1 && error_reporting() === -1, 'only known Joomla SQLite event and inherited native mask');
    echo "PASS {$checks} Joomla extension inventory checks on PHP " . PHP_VERSION . "; Joomla SQLite events {$platformEvents}\n";
} finally {
    restore_error_handler();
}
