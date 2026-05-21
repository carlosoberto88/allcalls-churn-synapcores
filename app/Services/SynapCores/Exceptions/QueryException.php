<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Exceptions;

/**
 * Thrown when the SynapCores query layer returns an error.
 *
 * Mapped from wire shape:
 *   {"error": {"code": "...", "message": "..."}, "meta": {"request_id": "..."}}
 *
 * The request_id from meta (when present) is surfaced via getRequestId().
 */
final class QueryException extends SynapCoresException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = '',
        int $httpStatus = 0,
        ?string $requestId = null,
        ?string $rawPayload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $requestId, $rawPayload, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
