<?php
/**
 * Copyright (c) 2026 Matthew X Murphy
 * https://MatthewxMurphy.com | https://builtnotbegged.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Gantry\Component\Gantry\Api\Serializer;

use Tobscure\JsonApi\AbstractSerializer;

defined('_JEXEC') or die;

/** JSON:API projection of the already validated native Gantry resource. */
final class ResourceSerializer extends AbstractSerializer
{
    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function getAttributes($model, ?array $fields = null): array
    {
        $attributes = (array) $model;
        unset($attributes['id']);

        return $fields === null ? $attributes : array_intersect_key($attributes, array_flip($fields));
    }
}
