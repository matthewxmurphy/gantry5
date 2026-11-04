<?php
/**
 * Native Twig runtime dispatch for callbacks bound to the active theme.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Twig;

use Gantry\Component\Theme\AbstractTheme;
use Twig\Environment;

/** Keep the original theme implementation and native environment state. */
final class ThemeRuntime
{
    public function __construct(private readonly AbstractTheme $theme) {}

    public function toGrid($text)
    {
        return $this->theme->toGrid($text);
    }

    public function dateFilter(Environment $environment, $date, $format = null, $timezone = null)
    {
        return $this->theme->twig_dateFilter($environment, $date, $format, $timezone);
    }
}
