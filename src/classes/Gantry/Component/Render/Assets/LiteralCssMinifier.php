<?php
/**
 * Literal-only use of the maintained minifier: our public-source policy owns I/O.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render\Assets;
final class LiteralCssMinifier extends \MatthiasMullie\Minify\CSS
{
    protected function canImportFile(mixed $path): bool { return false; }
}
