<?php
/**
 * Keep native locations when a stylesheet's dependency cannot safely move.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Component\Render\Assets;
final class UnrelocatableAsset extends \RuntimeException {}
