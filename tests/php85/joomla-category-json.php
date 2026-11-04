<?php
/**
 * Run the real Gantry category initializer against an installed Joomla fixture.
 * php tests/php85/joomla-category-json.php /path/to/joomla [category-source]
 */
declare(strict_types=1);

use Gantry\Joomla\Category\Category;

$root = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !is_dir($root . '/libraries/gantry5')) {
    throw new RuntimeException('Use PHP 8.5 and an installed Joomla Gantry fixture.');
}

define('_JEXEC', 1);

$joomla = require $root . '/libraries/vendor/autoload.php';
$joomla->addPsr4('Joomla\\CMS\\', $root . '/libraries/src');
require $root . '/libraries/gantry5/vendor/autoload.php';
require $argv[2] ?? dirname(__DIR__, 2) . '/src/platforms/joomla/classes/Gantry/Joomla/Category/Category.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$cases = [
    'valid objects' => [
        ['params' => '{"key":"value"}', 'metadata' => '{"author":"example"}'],
        [(object) ['key' => 'value'], (object) ['author' => 'example']],
    ],
    'null params with valid metadata' => [
        ['params' => null, 'metadata' => '{"author":"example"}'],
        [null, (object) ['author' => 'example']],
    ],
    'valid params with null metadata' => [
        ['params' => '{"key":"value"}', 'metadata' => null],
        [(object) ['key' => 'value'], null],
    ],
    'empty strings' => [['params' => '', 'metadata' => ''], [null, null]],
    'missing properties' => [[], [null, null]],
    'malformed JSON' => [['params' => '{', 'metadata' => '{'], [null, null]],
    'valid non-object JSON' => [['params' => '[0,1]', 'metadata' => 'false'], [[0, 1], false]],
];
foreach ($cases as $label => [$input, $expected]) {
    $category = new Category($input);
    if (!$category->initialize()
        || serialize([$category->params, $category->metadata]) !== serialize($expected)
        || $category->initialize()) {
        throw new RuntimeException('Failed category JSON case: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
