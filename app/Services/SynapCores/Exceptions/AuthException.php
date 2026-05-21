<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Exceptions;

/**
 * Thrown when the SynapCores auth layer rejects a request.
 *
 * Mapped from wire shape: {"error": "<short>", "description": "<long>"}
 * Also thrown when a 401 persists after one auto-retry.
 */
final class AuthException extends SynapCoresException {}
