<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Auth;

use App\Services\SynapCores\Exceptions\AuthException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Authenticates requests using a short-lived JWT obtained from /v1/auth/login.
 *
 * Token lifecycle:
 *   - Login request: POST /v1/auth/login {username, password}  (NOT {email, password})
 *   - Response: {access_token, token_type:"Bearer", expires_in:3600}
 *   - Token is cached under key synapcores.jwt.<sha1(username)> for (expires_in - 60) seconds
 *     to give a 60-second safety margin before actual expiry.
 *   - On 401 the client calls invalidate() then retries; if login itself fails, AuthException
 *     is thrown immediately.
 */
final class JwtAuth implements AuthStrategy
{
    private readonly string $cacheKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeoutSeconds,
    ) {
        $this->cacheKey = 'synapcores.jwt.' . sha1($username);
    }

    public function applyTo(PendingRequest $request): PendingRequest
    {
        $token = $this->resolveToken();

        return $request->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function invalidate(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Return the cached token, or fetch a fresh one via /v1/auth/login.
     *
     * @throws AuthException When the login request fails or returns an error shape.
     */
    private function resolveToken(): string
    {
        $cached = Cache::get($this->cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndCacheToken();
    }

    /**
     * POST /v1/auth/login and cache the resulting token.
     *
     * @throws AuthException
     */
    private function fetchAndCacheToken(): string
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post('/v1/auth/login', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

        if ($response->failed()) {
            $body = $response->json() ?? [];
            $message = $this->extractAuthErrorMessage($body, $response->status());
            throw new AuthException(
                message: $message,
                httpStatus: $response->status(),
                rawPayload: $response->body(),
            );
        }

        $body = $response->json();

        if (! isset($body['access_token']) || ! is_string($body['access_token'])) {
            throw new AuthException(
                message: 'Login response missing access_token field.',
                httpStatus: $response->status(),
                rawPayload: $response->body(),
            );
        }

        $token = $body['access_token'];
        $expiresIn = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        $ttl = max(1, $expiresIn - 60); // 60-second safety margin

        Cache::put($this->cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Extract a human-readable error message from the auth-layer error shape.
     *
     * Auth layer wire shape: {"error": "<short>", "description": "<long>"}
     *
     * @param  array<string,mixed> $body
     */
    private function extractAuthErrorMessage(array $body, int $status): string
    {
        if (isset($body['error']) && is_string($body['error'])) {
            $desc = isset($body['description']) ? ': ' . $body['description'] : '';
            return $body['error'] . $desc;
        }

        return "Login failed with HTTP {$status}.";
    }
}
