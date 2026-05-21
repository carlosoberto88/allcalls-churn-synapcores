<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * Authenticates requests using an API key in the Authorization header.
 *
 * Wire format: Authorization: ApiKey <key>
 *
 * Note: The SynapCores runtime requires the "ApiKey" scheme name exactly.
 * Neither "Bearer <api_key>" nor the "X-API-Key" header will be accepted,
 * despite what the OpenAPI spec documents.
 */
final class ApiKeyAuth implements AuthStrategy
{
    public function __construct(private readonly string $apiKey) {}

    public function applyTo(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders([
            'Authorization' => 'ApiKey ' . $this->apiKey,
        ]);
    }

    /** No-op: API keys do not expire. */
    public function invalidate(): void {}
}
