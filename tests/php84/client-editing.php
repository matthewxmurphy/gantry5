<?php
/** Real YAML/layout/filesystem checks; no CMS or user mutation. Matthew C. Murphy, 2026. MIT. */
use Gantry\Component\ClientEditing\PolicyStore;
use Symfony\Component\Yaml\Yaml;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80600 || !isset($argv[1])) {
    throw new RuntimeException('Use PHP 8.4/8.5 CLI with a real installed YAML autoloader.');
}
require $argv[1];
$source = dirname(__DIR__, 2) . '/src/classes/';
foreach (['Gantry/Component/Layout/Version/Format2.php', 'Gantry/Component/Layout/LayoutReader.php', 'Gantry/Component/ClientEditing/PolicyStore.php'] as $file) {
    require_once $source . $file;
}
$root = sys_get_temp_dir() . '/gantry-client-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/config'); mkdir($root . '/config/default'); mkdir($root . '/config/12');
$checks = 0;
$check = static function ($condition, $label) use (&$checks) {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$denied = static function (callable $action, $code, $label) use ($check) {
    try { $action(); } catch (RuntimeException $error) { $check($error->getCode() === $code, $label); return; }
    $check(false, $label);
};
$scope = ['cms' => 'fixture', 'theme' => 'fixture', 'definition' => str_repeat('a', 64)];
$raw = ['version' => 2, 'preset' => ['name' => 'default'],
    'layout' => ['/main/' => [['client-text-1001', 'custom-2001']]],
    'structure' => ['main' => ['type' => 'section', 'attributes' => ['class' => 'keep-section']]],
    'content' => ['client-text-1001' => ['title' => 'Approved plain text', 'attributes' => ['text' => 'Original text', 'class' => 'keep-particle']],
        'custom-2001' => ['attributes' => ['html' => '<b>UNAPPROVED_PRIVATE_CONTENT</b>']]]];
$layout = $root . '/config/default/layout.yaml';
$policy = $root . '/config/client-editing.yaml';
try {
    file_put_contents($layout, Yaml::dump($raw, 20, 2));
    file_put_contents($root . '/config/12/layout.yaml', Yaml::dump($raw, 20, 2));
    file_put_contents($root . '/outside.yaml', 'outside: preserve');
    $store = new PolicyStore($root . '/config', $scope, ['default', '12'], true);
    $check($store->state()['enabled'] === false && !file_exists($policy), 'absent policy is off and remains absent');
    $before = file_get_contents($layout);
    $candidates = $store->candidates();
    $check(count($candidates['choices']) === 2 && isset($candidates['choices']['12:client-text-1001']), 'actual layout reader finds safe instances including numeric outline IDs');
    $check(file_get_contents($layout) === $before && !file_exists($root . '/config/default/index.yaml'), 'candidate reading never initializes a layout or index');
    $selected = ['default:client-text-1001'];
    $denied(static function () use ($store, $candidates, $selected) {
        $store->savePolicy($store->policyRevision(), $candidates['revision'], true, $selected, false);
    }, 400, 'enabling policy requires explicit administrator confirmation');
    $store->savePolicy($store->policyRevision(), $candidates['revision'], true, $selected, true);
    $state = $store->state();
    $check($state['enabled'] && count($store->approved()) === 1, 'persistent policy exposes only explicitly approved instances');
    $check((fileperms($policy) & 0777) === 0600, 'policy is saved privately outside cache');
    $denied(static function () use ($store, $state) {
        $store->saveText($state['revision'], str_repeat('0', 64), 'default', 'client-text-1001', 'stale');
    }, 409, 'stale whole-layout revision is refused');
    $denied(static function () use ($store, $before) {
        $store->saveText(str_repeat('0', 64), hash('sha256', $before), 'default', 'client-text-1001', 'stale');
    }, 409, 'stale policy revision is refused');
    $denied(static function () use ($store, $state, $before) {
        $store->saveText($state['revision'], hash('sha256', $before), '12', 'client-text-1001', 'other');
    }, 403, 'unapproved outline instance is refused');
    $denied(static function () use ($store, $state, $before) {
        $store->saveText($state['revision'], hash('sha256', $before), 'default', 'client-text-1001', str_repeat('x', 4001));
    }, 400, 'oversized plain text is refused');
    $check(file_get_contents($layout) === $before, 'all rejected text writes leave exact layout bytes unchanged');
    $text = '<script>literal</script> [fixture_shortcode] {{ literal.twig }}';
    $store->saveText($state['revision'], hash('sha256', $before), 'default', 'client-text-1001', $text);
    $expected = $raw; $expected['content']['client-text-1001']['attributes']['text'] = $text;
    $check(Yaml::parse(file_get_contents($layout)) === $expected, 'actual patch changes only one approved text value in complete parsed layout');
    $check(file_get_contents($root . '/config/12/layout.yaml') === $before && file_get_contents($root . '/outside.yaml') === 'outside: preserve', 'unapproved outline and outside bytes are preserved');
    $newDefinition = $scope; $newDefinition['definition'] = str_repeat('b', 64);
    $updated = new PolicyStore($root . '/config', $newDefinition, ['default', '12'], true);
    $denied(static function () use ($updated) { $updated->state(); }, 400, 'changed renderer definition invalidates previous approvals');
    $policyBytes = file_get_contents($policy);
    file_put_contents($policy, '');
    $denied(static function () use ($store) { $store->state(); }, 400, 'existing empty policy fails closed');
    file_put_contents($policy, 'bad: [unterminated');
    $denied(static function () use ($store) { $store->state(); }, 400, 'malformed existing policy fails closed');
    $store->savePolicy($store->policyRevision(), $store->candidates()['revision'], false, [], true);
    $check($store->state()['enabled'] === false, 'explicit settings-administrator replacement recovers malformed policy without enabling');
    file_put_contents($policy, $policyBytes);
    $blocked = new PolicyStore($root . '/config', $scope, ['default', '12'], false);
    $check($blocked->candidates()['choices'] === [], 'renderer or blueprint override yields no eligible instances');
    $changed = $raw; $changed['structure']['main']['inherit'] = ['outline' => 'other', 'include' => ['children']];
    file_put_contents($layout, Yaml::dump($changed, 20, 2));
    $check(!isset($store->candidates()['choices']['default:client-text-1001']), 'inherited ancestor excludes its text instance');
    file_put_contents($layout, $before);
    unlink($root . '/config/12/layout.yaml');
    symlink($root . '/outside.yaml', $root . '/config/12/layout.yaml');
    $denied(static function () use ($store) { $store->candidates(); }, 400, 'linked layout is refused');
    $check(file_get_contents($root . '/outside.yaml') === 'outside: preserve', 'linked outside file remains unchanged');
    echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'checks' => $checks]) . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
