<?php
/**
 * Joomla change-state adapter against actual Joomla Database/DI/Factory classes.
 * php tests/php85/joomla-change-state.php <gantry-vendor/autoload.php>
 *     <joomla-libraries/vendor/autoload.php> [gantry-source-root] [joomla-Factory.php]
 * Uses an isolated SQLite memory database and private temporary theme paths.
 * It does not bootstrap a CMS site, use credentials, or certify staging MariaDB.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Api\ApiException;
use Gantry\Framework\ChangeState;
use Gantry\Framework\Gantry;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Cache\CacheControllerFactory;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\User\UserFactory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Sqlite\SqliteDriver;
use Joomla\DI\Container;
use Joomla\Registry\Registry;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !extension_loaded('pdo_sqlite')
    || !isset($argv[1], $argv[2]) || !is_file($argv[1]) || !is_file($argv[2])) {
    throw new RuntimeException('Use actual PHP 8.5 with PDO SQLite and the existing Gantry/Joomla Composer autoloaders.');
}
$source = $argv[3] ?? dirname(__DIR__, 2);
$factory = $argv[4] ?? dirname($argv[2], 2) . '/src/Factory.php';
if (!is_file($factory)) { throw new RuntimeException('Supply the actual Joomla CMS Factory.php source path.'); }
define('_JEXEC', 1);
define('GANTRY_DEBUGGER', false);
require $argv[2];
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
$loader->addPsr4('Joomla\\CMS\\', dirname(realpath($factory)), true);
if (!is_file(dirname($factory) . '/Access/Access.php')) { throw new RuntimeException('Supply complete native Joomla libraries/src, including Access/Access.php.'); }
require_once $factory;
// Explicitly test the real Joomla overlay, rather than the standalone adapter.
require $source . '/src/platforms/joomla/classes/Gantry/Framework/ChangeState.php';
error_reporting(E_ALL);
$strictDiagnostics = static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
};
set_error_handler($strictDiagnostics);

$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-joomla-state-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GANTRY5_ROOT', $temporary);
define('JPATH_CACHE', $temporary . '/cache');
define('JPATH_ROOT', $temporary);
define('JPATH_BASE', $temporary);
define('JPATH_LIBRARIES', dirname(realpath($factory), 2));
define('JDEBUG', false);
$oldContainer = Factory::$container;
$oldApplication = Factory::$application;
$oldConfig = Factory::$config;
$db = null;
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$conflict = static function (callable $operation, string $code, string $label) use ($expect): void {
    try { $operation(); }
    catch (ApiException $error) {
        $expect($error->getCode() === 409 && $error->getErrorCode() === $code, $label);
        return;
    }
    throw new RuntimeException('Failed to refuse: ' . $label);
};
try {
    $expect((new ReflectionMethod(DatabaseInterface::class, 'insertObject'))->getParameters()[1]->isPassedByReference(), 'actual Joomla insertObject requires a reference variable');
    $expect((new ReflectionMethod(DatabaseInterface::class, 'updateObject'))->getParameters()[1]->isPassedByReference(), 'actual Joomla updateObject requires a reference variable');
    $expect((new ReflectionMethod(DatabaseInterface::class, 'updateObject'))->getParameters()[3]->getDefaultValue() === false, 'actual Joomla updates omit NULL fields by default');
    $db = new SqliteDriver(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'g342_']);
    $upstreamDeprecations = [];
    $sqliteSource = realpath((new ReflectionClass(SqliteDriver::class))->getFileName());
    // The installed Joomla SQLite bootstrap still registers ROW_NUMBER through
    // PDO's deprecated 8.5 alias. Classify that exact upstream setup diagnostic;
    // no vendor code is changed and every other diagnostic remains an exception.
    set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($strictDiagnostics, $sqliteSource, &$upstreamDeprecations): bool {
        if ($severity === E_DEPRECATED && realpath($file) === $sqliteSource && $message === 'Method PDO::sqliteCreateFunction() is deprecated since 8.5, use Pdo\\Sqlite::createFunction() instead') {
            $upstreamDeprecations[] = $message;
            return true;
        }
        return $strictDiagnostics($severity, $message, $file, $line);
    });
    try { $db->connect(); } finally { restore_error_handler(); }
    $expect(count($upstreamDeprecations) <= 1, 'native SQLite bootstrap diagnostic is specifically counted, never broadly suppressed');
    $db->setQuery('CREATE TABLE #__template_styles (id INTEGER PRIMARY KEY, template TEXT NOT NULL, client_id INTEGER NOT NULL, home TEXT NOT NULL, title TEXT NOT NULL, params TEXT NOT NULL, checked_out INTEGER NULL, checked_out_time TEXT NULL)')->execute();
    $db->setQuery('CREATE TABLE #__menu (id INTEGER PRIMARY KEY, template_style_id INTEGER NOT NULL)')->execute();
    foreach ([
        ['id' => 19, 'template' => 'g6_fixture', 'client_id' => 0, 'home' => '1', 'title' => 'G342 protected default', 'params' => '{"configuration":"19","preset":"default"}', 'checked_out' => null, 'checked_out_time' => null],
        ['id' => 20, 'template' => 'g6_fixture', 'client_id' => 0, 'home' => '0', 'title' => 'G342 JoomlaMafia café', 'params' => '{"configuration":"20","preset":"default"}', 'checked_out' => null, 'checked_out_time' => null],
        ['id' => 30, 'template' => 'other_template', 'client_id' => 0, 'home' => '0', 'title' => 'Unrelated fixture', 'params' => '{}', 'checked_out' => null, 'checked_out_time' => null],
    ] as $row) {
        $record = (object) $row;
        $db->insertObject('#__template_styles', $record);
    }
    $db->setQuery('INSERT INTO #__menu (id, template_style_id) VALUES (1,19)')->execute();
    $container = new Container();
    $container->set(DatabaseInterface::class, $db);
    Factory::$container = $container;
    $theme = $temporary . '/theme';
    mkdir($theme . '/custom/config', 0700, true);
    mkdir($theme . '/custom/language/en-GB', 0700, true);
    file_put_contents($theme . '/templateDetails.xml', '<extension><positions><position>header</position></positions></extension>');
    $positionPath = $theme . '/custom/language/en-GB/en-GB.tpl_g6_fixture_positions.ini';
    file_put_contents($positionPath, 'TPL_G6_FIXTURE_POSITION_HEADER="Header"' . "\n");
    $locator = new UniformResourceLocator($temporary);
    $locator->addPath('gantry-theme', '', [$theme . '/custom', $theme]);
    $gantry = new Gantry();
    $gantry['theme.name'] = 'g6_fixture';
    $gantry['theme.path'] = $theme;
    $gantry['locator'] = static fn () => $locator;
    $adapter = new ChangeState($gantry);
    $expect($adapter->files()['template-details'] === $theme . '/templateDetails.xml', 'native manifest scope matches the Joomla manifest writer');
    $expect($adapter->files()['position-language'] === $positionPath, 'native language scope resolves the actual writable custom override');
    $baseline = $adapter->capture();
    $expect(array_column($baseline['styles'], 'id') === [19, 20], 'native capture selects only this theme frontend styles');
    $expect($baseline['assignments'] === [['id' => 1, 'template_style_id' => 19]], 'native capture records only assignments for selected styles');
    $expect($baseline['styles'][1]['checked_out'] === null && $baseline['styles'][1]['checked_out_time'] === null, 'native database retains original NULL values');
    $files = [file_get_contents($theme . '/templateDetails.xml'), file_get_contents($positionPath)];

    $adapter->begin();
    $db->setQuery('DELETE FROM #__template_styles WHERE id=20')->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $expect($adapter->capture() === $baseline, 'undo deletion reinserts the exact native row through by-reference insertObject');

    $adapter->begin();
    $db->setQuery("UPDATE #__template_styles SET title='G342 renamed', checked_out=943, checked_out_time='2026-11-02 20:00:00' WHERE id=20")->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $restored = $adapter->capture();
    $expect($restored === $baseline, 'undo rename restores every native style field');
    $expect($restored['styles'][1]['checked_out'] === null && $restored['styles'][1]['checked_out_time'] === null, 'undo passes nulls=true and restores both NULL fields');

    $adapter->begin();
    $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'0','G342 created','{}',NULL,NULL)")->execute();
    $adapter->restore($baseline);
    $adapter->commit();
    $expect($adapter->capture() === $baseline, 'undo creation deletes only the extra unassigned nondefault native row');
    $expect((int) $db->setQuery('SELECT COUNT(*) FROM #__template_styles WHERE id=30')->loadResult() === 1, 'native restore preserves another template row');

    $adapter->begin();
    $db->setQuery("UPDATE #__template_styles SET title='G342 transaction failure' WHERE id=20")->execute();
    $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'0','G342 rollback','{}',NULL,NULL)")->execute();
    $adapter->rollback();
    $expect($adapter->capture() === $baseline, 'actual Joomla database transaction rollback removes partial metadata changes');

    $adapter->begin();
    try {
        $db->setQuery('INSERT INTO #__menu (id,template_style_id) VALUES (2,20)')->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'revision_conflict', 'changed native menu assignments block undo');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'assignment-conflict transaction rollback preserves original metadata');

    $adapter->begin();
    try {
        $db->setQuery("INSERT INTO #__template_styles VALUES (21,'g6_fixture',0,'1','G342 protected new default','{}',NULL,NULL)")->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'protected_outline', 'undo cannot delete a newly protected default row');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'protected-default refusal preserves the original native state');

    $adapter->begin();
    try {
        $db->setQuery('DELETE FROM #__template_styles WHERE id=20')->execute();
        $db->setQuery("INSERT INTO #__template_styles VALUES (20,'another_template',0,'0','Unrelated ID reuse','{}',NULL,NULL)")->execute();
        $conflict(static fn () => $adapter->restore($baseline), 'outline_conflict', 'undo cannot overwrite an outline ID reused by another theme');
    } finally { $adapter->rollback(); }
    $expect($adapter->capture() === $baseline, 'ID-reuse refusal leaves original native rows after rollback');
    $expect([file_get_contents($theme . '/templateDetails.xml'), file_get_contents($positionPath)] === $files, 'direct native database adapter operations leave theme files unchanged');

    // Actual Joomla view levels, nested-set groups and component parameters.
    // No Access/authorise method or database query is replaced by a fixture.
    $db->setQuery('CREATE TABLE #__viewlevels (id INTEGER PRIMARY KEY, title TEXT NOT NULL, rules TEXT NOT NULL)')->execute();
    $db->setQuery('CREATE TABLE #__usergroups (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, lft INTEGER NOT NULL, rgt INTEGER NOT NULL, title TEXT NOT NULL)')->execute();
    $db->setQuery('CREATE TABLE #__user_usergroup_map (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)')->execute();
    $db->setQuery('CREATE TABLE #__extensions (extension_id INTEGER PRIMARY KEY, element TEXT NOT NULL, type TEXT NOT NULL, state INTEGER NOT NULL, enabled INTEGER NOT NULL, params TEXT NOT NULL, custom_data TEXT NOT NULL)')->execute();
    foreach ([[1, 0, 1, 8, 'Public'], [2, 1, 2, 3, 'Registered'], [3, 1, 4, 5, 'Guest'], [8, 1, 6, 7, 'Super Users']] as [$id, $parent, $left, $right, $title]) {
        $record = (object) ['id' => $id, 'parent_id' => $parent, 'lft' => $left, 'rgt' => $right, 'title' => $title];
        $db->insertObject('#__usergroups', $record);
    }
    foreach ([[1, 'Public', '[1]'], [2, 'Registered', '[2]'], [3, 'Special', '[8]'], [4, 'Guest only', '[3]']] as [$id, $title, $rules]) {
        $record = (object) compact('id', 'title', 'rules');
        $db->insertObject('#__viewlevels', $record);
    }
    $db->setQuery('INSERT INTO #__user_usergroup_map VALUES (999,8)')->execute();
    $record = (object) ['extension_id' => 1, 'element' => 'com_users', 'type' => 'component', 'state' => 0, 'enabled' => 1, 'params' => '{"guest_usergroup":3}', 'custom_data' => '{}'];
    $db->insertObject('#__extensions', $record);
    $config = new Registry(['root_user' => 999, 'caching' => 0, 'cache_handler' => 'file', 'cache_path' => JPATH_CACHE, 'lifetime' => 15, 'secret' => 'isolated-test-only']);
    Factory::$config = $config;
    // Only native application configuration is needed; there is no site request,
    // session, authentication plugin or synthetic authorisation implementation.
    Factory::$application = new class($config) extends \Joomla\Application\AbstractApplication {
        use \Joomla\CMS\Application\IdentityAware;
        protected function doExecute() {}
    };
    $actor = new User();
    $actor->id = 999;
    $actor->username = 'isolated_fixture_administrator';
    $actor->guest = 0;
    $actor->groups = [8];
    Factory::$application->loadIdentity($actor);
    $container->set(UserFactoryInterface::class, new UserFactory($db));
    $container->set(CacheControllerFactoryInterface::class, new CacheControllerFactory());
    Access::clearStatics();
    (new ReflectionProperty(ComponentHelper::class, 'components'))->setValue(null, []);
    $guestLevels = array_map('intval', Access::getAuthorisedViewLevels(0));
    sort($guestLevels);
    $expect($guestLevels === [1, 4], 'actual Joomla Access reads the configured Guest nested-set group and viewlevel rules');
    $actorGroups = array_map('intval', Access::getGroupsByUser(999));
    sort($actorGroups);
    $expect($actorGroups === [1, 8], 'the same native ACL database distinguishes the administrator group from the guest group');
    $expect(Factory::getApplication()->getIdentity() === $actor && $actor->id === 999 && !in_array(3, $guestLevels, true), 'a native administrator build identity cannot add its Special view level to guest render inputs');

    $db->setQuery('CREATE TABLE #__categories (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, published INTEGER NOT NULL, access INTEGER NOT NULL, description TEXT NOT NULL)')->execute();
    $db->setQuery("CREATE TABLE #__content (id INTEGER PRIMARY KEY, state INTEGER NOT NULL, access INTEGER NOT NULL, catid INTEGER NOT NULL, introtext TEXT NOT NULL, fulltext TEXT NOT NULL, images TEXT NOT NULL DEFAULT '{}', attribs TEXT NOT NULL DEFAULT '{}', publish_up TEXT NULL, publish_down TEXT NULL)")->execute();
    $db->setQuery('CREATE TABLE #__modules (id INTEGER PRIMARY KEY, published INTEGER NOT NULL, access INTEGER NOT NULL, client_id INTEGER NOT NULL, content TEXT NOT NULL, publish_up TEXT NULL, publish_down TEXT NULL)')->execute();
    foreach ([
        [1, 0, 1, 1, 'PUBLIC_CATEGORY_ROOT'],
        [10, 1, 1, 1, 'PUBLIC_CATEGORY_CHILD'],
        [11, 1, 1, 4, 'GUEST_CATEGORY'],
        [20, 1, 0, 1, 'UNPUBLISHED_CATEGORY'],
        [21, 20, 1, 1, 'CHILD_OF_UNPUBLISHED_CATEGORY'],
        [30, 1, 1, 2, 'PRIVATE_CATEGORY'],
        [31, 30, 1, 1, 'CHILD_OF_PRIVATE_CATEGORY'],
        [40, 999, 1, 1, 'ORPHAN_CATEGORY'],
        [50, 51, 1, 1, 'CYCLIC_CATEGORY_ONE'],
        [51, 50, 1, 1, 'CYCLIC_CATEGORY_TWO'],
        [60, 1, -2, 1, 'TRASHED_CATEGORY'],
    ] as [$id, $parent, $published, $access, $description]) {
        $record = (object) ['id' => $id, 'parent_id' => $parent, 'published' => $published, 'access' => $access, 'description' => $description];
        $db->insertObject('#__categories', $record);
    }
    $past = gmdate('Y-m-d H:i:s', time() - 86400);
    $future = gmdate('Y-m-d H:i:s', time() + 86400);
    $articles = [
        ['PUBLIC_ARTICLE_NULL', 1, 1, 10, null, null],
        ['PUBLIC_ARTICLE_ZERO', 1, 1, 10, '0000-00-00 00:00:00', '0000-00-00 00:00:00'],
        ['PUBLIC_ARTICLE_WINDOW', 1, 1, 10, $past, $future],
        ['GUEST_ARTICLE', 1, 4, 11, null, null],
        ['UNPUBLISHED_ARTICLE', 0, 1, 10, null, null],
        ['ARCHIVED_ARTICLE', 2, 1, 10, null, null],
        ['PRIVATE_ARTICLE', 1, 2, 10, null, null],
        ['SPECIAL_ARTICLE', 1, 3, 10, null, null],
        ['FUTURE_ARTICLE', 1, 1, 10, $future, null],
        ['EXPIRED_ARTICLE', 1, 1, 10, null, $past],
        ['UNPUBLISHED_PARENT_ARTICLE', 1, 1, 21, null, null],
        ['PRIVATE_PARENT_ARTICLE', 1, 1, 31, null, null],
        ['ORPHAN_ARTICLE', 1, 1, 40, null, null],
        ['CYCLIC_ARTICLE', 1, 1, 50, null, null],
        ['TRASHED_PARENT_ARTICLE', 1, 1, 60, null, null],
        ['MISSING_CATEGORY_ARTICLE', 1, 1, 999, null, null],
    ];
    foreach ($articles as $offset => [$marker, $state, $access, $catid, $publishUp, $publishDown]) {
        $record = (object) ['id' => $offset + 1, 'state' => $state, 'access' => $access, 'catid' => $catid, 'introtext' => $marker . '_INTRO', 'fulltext' => $marker . '_FULL', 'publish_up' => $publishUp, 'publish_down' => $publishDown];
        $db->insertObject('#__content', $record);
    }
    $modules = [
        ['PUBLIC_MODULE_NULL', 1, 1, 0, null, null],
        ['PUBLIC_MODULE_ZERO', 1, 1, 0, '0000-00-00 00:00:00', '0000-00-00 00:00:00'],
        ['PUBLIC_MODULE_WINDOW', 1, 1, 0, $past, $future],
        ['GUEST_MODULE', 1, 4, 0, null, null],
        ['ADMIN_MODULE', 1, 1, 1, null, null],
        ['UNPUBLISHED_MODULE', 0, 1, 0, null, null],
        ['PRIVATE_MODULE', 1, 2, 0, null, null],
        ['SPECIAL_MODULE', 1, 3, 0, null, null],
        ['FUTURE_MODULE', 1, 1, 0, $future, null],
        ['EXPIRED_MODULE', 1, 1, 0, null, $past],
    ];
    foreach ($modules as $offset => [$content, $published, $access, $client, $publishUp, $publishDown]) {
        $record = (object) ['id' => $offset + 1, 'published' => $published, 'access' => $access, 'client_id' => $client, 'content' => $content, 'publish_up' => $publishUp, 'publish_down' => $publishDown];
        $db->insertObject('#__modules', $record);
    }
    $publicSources = $adapter->renderSources();
    foreach (['PUBLIC_ARTICLE_NULL', 'PUBLIC_ARTICLE_ZERO', 'PUBLIC_ARTICLE_WINDOW', 'GUEST_ARTICLE'] as $marker) {
        $expect(in_array($marker . '_INTRO', $publicSources, true) && in_array($marker . '_FULL', $publicSources, true), 'public native article intro/fulltext is admitted: ' . $marker);
    }
    foreach (array_slice($articles, 4) as [$marker]) {
        $expect(!in_array($marker . '_INTRO', $publicSources, true) && !in_array($marker . '_FULL', $publicSources, true), 'nonpublic native article text is absent: ' . $marker);
    }
    foreach ($modules as $offset => [$marker]) { $expect(in_array($marker, $publicSources, true) === ($offset < 4), 'module ACL/publication/client/time boundary: ' . $marker); }
    foreach (['PUBLIC_CATEGORY_ROOT', 'PUBLIC_CATEGORY_CHILD', 'GUEST_CATEGORY'] as $marker) { $expect(in_array($marker, $publicSources, true), 'guest-visible native category description is admitted: ' . $marker); }
    foreach (['UNPUBLISHED_CATEGORY', 'CHILD_OF_UNPUBLISHED_CATEGORY', 'PRIVATE_CATEGORY', 'CHILD_OF_PRIVATE_CATEGORY', 'ORPHAN_CATEGORY', 'CYCLIC_CATEGORY_ONE', 'CYCLIC_CATEGORY_TWO', 'TRASHED_CATEGORY'] as $marker) { $expect(!in_array($marker, $publicSources, true), 'hidden native category description is absent: ' . $marker); }
    $expect(count($publicSources) === 15, 'offline render inputs contain exactly the guest-public article/module/category strings');
    $expect($adapter->capture() === $baseline, 'offline guest content collection does not mutate native outline/assignment metadata');

    // The two native article image fields are guest-rendered independently of
    // intro/fulltext HTML. Only these declared fields may enter public assets.
    $nativeIntro = 'images/public-intro.jpg#joomlaImage://local-images/public-intro.jpg?width=640&height=480';
    $nativeFull = 'images/public-full.jpg?revision=12#joomlaImage://local-images/public-full.jpg?width=1280&height=960';
    $ordinaryFragment = 'images/window-intro.svg?revision=7#section-two';
    $ordinaryFull = 'images/window-full.webp';
    $guestIntro = 'images/guest-intro.avif#joomlaImage://local-images/guest-intro.avif?width=320&height=240';
    $ignored = [
        'image_intro_caption' => 'images/caption-intro-not-an-asset.jpg',
        'image_fulltext_caption' => 'images/caption-full-not-an-asset.jpg',
        'image_intro_alt' => 'images/alt-intro-not-an-asset.jpg',
        'image_fulltext_alt' => 'images/alt-full-not-an-asset.jpg',
        'float_intro' => 'images/float-intro-not-an-asset.jpg',
        'float_fulltext' => 'images/float-full-not-an-asset.jpg',
        'unrelated' => 'images/arbitrary-json-not-an-asset.jpg',
    ];
    $imageValues = [
        json_encode(['image_intro' => $nativeIntro, 'image_fulltext' => $nativeFull] + $ignored + ['params' => ['image_intro' => 'images/nested-not-an-asset.jpg']], JSON_THROW_ON_ERROR),
        '{"image_intro":"images/malformed-not-an-asset.jpg","extra":',
        json_encode(['image_intro' => $ordinaryFragment, 'image_fulltext' => $ordinaryFull], JSON_THROW_ON_ERROR),
        json_encode(['image_intro' => $guestIntro, 'image_fulltext' => ['images/nonstring-not-an-asset.jpg']], JSON_THROW_ON_ERROR),
    ];
    foreach ($articles as $offset => [$marker]) {
        $images = $imageValues[$offset] ?? json_encode(['image_intro' => 'images/' . $marker . '-INTRO.jpg', 'image_fulltext' => 'images/' . $marker . '-FULL.jpg'], JSON_THROW_ON_ERROR);
        $record = (object) ['id' => $offset + 1, 'images' => $images, 'attribs' => '{"image_intro":"images/arbitrary-attribs-not-an-asset.jpg"}'];
        $db->updateObject('#__content', $record, 'id');
    }
    $imageSources = $adapter->renderSources();
    $expectedImages = [
        'images/public-intro.jpg', 'images/public-full.jpg?revision=12',
        $ordinaryFragment, $ordinaryFull, 'images/guest-intro.avif',
    ];
    foreach ($expectedImages as $image) { $expect(in_array($image, $imageSources, true), 'declared public/guest article image URI is collected: ' . $image); }
    foreach ([$nativeIntro, $nativeFull, $guestIntro] as $native) {
        $clean = HTMLHelper::cleanImageURL($native);
        $expect(in_array($clean->url, $imageSources, true) && !in_array($native, $imageSources, true), 'native Joomla image metadata cleaning matches HTMLHelper for a declared image');
    }
    $expect(in_array($ordinaryFragment, $imageSources, true), 'an ordinary query/fragment remains exact; only the native Joomla metadata fragment is removed');
    foreach ($ignored as $field => $value) { $expect(!in_array($value, $imageSources, true), 'non-image JSON field is not collected: ' . $field); }
    foreach (['images/nested-not-an-asset.jpg', 'images/nonstring-not-an-asset.jpg', 'images/arbitrary-attribs-not-an-asset.jpg'] as $value) {
        $expect(!in_array($value, $imageSources, true), 'nested/nonstring/arbitrary article JSON does not add an image source: ' . $value);
    }
    $expect(!in_array($imageValues[1], $imageSources, true) && !in_array('images/malformed-not-an-asset.jpg', $imageSources, true), 'malformed images JSON is ignored without leaking the raw JSON or a partial value');
    $expect(in_array('PUBLIC_ARTICLE_ZERO_INTRO', $imageSources, true) && in_array('PUBLIC_ARTICLE_ZERO_FULL', $imageSources, true), 'malformed image metadata preserves the public article text');
    foreach (array_slice($articles, 4) as [$marker]) {
        $expect(!in_array('images/' . $marker . '-INTRO.jpg', $imageSources, true) && !in_array('images/' . $marker . '-FULL.jpg', $imageSources, true), 'nonpublic article images remain absent: ' . $marker);
    }
    $addedImages = array_values(array_diff($imageSources, $publicSources));
    sort($addedImages);
    sort($expectedImages);
    $expect($addedImages === $expectedImages && count($imageSources) === 20, 'only the five declared guest-public image URIs are added to the exact previous content sources');
    $expect(array_values(array_diff($publicSources, $imageSources)) === [], 'collecting image metadata preserves every existing guest-public source');
    $expect($adapter->capture() === $baseline, 'image source collection leaves native outline and assignment metadata unchanged');
    echo 'PASS ' . $checks . ' real Joomla Database SQLite adapter checks on PHP ' . PHP_VERSION
        . '; observed ' . count($upstreamDeprecations) . " upstream SQLite bootstrap deprecation(s)\n";
} finally {
    if (class_exists(Access::class, false)) { Access::clearStatics(); }
    if (class_exists(ComponentHelper::class, false)) { (new ReflectionProperty(ComponentHelper::class, 'components'))->setValue(null, []); }
    Factory::$container = $oldContainer;
    Factory::$application = $oldApplication;
    Factory::$config = $oldConfig;
    $db?->disconnect();
    if (is_dir($temporary)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
    restore_error_handler();
}
