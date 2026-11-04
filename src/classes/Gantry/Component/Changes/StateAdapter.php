<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Changes;

/** Native CMS adapters declare exact authoritative files and metadata/transaction behavior. */
abstract class StateAdapter
{
    public function refresh(): void {}
    public function assigned(string $id): bool { return false; }
    public function files(): array { return []; }
    public function capture(): array { return []; }
    public function restore(array $metadata): void {}
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    /** Native public-content HTML for offline image preparation; never fetched on page loads. */
    public function renderSources(): array { return []; }
}
