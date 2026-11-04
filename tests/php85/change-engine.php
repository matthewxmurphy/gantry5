<?php
/**
 * Shared change-engine regressions using native Gantry containers and YAML I/O.
 * php tests/php85/change-engine.php /path/to/lib_gantry5/vendor/autoload.php [source-root]
 * No CMS site is bootstrapped. Every source/cache/history file belongs to a
 * temporary fixture; the contention check uses a second real PHP process.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Api\ApiException;
use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Changes\ThemeState;
use Gantry\Component\Config\Config;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use RocketTheme\Toolbox\File\YamlFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Use actual PHP 8.5 CLI and the installed Gantry Composer autoloader.');
}
$source = $argv[2] ?? dirname(__DIR__, 2);
$loader = require $argv[1];
$loader->addPsr4('Gantry\\', $source . '/src/classes/Gantry', true);
define('_JEXEC', 1);
define('GANTRY_DEBUGGER', false);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});

/** Capture paths and exact bytes, including directories and non-YAML sources. */
function fixtureTree(string $root): array
{
    if (!is_dir($root)) { return []; }
    $tree = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isLink()) { throw new RuntimeException('Unexpected fixture link.'); }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $tree[$relative] = $file->isDir() ? null : file_get_contents($file->getPathname());
    }
    ksort($tree);
    return $tree;
}

function fixtureGantry(string $root): Gantry
{
    $locator = new UniformResourceLocator($root);
    $locator->addPath('gantry-config', '', $root . '/config');
    $locator->addPath('gantry-cache', '', $root . '/cache');
    $gantry = new Gantry();
    $gantry['global'] = new Config(['compile_yaml' => 1]);
    $gantry['locator'] = static fn () => $locator;
    $gantry['theme.name'] = 'g6_change_fixture';
    $outlines = new Outlines($gantry, ['20' => 'Owned engine fixture', '21' => 'Inherited engine fixture']);
    $gantry['outlines'] = $gantry->factory(static fn () => $outlines->copy());
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    return $gantry;
}

function nativeYaml(string $filename, array $data): void
{
    $file = YamlFile::instance($filename);
    try { $file->save($data); } finally { $file->free(); }
}

