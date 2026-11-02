<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render;

use Gantry\Component\Changes\ThemeState;
use Gantry\Framework\Gantry;

/** Shared derived-artifact registry. Only ChangeEngine's locked build/save paths activate it. */
final class RenderRegistry
{
    public const DIRECTORY = '.gantry6-render';
    private const GUARD = "<?php exit; ?>\n";
    private readonly string $directory;

    public function __construct(private readonly Gantry $gantry)
    {
        $theme = (string) $gantry['theme.name'];
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $theme)) { throw new \RuntimeException('Invalid render theme.', 400); }
        $this->directory = rtrim($gantry['platform']->getRenderPath(), '/') . '/' . $theme;
        self::safePath($this->directory);
    }

    public function directory(): string { return $this->directory; }

    public function activeId(): ?string
    {
        $path = $this->directory . '/current.php';
        self::safePath($path);
        if (!file_exists($path)) { return null; }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || !preg_match('/\A' . preg_quote(self::GUARD, '/') . '([a-f0-9]{64})\n\z/D', $bytes, $match)) {
            throw new \RuntimeException('The production render pointer requires verified recovery.', 503);
        }
        return $match[1];
    }

    public function current(): BundleRuntime
    {
        $id = $this->activeId();
        if ($id === null) { BundleRuntime::fail('No production render bundle exists. Compile this theme before serving.'); }
        return $this->open($id);
    }

    public function open(string $id): BundleRuntime
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) { throw new \RuntimeException('Invalid production render generation.', 503); }
        $directory = $this->directory . '/' . $id;
        $path = $directory . '/bundle.php';
        self::safePath($path);
        if (!is_file($path)) { BundleRuntime::fail('The selected production generation is incomplete.'); }
        try { $manifest = (static fn (string $path): mixed => require $path)($path); }
        catch (\Throwable $error) { BundleRuntime::fail('The production manifest could not be loaded.'); }
        if (!is_array($manifest) || ($manifest['id'] ?? null) !== $id || ($manifest['theme'] ?? null) !== (string) $this->gantry['theme.name']) {
            BundleRuntime::fail('The production manifest does not match its theme/generation.');
        }
        return new BundleRuntime($directory, $manifest);
    }

    public function prepare(string $revision, array $contentSources = []): string
    {
        return BundleRuntime::source(fn (): string => (new BundleCompiler($this->gantry, $this->directory))->prepare($revision, $contentSources));
    }

    /** One small rename makes the complete prepared generation visible. Old versions remain readable. */
    public function activate(string $id, string $revision): void
    {
        $runtime = $this->open($id);
        if (!hash_equals($runtime->revision(), $revision)) { throw new \RuntimeException('The prepared render revision is stale.', 409); }
        ThemeState::atomic($this->directory . '/current.php', self::GUARD . $id . "\n");
    }

    public static function safePath(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new \RuntimeException('Invalid production artifact path.', 409);
        }
        $cursor = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new \RuntimeException('Linked production artifacts are unsupported.', 409); }
        }
    }
}
