<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Joomla\CMS\WebAsset\WebAssetItem;

/** Native WAM item for a URI already resolved by the Gantry document. */
final class JoomlaResolvedAsset extends WebAssetItem
{
    public function getUri($resolvePath = true): string
    {
        // Re-running Joomla mediaPath on /media/... can produce //media/...
        // and cannot improve an already resolved immutable or native URI.
        return parent::getUri(false);
    }
}
