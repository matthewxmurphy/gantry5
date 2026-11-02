<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
namespace Gantry\Framework;

use Gantry\Component\Api\ApiException;
use Gantry\Component\Changes\StateAdapter;

/** Filesystem-only standalone core. Each CMS with database state supplies its native overlay. */
class ChangeState extends StateAdapter
{
    public function __construct(Gantry $gantry) {}

    public function begin(): void
    {
        // A WordPress database adapter must ship before enabling this candidate's
        // recoverable writes there. Do not silently claim file-only DB rollback.
        if (defined('ABSPATH')) { throw new ApiException('The native WordPress change adapter is not available in this Joomla staging candidate.', 503, 'cms_adapter_unavailable'); }
    }
}
