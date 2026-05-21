<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SynapCores\Auth\ApiKeyAuth;
use App\Services\SynapCores\Auth\AuthStrategy;
use App\Services\SynapCores\Auth\JwtAuth;
use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\Exceptions\AuthException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the SynapCores SDK with the Laravel service container.
 *
 * Auth selection order (first match wins):
 *   1. SYNAPCORES_API_KEY is non-empty → ApiKeyAuth
 *   2. SYNAPCORES_JWT_USERNAME and SYNAPCORES_JWT_PASSWORD are both set → JwtAuth
 *   3. Neither → AuthException thrown on first use (lazy; does not crash the container)
 */
final class SynapCoresServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SynapCoresClientInterface::class,
            function (): SynapCoresClient {
                $config = config('synapcores');

                /** @var string $baseUrl */
                $baseUrl = (string) $config['base_url'];
                /** @var int $timeout */
                $timeout = (int) $config['timeout_seconds'];
                /** @var string $defaultDb */
                $defaultDb = (string) $config['default_database'];

                $auth = $this->resolveAuthStrategy($config, $baseUrl, $timeout);

                return new SynapCoresClient(
                    auth: $auth,
                    baseUrl: $baseUrl,
                    timeoutSeconds: $timeout,
                    defaultDatabase: $defaultDb,
                );
            },
        );
    }

    /**
     * Resolve the appropriate AuthStrategy from configuration.
     *
     * @param  array<string,mixed> $config
     *
     * @throws AuthException  When neither API key nor JWT credentials are configured.
     */
    private function resolveAuthStrategy(
        array $config,
        string $baseUrl,
        int $timeout,
    ): AuthStrategy {
        $apiKey      = (string) ($config['api_key'] ?? '');
        $jwtUsername = (string) ($config['jwt_username'] ?? '');
        $jwtPassword = (string) ($config['jwt_password'] ?? '');

        if ($apiKey !== '') {
            return new ApiKeyAuth($apiKey);
        }

        if ($jwtUsername !== '' && $jwtPassword !== '') {
            return new JwtAuth(
                baseUrl: $baseUrl,
                username: $jwtUsername,
                password: $jwtPassword,
                timeoutSeconds: $timeout,
            );
        }

        // Return a strategy that throws on first use rather than crashing during container boot.
        return new class implements AuthStrategy {
            public function applyTo(\Illuminate\Http\Client\PendingRequest $request): \Illuminate\Http\Client\PendingRequest
            {
                throw new AuthException(
                    message: 'No SynapCores credentials configured. Set SYNAPCORES_API_KEY or both SYNAPCORES_JWT_USERNAME and SYNAPCORES_JWT_PASSWORD in your .env file.',
                );
            }

            public function invalidate(): void {}
        };
    }
}
