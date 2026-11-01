<?php
/**
 * Actual filesystem/YAML acceptance for private outline snapshots.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Usage: php tests/php84/upgrade-snapshot.php /path/to/installed/vendor/autoload.php
 * Uses real installed YAML dependencies and a new owned temporary directory only.
 */
use Gantry\Component\Upgrade\OutlineSnapshot;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80600
    || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.4/8.5 CLI with an installed dependency autoloader.');
}
require $argv[1];
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/Upgrade/OutlineSnapshot.php';
$root = sys_get_temp_dir() . '/gantry-upgrade-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/outline');
mkdir($root . '/outline/particles');
$identity = ['cms' => 'wordpress', 'theme' => 'g5_helium', 'outline' => 'default'];
$checks = 0;
$expect = static function ($condition, $label) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $label);
    }
    ++$checks;
};
$refused = static function (callable $action, $label) use ($expect) {
    try {
        $action();
    } catch (RuntimeException $error) {
        $expect(true, $label);
        return;
    }
    $expect(false, $label);
};
$encode = static function ($value) {
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};
try {
    $original = "accent:\n  color-1: '#123abc'\n";
    $private = "content: 'SYNTHETIC_PRIVATE_OUTLINE_VALUE'\n";
    file_put_contents($root . '/outline/styles.yaml', $original);
    file_put_contents($root . '/outline/particles/custom.yaml', $private);
    file_put_contents($root . '/outline/keep.txt', 'outside YAML snapshot');
    file_put_contents($root . '/outside.yaml', "do_not_change: true\n");
    $service = new OutlineSnapshot($root . '/outline', $identity);
    $snapshot = $service->create();
    file_put_contents($root . '/outline/added.yaml', "preserve_after_snapshot: true\n");
    $document = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
    $expect(count($document['files']) === 2, 'selected YAML files only');
    $expect(base64_decode($document['files']['particles/custom.yaml']['bytes']) === $private, 'private content preserved exactly with explicit snapshot scope');
    $preview = $service->preview($snapshot);
    $expect($preview['changes'] === 0, 'unchanged snapshot preview');
    $expect(strpos(json_encode($preview), 'SYNTHETIC_PRIVATE') === false, 'preview contains no configuration values');
    $edited = "accent:\n  color-1: '#abcdef'\n";
    file_put_contents($root . '/outline/styles.yaml', $edited);
    $refused(static function () use ($service, $snapshot, $preview) {
        $service->restore($snapshot, $preview['revision'], $preview['snapshot'], true);
    }, 'stale preview refused');
    $expect(file_get_contents($root . '/outline/styles.yaml') === $edited, 'stale refusal makes no config write');
    $preview = $service->preview($snapshot);
    $refused(static function () use ($service, $snapshot, $preview) {
        $service->restore($snapshot, $preview['revision'], $preview['snapshot'], false);
    }, 'explicit restore confirmation required');
    $refused(static function () use ($service, $snapshot, $preview) {
        $service->restore($snapshot, $preview['revision'], str_repeat('0', 64), true);
    }, 'same uploaded snapshot required');
    $result = $service->restore($snapshot, $preview['revision'], $preview['snapshot'], true);
    $expect($result['restored'] === 1 && file_get_contents($root . '/outline/styles.yaml') === $original, 'actual byte-exact rollback');
    $expect(file_get_contents($root . '/outline/particles/custom.yaml') === $private, 'particle content preserved');
    $expect(file_get_contents($root . '/outline/keep.txt') === 'outside YAML snapshot'
        && file_get_contents($root . '/outside.yaml') === "do_not_change: true\n", 'unlisted/outside files preserved');
    $expect($result['preserved'] === 1 && file_get_contents($root . '/outline/added.yaml') === "preserve_after_snapshot: true\n", 'YAML added after snapshot is preserved');
    unlink($root . '/outline/particles/custom.yaml');
    $preview = $service->preview($snapshot);
    $expect($preview['changes'] === 1 && !file_exists($root . '/outline/particles/custom.yaml'), 'missing-file preview stays read-only');
    $service->restore($snapshot, $preview['revision'], $preview['snapshot'], true);
    $expect(file_get_contents($root . '/outline/particles/custom.yaml') === $private, 'missing selected configuration file is restored');
    foreach (['../outside.yaml', '/outside.yaml', 'nested/../../outside.yaml', 'settings.php'] as $path) {
        $bad = $document;
        $bad['files'] = [$path => reset($document['files'])];
        $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'unsafe relative path refused');
    }
    foreach (['cms', 'theme', 'outline'] as $key) {
        $bad = $document;
        $bad['scope'][$key] = 'different';
        $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'cross-scope snapshot refused');
    }
    $bad = $document;
    $bad['files']['styles.yaml']['sha256'] = str_repeat('0', 64);
    $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'corrupt file digest refused');
    $bad = $document;
    $invalid = "invalid: [unterminated\n";
    $bad['files']['styles.yaml'] = ['sha256' => hash('sha256', $invalid), 'bytes' => base64_encode($invalid)];
    $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'invalid YAML refused before writes');
    $refused(static function () use ($service) { $service->preview(str_repeat('x', OutlineSnapshot::MAX_JSON_BYTES + 1)); }, 'oversized upload refused');
    $duplicate = preg_replace('/"schema": 1,/', '"schema": 1, "schema": 1,', $snapshot, 1);
    $refused(static function () use ($service, $duplicate) { $service->preview($duplicate); }, 'duplicate JSON key refused');
    symlink($root . '/outside.yaml', $root . '/outline/linked.yaml');
    $refused(static function () use ($service) { $service->create(); }, 'linked source refused');
    unlink($root . '/outline/linked.yaml');
    symlink($root, $root . '/outline/linked');
    $bad = $document;
    $bad['files'] = ['linked/outside.yaml' => reset($document['files'])];
    $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'linked restore parent refused');
    unlink($root . '/outline/linked');
    link($root . '/outside.yaml', $root . '/outline/hardlinked.yaml');
    $refused(static function () use ($service) { $service->create(); }, 'hardlinked source refused');
    unlink($root . '/outline/hardlinked.yaml');
    $bad = $document;
    $bad['files']['Styles.yaml'] = $bad['files']['styles.yaml'];
    $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'case-fold duplicate payload refused');
    $bad = $document;
    $bad['files'] = ['Styles.yaml' => $bad['files']['styles.yaml']];
    $refused(static function () use ($service, $encode, $bad) { $service->preview($encode($bad)); }, 'case-alias of current file refused');
    if (!file_exists($root . '/outline/Styles.yaml')) {
        // A case-insensitive filesystem cannot construct two distinct case-alias files.
        file_put_contents($root . '/outline/Styles.yaml', "alias_fixture: true\n");
        $refused(static function () use ($service) { $service->create(); }, 'case-ambiguous current roster refused');
        unlink($root . '/outline/Styles.yaml');
    }
    unlink($root . '/outline/styles.yaml');
    file_put_contents($root . '/outline/STYLES.YAML', "unlisted_uppercase: preserve\n");
    $refused(static function () use ($service, $snapshot) { $service->preview($snapshot); }, 'uppercase-extension alias is not overwritten as a new file');
    $expect(file_get_contents($root . '/outline/STYLES.YAML') === "unlisted_uppercase: preserve\n", 'unlisted uppercase-extension bytes preserved');
    unlink($root . '/outline/STYLES.YAML');
    file_put_contents($root . '/outline/styles.yaml', $original);
    $lock = $root . '/outline/.gantry-upgrade.lock';
    unlink($lock);
    link($root . '/outside.yaml', $lock);
    $outsideMode = fileperms($root . '/outside.yaml') & 0777;
    $preview = $service->preview($snapshot);
    $refused(static function () use ($service, $snapshot, $preview) {
        $service->restore($snapshot, $preview['revision'], $preview['snapshot'], true);
    }, 'hardlinked lock refused before open/chmod');
    clearstatcache();
    $expect(file_get_contents($root . '/outside.yaml') === "do_not_change: true\n"
        && (fileperms($root . '/outside.yaml') & 0777) === $outsideMode, 'outside hardlink bytes and permissions unchanged');
    unlink($lock);

    // A failed second filesystem commit must recover the first real changed file.
    file_put_contents($root . '/outline/particles/custom.yaml', "content: changed\n");
    file_put_contents($root . '/outline/styles.yaml', $edited);
    $failing = new class($root . '/outline', $identity) extends OutlineSnapshot {
        private $commits = 0;
        protected function commit($temporary, $target)
        {
            if (++$this->commits === 2) {
                throw new RuntimeException('Synthetic filesystem commit failure.');
            }
            parent::commit($temporary, $target);
        }
    };
    $beforeParticle = file_get_contents($root . '/outline/particles/custom.yaml');
    $preview = $failing->preview($snapshot);
    $refused(static function () use ($failing, $snapshot, $preview) {
        $failing->restore($snapshot, $preview['revision'], $preview['snapshot'], true);
    }, 'commit failure is reported');
    $expect(file_get_contents($root . '/outline/particles/custom.yaml') === $beforeParticle
        && file_get_contents($root . '/outline/styles.yaml') === $edited, 'compensation recovers actual pre-restore bytes');
    $temporary = glob($root . '/outline/{,particles/}.gantry-upgrade-*.tmp', GLOB_BRACE);
    $expect($temporary === [], 'temporary private staging files removed');
    echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