// The child holds the public engine's lock, rather than guessing its filename.
if (in_array($argv[3] ?? '', ['--hold', '--crash'], true)) {
    $root = $argv[4];
    $crash = $argv[3] === '--crash';
    define('GANTRY5_ROOT', $root);
    $engine = new ChangeEngine(fixtureGantry($root));
    $result = $engine->save($argv[5], 'fixture.concurrent', static function () use ($root, $crash): array {
        if ($crash) { nativeYaml($root . '/config/20/styles.yaml', ['accent' => ['color-1' => '#dead00']]); }
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        stream_set_timeout(STDIN, 15);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') { throw new RuntimeException('Fixture writer was not released.'); }
        nativeYaml($root . '/config/20/styles.yaml', ['accent' => ['color-1' => '#456789']]);
        return ['writer' => 'child'];
    });
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/g6-change-engine-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GANTRY5_ROOT', $temporary);
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Failed: ' . $label); }
    ++$checks;
};
$reject = static function (callable $operation, string $errorCode, string $label) use ($expect): void {
    try { $operation(); }
    catch (ApiException $error) {
        $expect($error->getCode() === 409 && ($errorCode === '*' || $error->getErrorCode() === $errorCode), $label);
        return;
    }
    throw new RuntimeException('Failed to reject: ' . $label);
};
$process = null;
$pipes = [];
try {
    foreach (['config/default', 'config/20', 'config/21', 'cache/theme'] as $directory) { mkdir($temporary . '/' . $directory, 0700, true); }
    file_put_contents($temporary . '/config/default/styles.yaml', "accent:\n  color-1: '#112233'\n");
    file_put_contents($temporary . '/config/20/styles.yaml', "# preserve the original YAML bytes\naccent:\n  color-1: '#123456'\n");
    file_put_contents($temporary . '/config/20/layout.yaml', "version: 2\nlayout: {}\n");
    file_put_contents($temporary . '/config/20/index.yaml', "name: Owned fixture\ninherit: {}\n");
    file_put_contents($temporary . '/config/21/layout.yaml', "version: 2\nlayout: {}\n");
    file_put_contents($temporary . '/config/21/index.yaml', "name: Dependent fixture\ninherit:\n  20: [header]\n");
    file_put_contents($temporary . '/config/21/owned-marker.txt', "owned unrelated bytes\n");
    $gantry = fixtureGantry($temporary);
    $engine = new ChangeEngine($gantry);
    $initial = $engine->revision();
    $expect(preg_match('/\A[a-f0-9]{64}\z/D', $initial) === 1, 'theme revision is an opaque SHA-256 value');
    $expect($engine->revision() === $initial, 'unchanged native source produces stable revisions');
    $before = fixtureTree($temporary . '/config');
    $historyBefore = fixtureTree($temporary . '/cache');
    $journalBefore = fixtureTree($temporary . '/.gantry-changes');
    $preview = $engine->preview($initial, static fn (): array => ['styles' => ['accent' => ['color-1' => '#abcdef']]]);
    $expect($preview['preview'] === true && $preview['revision'] === $initial, 'preview returns unchanged revision and explicit dry-run marker');
    $expect($preview['result']['styles']['accent']['color-1'] === '#abcdef', 'preview returns the validated plan');
    $expect(fixtureTree($temporary . '/config') === $before, 'preview leaves exact source bytes and directories unchanged');
    $expect(fixtureTree($temporary . '/cache') === $historyBefore, 'preview creates no persistent cache paths');
    $expect(fixtureTree($temporary . '/.gantry-changes') === $journalBefore && !is_dir($temporary . '/.gantry-changes'), 'preview creates no persistent history or generation');
    $expect($engine->revision() === $initial, 'preview does not advance the theme revision');
    $reject(static fn () => $engine->preview(str_repeat('0', 64), static fn (): array => []), 'revision_conflict', 'stale preview is rejected');
    foreach ([false, true] as $throwAfterWrite) {
        try {
            $engine->preview($initial, static function () use ($temporary, $throwAfterWrite): array {
                nativeYaml($temporary . '/config/21/index.yaml', ['name' => 'impure preview must not survive']);
                unlink($temporary . '/config/20/layout.yaml');
                if ($throwAfterWrite) { throw new LogicException('Owned impure preview failure'); }
                return ['impure' => true];
            });
            throw new RuntimeException('Impure preview was admitted.');
        } catch (Throwable $error) {
            $expect($error instanceof ApiException || $error instanceof LogicException, 'impure planner is rejected whether it returns or throws');
        }
        $expect(fixtureTree($temporary . '/config') === $before && $engine->revision() === $initial, 'impure preview recovers exact bytes and original revision');
        $expect(fixtureTree($temporary . '/.gantry-changes') === $journalBefore, 'impure preview leaves no undo records or generation');
    }

    $saved = $engine->save($initial, 'fixture.save', static function () use ($temporary): array {
        nativeYaml($temporary . '/config/20/styles.yaml', ['accent' => ['color-1' => '#abcdef']]);
        nativeYaml($temporary . '/config/20/index.yaml', ['name' => 'Changed fixture', 'inherit' => []]);
        nativeYaml($temporary . '/config/21/index.yaml', ['name' => 'Changed dependent', 'inherit' => ['20' => ['footer']]]);
        mkdir($temporary . '/config/20/page', 0700);
        nativeYaml($temporary . '/config/20/page/body.yaml', ['class' => 'owned-change']);
        return ['saved' => 'native'];
    });
    $expect($saved['result'] === ['saved' => 'native'], 'save returns native writer result');
    $expect(preg_match('/\A[a-f0-9]{64}\z/D', $saved['change_id']) === 1, 'save returns opaque undo identity');
    $expect($saved['revision'] !== $initial && $engine->revision() === $saved['revision'], 'successful save advances revision and immediate read agrees');
    $expect(is_file($temporary . '/config/20/page/body.yaml'), 'native multi-file change is persisted');
    $expect(file_get_contents($temporary . '/config/21/owned-marker.txt') === "owned unrelated bytes\n", 'successful save preserves unrelated native files');
    $reject(static fn () => $engine->save($initial, 'fixture.stale', static fn (): array => []), 'revision_conflict', 'stale save is rejected');
    $reject(static fn () => $engine->undo($initial, $saved['change_id']), 'revision_conflict', 'stale undo is rejected');
    $undo = $engine->undo($saved['revision'], $saved['change_id']);
    $expect($undo['result']['undone'] === $saved['change_id'], 'undo names the reverted change');
    $expect(preg_match('/\A[a-f0-9]{64}\z/D', $undo['change_id']) === 1 && $undo['change_id'] !== $saved['change_id'], 'undo itself has a distinct change identity');
    $expect(fixtureTree($temporary . '/config') === $before, 'undo restores exact prior bytes and removes only owned created paths');
    $expect($engine->revision() === $undo['revision'], 'undo response and following read revisions agree');
    $expect($undo['revision'] !== $initial && $undo['revision'] !== $saved['revision'], 'undo has a new generation despite restoring identical source bytes');
    $reject(static fn () => $engine->undo($undo['revision'], $saved['change_id']), 'revision_conflict', 'current-revision repeated undo is refused instead of causing an ABA replay');
    $reject(static fn () => $engine->undo($saved['revision'], $saved['change_id']), 'revision_conflict', 'undo cannot use a pre-undo stale revision');
    $reject(static fn () => $engine->undo($engine->revision(), str_repeat('f', 64)), '*', 'unknown undo identity is refused');
    $expect(fixtureTree($temporary . '/config') === $before, 'refused undo leaves original source unchanged');

    $priorFailureRevision = $engine->revision();
    try {
        $engine->save($priorFailureRevision, 'fixture.failure', static function () use ($temporary): array {
            nativeYaml($temporary . '/config/20/styles.yaml', ['accent' => ['color-1' => '#ff0000']]);
            nativeYaml($temporary . '/config/21/index.yaml', ['name' => 'Partial dependent', 'inherit' => []]);
            unlink($temporary . '/config/20/layout.yaml');
            mkdir($temporary . '/config/20/page', 0700);
            nativeYaml($temporary . '/config/20/page/body.yaml', ['class' => 'must-not-survive']);
            throw new LogicException('Owned injected native write failure');
        });
        throw new RuntimeException('Injected writer failure was swallowed.');
    } catch (Throwable $error) {
        $cause = $error;
        while ($cause && $cause->getMessage() !== 'Owned injected native write failure') { $cause = $cause->getPrevious(); }
        $expect($cause !== null, 'failed save retains its native failure cause');
    }
    $expect(fixtureTree($temporary . '/config') === $before, 'failed save recovers source, dependent index, deleted file and created directories');
    $expect($engine->revision() === $priorFailureRevision, 'failed save leaves revision unchanged');

    $styles = $temporary . '/config/20/styles.yaml';
    $originalBytes = file_get_contents($styles);
    $originalMtime = filemtime($styles);
    $sameLength = str_replace('#123456', '#654321', $originalBytes);
    file_put_contents($styles, $sameLength);
    touch($styles, $originalMtime);
    clearstatcache();
    $expect(strlen($sameLength) === strlen($originalBytes) && filemtime($styles) === $originalMtime, 'same-second fixture retains exact size and mtime');
    $expect($engine->revision() !== $priorFailureRevision, 'content revision detects equal-size/equal-mtime source changes');
    $parentRevision = $engine->revision();
    nativeYaml($temporary . '/config/default/styles.yaml', ['accent' => ['color-1' => '#332211']]);
    $expect($engine->revision() !== $parentRevision, 'theme revision includes base-outline changes');
    $siblingRevision = $engine->revision();
    file_put_contents($temporary . '/config/21/owned-marker.txt', "owned changed bytes!!\n");
    $expect($engine->revision() !== $siblingRevision, 'theme revision includes another outline and non-YAML source bytes');

    $concurrentRevision = $engine->revision();
    $process = proc_open([PHP_BINARY, __FILE__, $argv[1], $source, '--hold', $temporary, $concurrentRevision], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $expect(is_resource($process), 'independent PHP writer starts');
    stream_set_timeout($pipes[1], 15);
    $ready = fgets($pipes[1]);
    $expect($ready === "READY\n", 'independent engine writer holds its actual public lock');
    $reject(static fn () => $engine->save($concurrentRevision, 'fixture.competing', static fn (): array => []), 'write_in_progress', 'second process cannot enter a concurrent write');
    fwrite($pipes[0], "COMMIT\n");
    fclose($pipes[0]);
    unset($pipes[0]);
    $childResult = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    $childErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
    $childStatus = proc_close($process); $process = null;
    $expect($childStatus === 0 && $childErrors === '', 'independent native writer exits without diagnostics');
    $expect($childResult['result'] === ['writer' => 'child'] && $childResult['revision'] === $engine->revision(), 'independent commit is visible to the original engine');
    $reject(static fn () => $engine->save($concurrentRevision, 'fixture.lost', static fn (): array => []), 'revision_conflict', 'revision reread after contention prevents a lost update');
    $parentSave = $engine->save($engine->revision(), 'fixture.reacquire', static fn (): array => ['acquired' => true]);
    $expect($parentSave['result']['acquired'] === true, 'lock releases after successful independent commit');
    $expect($parentSave['revision'] !== $childResult['revision'], 'no-op save advances generation to prevent revision replay');

    $journal = $temporary . '/.gantry-changes';
    $historyPath = $journal . '/' . $parentSave['change_id'] . '.php';
    $historyBytes = file_get_contents($historyPath);
    $expect(str_starts_with($historyBytes, "<?php exit; ?>\n"), 'private history starts with a PHP execution guard');
    $expect((fileperms($journal) & 0777) === 0700 && (fileperms($historyPath) & 0777) === 0600, 'native journal directory and records have private permissions');
    file_put_contents($historyPath, "<?php exit; ?>\n{not-valid-json");
    $corruptBefore = fixtureTree($temporary . '/config');
    $reject(static fn () => $engine->undo($parentSave['revision'], $parentSave['change_id']), '*', 'corrupt guarded history is refused');
    $expect(fixtureTree($temporary . '/config') === $corruptBefore, 'corrupt history cannot mutate native source');
    file_put_contents($historyPath, $historyBytes);

    $pendingPath = $journal . '/pending.php';
    file_put_contents($pendingPath, "<?php exit; ?>\n{\"fixture\":\"interrupted\"}");
    foreach ([
        static fn () => $engine->revision(),
        static fn () => $engine->preview($parentSave['revision'], static fn (): array => []),
        static fn () => $engine->save($parentSave['revision'], 'fixture.interrupted', static fn (): array => []),
        static fn () => $engine->undo($parentSave['revision'], $parentSave['change_id']),
    ] as $operation) {
        try { $operation(); throw new RuntimeException('Interrupted operation was admitted.'); }
        catch (ApiException $error) {
            $expect($error->getCode() === 503 && $error->getErrorCode() === 'recovery_required', 'interrupted pending journal blocks further operations');
        }
    }
    $expect(fixtureTree($temporary . '/config') === $corruptBefore, 'interrupted-state refusal preserves native source');
    unlink($pendingPath);

    rename($journal, $journal . '-owned');
    symlink($journal . '-owned', $journal);
    try {
        $reject(static fn () => (new ChangeEngine($gantry))->revision(), '*', 'linked journal root is refused');
    } finally {
        unlink($journal);
        rename($journal . '-owned', $journal);
    }
    symlink($temporary . '/config/20/styles.yaml', $temporary . '/config/21/linked.yaml');
    try {
        $reject(static fn () => $engine->revision(), '*', 'linked native configuration descendant is refused');
    } finally { unlink($temporary . '/config/21/linked.yaml'); }
    mkdir($temporary . '/linked-root', 0700);
    symlink($temporary . '/config', $temporary . '/linked-root/config');
    try {
        $reject(static fn () => (new ChangeEngine(fixtureGantry($temporary . '/linked-root')))->revision(), '*', 'linked native configuration root is refused');
    } finally {
        unlink($temporary . '/linked-root/config');
        (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    }
    $expect($engine->revision() === $parentSave['revision'], 'refused recovery and linked-path operations leave current revision intact');

    $atomicTarget = $temporary . '/owned-atomic-destination';
    mkdir($atomicTarget, 0700);
    try {
        ThemeState::atomic($atomicTarget, 'owned bytes cannot replace a directory');
        throw new RuntimeException('Expected authentic rename failure.');
    } catch (Throwable $error) {
        $expect($error instanceof ErrorException || $error instanceof ApiException, 'native atomic activation failure is reported');
    }
    $expect(glob($temporary . '/.g6-write-*') === [], 'failed atomic activation removes every staged temporary file');
    $expect(is_dir($atomicTarget), 'failed atomic activation preserves the original destination');

    // A clock correction must not cause retention to evict the active undo record.
    // This also exercises more than one retention window of real journal writes.
    $burst = null;
    for ($i = 0; $i < 24; ++$i) {
        $burst = $engine->save($engine->revision(), 'fixture.retention', static function () use ($temporary, $journal, $i): array {
            foreach (glob($journal . '/*.php') as $path) {
                if (preg_match('/\A[a-f0-9]{64}\.php\z/D', basename($path))) { touch($path, time() + 60); }
            }
            nativeYaml($temporary . '/config/20/styles.yaml', ['accent' => ['color-1' => sprintf('#%06x', $i + 1)]]);
            return ['retention' => $i];
        });
    }
    $expect(is_file($journal . '/' . $burst['change_id'] . '.php'), 'retention always keeps the active latest undo record despite mtime skew');
    $history = $engine->history();
    $latest = array_values(array_filter($history['items'], static fn (array $item): bool => $item['id'] === $burst['change_id']));
    $expect(count($latest) === 1 && $latest[0]['can_undo'], 'retained history advertises the current latest change as undoable');
    $expect(count($history['items']) <= 20, 'retention bounds stored change records');
    $retentionUndo = $engine->undo($burst['revision'], $burst['change_id']);
    $expect($retentionUndo['result']['undone'] === $burst['change_id'], 'latest retained change remains genuinely undoable');

    // PHP parses an entire file before executing exit. A source filename or
    // metadata string containing an opening tag must remain JSON data after the
    // guard, rather than becoming another PHP block during compilation.
    $tagFilename = $temporary . '/config/20/owned-<?php invalid.txt';
    file_put_contents($tagFilename, "owned exact bytes behind a tag-bearing filename\n");
    $tagBefore = fixtureTree($temporary . '/config');
    $tagChange = $engine->save($engine->revision(), 'fixture.guarded_json', static function () use ($temporary): array {
        nativeYaml($temporary . '/config/20/styles.yaml', ['accent' => ['color-1' => '#cafebe']]);
        return ['guarded' => true];
    });
    $tagJournal = $journal . '/' . $tagChange['change_id'] . '.php';
    $tagBytes = file_get_contents($tagJournal);
    $expect(str_starts_with($tagBytes, "<?php exit; ?>\n"), 'tag-bearing snapshots preserve the exact PHP execution guard');
    $tagRecord = json_decode(substr($tagBytes, strlen("<?php exit; ?>\n")), true, 128, JSON_THROW_ON_ERROR);
    $expect(isset($tagRecord['before_state']['files']['config/20/owned-<?php invalid.txt']), 'guard-safe JSON decodes to the exact original tag-bearing filename');
    $process = proc_open([PHP_BINARY, $tagJournal], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not execute the owned guarded journal fixture.'); }
    fclose($pipes[0]); unset($pipes[0]);
    $tagOutput = stream_get_contents($pipes[1]);
    $tagErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
    $tagStatus = proc_close($process); $process = null;
    $expect($tagStatus === 0 && $tagOutput === '' && $tagErrors === '', 'actual PHP execution of the guarded journal emits no bytes or parse errors');
    $engine->undo($tagChange['revision'], $tagChange['change_id']);
    $expect(fixtureTree($temporary . '/config') === $tagBefore, 'undo preserves exact original filenames and bytes after guard-safe JSON encoding');

    // SIGKILL bypasses PHP finally/destructors, so this is an actual interrupted
    // native write rather than a fabricated pending-record-only simulation.
    $crashRevision = $engine->revision();
    $process = proc_open([PHP_BINARY, __FILE__, $argv[1], $source, '--crash', $temporary, $crashRevision], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $expect(is_resource($process), 'interruption fixture starts an independent native writer');
    stream_set_timeout($pipes[1], 15);
    $expect(fgets($pipes[1]) === "READY\n", 'interruption fixture reaches a real partial source write');
    $expect(proc_terminate($process, 9), 'native writer is forcibly interrupted without cleanup');
    foreach ($pipes as $pipe) { fclose($pipe); }
    $pipes = [];
    proc_close($process); $process = null;
    clearstatcache();
    $expect(is_file($journal . '/pending.php') && str_contains(file_get_contents($styles), '#dead00'), 'interrupted native write leaves a durable pending snapshot and partial state');
    foreach ([
        static fn () => (new ChangeEngine($gantry))->revision(),
        static fn () => (new ChangeEngine($gantry))->save($crashRevision, 'fixture.after_crash', static fn (): array => []),
    ] as $operation) {
        try { $operation(); throw new RuntimeException('Interrupted native writer was silently accepted.'); }
        catch (ApiException $error) {
            $expect($error->getCode() === 503 && $error->getErrorCode() === 'recovery_required', 'fresh engine refuses interrupted native state until operator recovery');
        }
    }
    echo 'PASS ' . $checks . ' shared change-engine native regression checks on PHP ' . PHP_VERSION . "\n";
} finally {
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_dir($temporary)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
    restore_error_handler();
}
