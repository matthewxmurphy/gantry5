<?php
/**
 * Real engine/lock/journal/files with controlled CMS/renderer fault adapters.
 * This verifies ordering and interruption, not a native SQL/compiler runtime.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

use Gantry\Component\Changes\ChangeEngine;
use Gantry\Component\Changes\StateAdapter;
use Gantry\Component\Changes\ThemeState;
use Gantry\Component\Config\Config;
use Gantry\Framework\Gantry;
use Gantry\Framework\Outlines;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || !isset($argv[1])) { throw new RuntimeException('Use actual PHP 8.5 and native Gantry dependencies.'); }
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

final class RenderProtocolState extends StateAdapter
{
    public static ?self $last = null;
    private ?string $staged = null;
    public function __construct(private readonly Gantry $gantry) { self::$last = $this; }
    private function path(): string { return $this->gantry['fixture.root'] . '/native-value'; }
    public function event(string $event): void { file_put_contents($this->gantry['fixture.root'] . '/events', $event . "\n", FILE_APPEND); }
    public function capture(): array { return ['value' => $this->staged ?? file_get_contents($this->path())]; }
    public function begin(): void { $this->event('begin'); $this->staged = file_get_contents($this->path()); }
    public function set(string $value): void { $this->staged = $value; }
    public function commit(): void
    {
        $this->event('commit');
        if ($this->gantry['fixture.mode'] === 'commit-failure') { throw new RuntimeException('Injected native commit refusal.'); }
        file_put_contents($this->path(), $this->staged);
        $this->staged = null;
    }
    public function rollback(): void { $this->event('rollback'); $this->staged = null; }
    public function restore(array $metadata): void { $this->staged = $metadata['value']; }
}
class_alias(RenderProtocolState::class, 'Gantry\\Framework\\ChangeState');

final class RenderProtocolRegistry
{
    public function __construct(private readonly Gantry $gantry) {}
    public function activeId(): ?string { return file_get_contents($this->gantry['fixture.root'] . '/active'); }
    public function prepare(string $revision, array $sources = []): string
    {
        RenderProtocolState::$last->event('prepare');
        if ($this->gantry['fixture.mode'] === 'prepare-failure') { throw new RuntimeException('Injected compiler refusal.'); }
        $id = bin2hex(random_bytes(32));
        file_put_contents($this->gantry['fixture.root'] . '/prepared/' . $id, json_encode(['revision' => $revision, 'source' => file_get_contents($this->gantry['fixture.root'] . '/config/20/styles.yaml')], JSON_THROW_ON_ERROR));
        return $id;
    }
    public function activate(string $id, string $revision): void
    {
        RenderProtocolState::$last->event('activate');
        $prepared = json_decode(file_get_contents($this->gantry['fixture.root'] . '/prepared/' . $id), true, 16, JSON_THROW_ON_ERROR);
        if ($prepared['revision'] !== $revision) { throw new RuntimeException('Stale prepared fixture.'); }
        if ($this->gantry['fixture.mode'] === 'activation-failure') { throw new RuntimeException('Injected pointer refusal.'); }
        if ($this->gantry['fixture.mode'] === 'hold') {
            fwrite(STDOUT, "COMMITTED\n"); fflush(STDOUT);
            stream_set_timeout(STDIN, 20);
            if (trim((string) fgets(STDIN)) !== 'ACTIVATE') { throw new RuntimeException('Unreleased fixture activation.'); }
        }
        ThemeState::atomic($this->gantry['fixture.root'] . '/active', $id);
    }
}

function protocolGantry(string $root, string $mode): Gantry
{
    $locator = new UniformResourceLocator($root);
    $locator->addPath('gantry-config', '', $root . '/config');
    $locator->addPath('gantry-cache', '', $root . '/cache');
    $gantry = new Gantry();
    $gantry['global'] = new Config(['compile_yaml' => true]);
    $gantry['locator'] = static fn () => $locator;
    $gantry['fixture.root'] = $root;
    $gantry['fixture.mode'] = $mode;
    $gantry['theme.name'] = 'g6_protocol_fixture';
    $gantry['theme'] = new class { public function updateCss(): array { return []; } };
    $outlines = new Outlines($gantry, ['20' => 'Owned protocol fixture']);
    $gantry['outlines'] = $gantry->factory(static fn () => $outlines->copy());
    $gantry['render.registry'] = new RenderProtocolRegistry($gantry);
    (new ReflectionProperty(Gantry::class, 'instance'))->setValue(null, $gantry);
    return $gantry;
}

function protocolWrite(string $root): array
{
    RenderProtocolState::$last->event('writer');
    file_put_contents($root . '/config/20/styles.yaml', "accent: changed\n");
    RenderProtocolState::$last->set('changed native metadata');
    return ['saved' => true];
}

if (($argv[3] ?? '') === '--hold') {
    define('GANTRY5_ROOT', $argv[4]);
    $engine = new ChangeEngine(protocolGantry($argv[4], 'hold'));
    $engine->save($engine->revision(), 'fixture.interrupted', static fn (): array => protocolWrite($argv[4]));
    exit;
}

$temporary = realpath(sys_get_temp_dir()) . '/g6-render-protocol-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
define('GANTRY5_ROOT', $temporary);
$checks = 0;
$expect = static function (bool $condition, string $name) use (&$checks): void { if (!$condition) { throw new RuntimeException('Failed render protocol: ' . $name); } ++$checks; };
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $file) { if (!$file->isDot()) { $remove($file->getPathname()); } } rmdir($path); }
    else { unlink($path); }
};
$setup = static function (string $name) use ($temporary): string {
    $root = $temporary . '/' . $name;
    foreach (['config/20', 'cache', 'prepared'] as $path) { mkdir($root . '/' . $path, 0700, true); }
    file_put_contents($root . '/config/20/styles.yaml', "# original bytes\naccent: original\n");
    file_put_contents($root . '/native-value', 'original native metadata');
    file_put_contents($root . '/active', str_repeat('a', 64));
    file_put_contents($root . '/prepared/' . str_repeat('a', 64), 'original immutable artifact');
    file_put_contents($root . '/events', '');
    return $root;
};
$process = null;
$pipes = [];
try {
    $root = $setup('success');
    $gantry = protocolGantry($root, 'normal');
    $engine = new ChangeEngine($gantry);
    $revision = $engine->revision();
    $before = file_get_contents($root . '/config/20/styles.yaml');
    $preview = $engine->preview($revision, static fn (): array => ['operation' => 'settings']);
    $expect(file_get_contents($root . '/events') === "begin\nrollback\n", 'preview starts no compiler or activation');
    $expect(file_get_contents($root . '/active') === str_repeat('a', 64) && count(glob($root . '/prepared/*')) === 1, 'preview keeps the current artifact and creates no new one');
    $expect(file_get_contents($root . '/config/20/styles.yaml') === $before && $preview['revision'] === $revision, 'preview keeps exact source and revision');
    file_put_contents($root . '/events', '');
    $saved = $engine->save($revision, 'fixture.save', static fn (): array => protocolWrite($root));
    $expect(file_get_contents($root . '/events') === "begin\nwriter\nprepare\ncommit\nactivate\n", 'prepare precedes native commit and activation follows it');
    $expect(file_get_contents($root . '/native-value') === 'changed native metadata', 'native metadata commit completes');
    $active = file_get_contents($root . '/active');
    $expect($active !== str_repeat('a', 64) && is_file($root . '/prepared/' . $active), 'only a prepared immutable artifact activates');
    $expect(is_file($root . '/prepared/' . str_repeat('a', 64)), 'previous generation remains readable');
    $expect(!is_file($root . '/.gantry-changes/pending.php'), 'success finalizes the pending intent');
    $undo = $engine->undo($saved['revision'], $saved['change_id']);
    $expect(file_get_contents($root . '/config/20/styles.yaml') === $before && file_get_contents($root . '/native-value') === 'original native metadata', 'undo restores exact source and native state');
    $expect(file_get_contents($root . '/active') !== $active && $undo['revision'] !== $revision, 'undo activates a new generation instead of replaying the old revision');
    $events = file_get_contents($root . '/events');
    $stable = $engine->revision();
    $rebuilt = $engine->rebuild($stable);
    $expect($rebuilt['revision'] === $stable && $engine->revision() === $stable, 'initial/manual rebuild preserves the source revision');
    $expect(substr(file_get_contents($root . '/events'), strlen($events)) === "prepare\nactivate\n", 'rebuild uses the shared lock with no native transaction or source writer');
    $expect(file_get_contents($root . '/active') === $rebuilt['render_generation'], 'rebuild returns its verified active generation');
    $events = file_get_contents($root . '/events');
    try { $engine->rebuild($revision); throw new LogicException('Stale rebuild admitted.'); }
    catch (\Gantry\Component\Api\ApiException $error) { $expect($error->getCode() === 409, 'stale rebuild is refused'); }
    $expect(file_get_contents($root . '/events') === $events, 'stale rebuild invokes no compiler');

    foreach (['prepare-failure', 'commit-failure', 'activation-failure'] as $mode) {
        $root = $setup($mode);
        $engine = new ChangeEngine(protocolGantry($root, $mode));
        $revision = $engine->revision();
        $before = file_get_contents($root . '/config/20/styles.yaml');
        try { $engine->save($revision, 'fixture.failure', static fn (): array => protocolWrite($root)); throw new LogicException('Injected failure admitted.'); }
        catch (RuntimeException $error) { $expect(!$error instanceof LogicException, $mode . ' does not return success'); }
        $expect(file_get_contents($root . '/active') === str_repeat('a', 64), $mode . ' keeps old frontend generation');
        if ($mode === 'activation-failure') {
            $expect(file_get_contents($root . '/config/20/styles.yaml') !== $before && file_get_contents($root . '/native-value') === 'changed native metadata', 'postcommit failure never rolls back committed native/source state');
            $expect(!str_contains(file_get_contents($root . '/events'), 'rollback') && is_file($root . '/.gantry-changes/pending.php'), 'postcommit activation failure retains durable recovery intent without rollback');
            try { $engine->preview($revision, static fn (): array => []); throw new LogicException('Recovery block bypassed.'); }
            catch (\Gantry\Component\Api\ApiException $error) { $expect($error->getCode() === 503, 'further edits are blocked after postcommit failure'); }
        } else {
            $expect(file_get_contents($root . '/config/20/styles.yaml') === $before && file_get_contents($root . '/native-value') === 'original native metadata', $mode . ' recovers exact original state');
            $expect(str_contains(file_get_contents($root . '/events'), 'rollback') && !is_file($root . '/.gantry-changes/pending.php'), $mode . ' rolls back and verifies recovery');
            $expect($engine->revision() === $revision, $mode . ' restores the original revision');
        }
    }

    $root = $setup('killed-after-commit');
    $process = proc_open([PHP_BINARY, __FILE__, $argv[1], $source, '--hold', $root], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start independent interruption fixture.'); }
    stream_set_timeout($pipes[1], 20);
    $expect(trim((string) fgets($pipes[1])) === 'COMMITTED', 'independent process reaches the postcommit/preactivation boundary');
    $status = proc_get_status($process);
    posix_kill($status['pid'], SIGKILL);
    foreach ($pipes as $pipe) { fclose($pipe); } $pipes = [];
    proc_close($process); $process = null;
    $expect(file_get_contents($root . '/native-value') === 'changed native metadata', 'forced interruption happens after durable fixture native commit');
    $expect(file_get_contents($root . '/active') === str_repeat('a', 64) && is_file($root . '/.gantry-changes/pending.php'), 'SIGKILL preserves old frontend and pending recovery state');
    $fresh = new ChangeEngine(protocolGantry($root, 'normal'));
    try { $fresh->revision(); throw new LogicException('Interrupted state admitted.'); }
    catch (\Gantry\Component\Api\ApiException $error) { $expect($error->getCode() === 503, 'a fresh engine refuses interrupted state without blind recovery'); }
    echo 'PASS change-render: ' . $checks . ' real engine/adapter-protocol/interruption checks on PHP ' . PHP_VERSION . "\n";
} finally {
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
    $remove($temporary);
}
