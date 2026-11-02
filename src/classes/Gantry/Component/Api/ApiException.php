<?php
/**
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Api;

/** An intentionally public, transport-independent API error. */
final class ApiException extends \RuntimeException
{
    public function __construct(string $message, int $status, private readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
