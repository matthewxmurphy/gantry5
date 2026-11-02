<?php
/**
 * Raw OpenAPI JSON using the native JSON response lifecycle (no CMS chrome).
 * Copyright (C) 2026 Matthew X Murphy https://MatthewxMurphy.com
 * SPDX-License-Identifier: MIT
 */
namespace Gantry\Component\Api;

use Gantry\Component\Response\JsonResponse;

final class OpenApiResponse extends JsonResponse
{
    public function __construct(private readonly array $document)
    {
        parent::__construct();
    }

    public function __toString()
    {
        return json_encode($this->document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
