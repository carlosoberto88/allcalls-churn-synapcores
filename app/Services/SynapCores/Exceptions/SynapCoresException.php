<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Exceptions;

use RuntimeException;

/**
 * Abstract base for all SynapCores SDK exceptions.
 *
 * Subclasses carry context (HTTP status, request_id, raw payload) so callers
 * can make decisions without re-parsing the original response.
 */
abstract class SynapCoresException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $requestId = null,
        private readonly ?string $rawPayload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getRawPayload(): ?string
    {
        return $this->rawPayload;
    }
}
