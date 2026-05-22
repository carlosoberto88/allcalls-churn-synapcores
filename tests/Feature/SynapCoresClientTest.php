<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SynapCores\Auth\ApiKeyAuth;
use App\Services\SynapCores\Auth\JwtAuth;
use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\DTO\QueryResult;
use App\Services\SynapCores\Exceptions\AuthException;
use App\Services\SynapCores\Exceptions\QueryException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for SynapCoresClient using Http::fake().
 *
 * Each test stands alone: Http::fake() is called within the test, and the client
 * is constructed directly (not resolved from the container) so credentials can be
 * swapped per scenario without touching real config values.
 */
class SynapCoresClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a client backed by ApiKeyAuth. */
    private function makeApiKeyClient(string $key = 'test-api-key'): SynapCoresClient
    {
        return new SynapCoresClient(
            auth: new ApiKeyAuth($key),
            baseUrl: 'http://synapcores.test',
            timeoutSeconds: 5,
            defaultDatabase: 'main',
        );
    }

    /** Build a client backed by JwtAuth against the given base URL. */
    private function makeJwtClient(
        string $username = 'admin',
        string $password = 'secret',
        string $baseUrl = 'http://synapcores.test',
    ): SynapCoresClient {
        // Ensure any cached token from a prior test is cleared.
        Cache::forget('synapcores.jwt.' . sha1($username));

        return new SynapCoresClient(
            auth: new JwtAuth(
                baseUrl: $baseUrl,
                username: $username,
                password: $password,
                timeoutSeconds: 5,
            ),
            baseUrl: $baseUrl,
            timeoutSeconds: 5,
            defaultDatabase: 'main',
        );
    }

    /** A valid query-layer success response shape. */
    private function querySuccessResponse(): array
    {
        return [
            'columns'          => ['one'],
            'rows'             => [[1]],
            'rows_affected'    => 0,
            'execution_time_ms' => 0.5,
        ];
    }

    /** A valid JWT login success response. */
    private function loginSuccessResponse(string $token = 'jwt-token-abc'): array
    {
        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ];
    }

    // -------------------------------------------------------------------------
    // 1. API key auth: correct header, body, URL; result DTO populated
    // -------------------------------------------------------------------------

    public function test_api_key_query_sends_correct_request_and_returns_dto(): void
    {
        Http::fake([
            'http://synapcores.test/v1/query/execute' => Http::response(
                $this->querySuccessResponse(),
                200,
            ),
        ]);

        $client = $this->makeApiKeyClient('my-key-123');
        $result = $client->query('SELECT 1', 'main');

        // DTO is correctly populated.
        $this->assertInstanceOf(QueryResult::class, $result);
        $this->assertSame(['one'], $result->columns);
        $this->assertSame([['one' => 1]], $result->rows);
        $this->assertSame(0, $result->rowsAffected);
        $this->assertSame(0.5, $result->executionTimeMs);

        // Correct URL, method, body, and Authorization header were used.
        Http::assertSent(function (\Illuminate\Http\Client\Request $req): bool {
            return $req->url() === 'http://synapcores.test/v1/query/execute'
                && $req->method() === 'POST'
                && $req->body() === json_encode([
                    'sql'          => 'SELECT 1',
                    'max_rows'     => 100,
                    'timeout_secs' => 5,
                    'parameters'   => [],
                    'database'     => 'main',
                ])
                && $req->hasHeader('Authorization', 'ApiKey my-key-123');
        });
    }

    // -------------------------------------------------------------------------
    // 2. JWT auth: first call hits /v1/auth/login; second call reuses cached token
    // -------------------------------------------------------------------------

    public function test_jwt_auth_fetches_token_on_first_call_and_caches_for_second(): void
    {
        Http::fake([
            'http://synapcores.test/v1/auth/login'     => Http::response(
                $this->loginSuccessResponse('token-xyz'),
                200,
            ),
            'http://synapcores.test/v1/query/execute' => Http::sequence()
                ->push($this->querySuccessResponse(), 200)
                ->push($this->querySuccessResponse(), 200),
        ]);

        $client = $this->makeJwtClient();

        $client->query('SELECT 1');
        $client->query('SELECT 1'); // second call — must reuse cached token

        // Login was hit exactly once.
        Http::assertSentCount(3); // 1 login + 2 queries

        Http::assertSent(function (\Illuminate\Http\Client\Request $req): bool {
            return str_ends_with($req->url(), '/v1/auth/login');
        });

        // Both query requests carried the Bearer token.
        Http::assertSent(function (\Illuminate\Http\Client\Request $req): bool {
            return str_ends_with($req->url(), '/v1/query/execute')
                && $req->hasHeader('Authorization', 'Bearer token-xyz');
        });
    }

    // -------------------------------------------------------------------------
    // 3. 401 retry: faked sequence [401, login, 200] → success + exactly one login
    // -------------------------------------------------------------------------

    public function test_jwt_auth_retries_once_on_401_and_succeeds(): void
    {
        Http::fake([
            'http://synapcores.test/v1/auth/login'    => Http::response(
                $this->loginSuccessResponse('fresh-token'),
                200,
            ),
            'http://synapcores.test/v1/query/execute' => Http::sequence()
                ->push(['error' => 'Unauthorized', 'description' => 'Token expired'], 401)
                ->push($this->querySuccessResponse(), 200),
        ]);

        // Build the client first (makeJwtClient calls Cache::forget internally),
        // then inject the stale token so the initial query uses it without hitting login.
        $client = $this->makeJwtClient();
        Cache::put('synapcores.jwt.' . sha1('admin'), 'stale-token', 60);

        $result = $client->query('SELECT 1');

        $this->assertInstanceOf(QueryResult::class, $result);

        // Sequence: 1 initial query (401) + 1 login + 1 retry query = 3 requests total.
        Http::assertSentCount(3);

        // Login was triggered exactly once (after the 401).
        Http::assertSent(function (\Illuminate\Http\Client\Request $req): bool {
            return str_ends_with($req->url(), '/v1/auth/login')
                && $req->data() === ['username' => 'admin', 'password' => 'secret'];
        });

        // The retry carried the fresh token.
        $queryRequests = collect(Http::recorded())
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/v1/query/execute'))
            ->values();

        $this->assertCount(2, $queryRequests);
        $this->assertSame('Bearer stale-token', $queryRequests[0][0]->header('Authorization')[0]);
        $this->assertSame('Bearer fresh-token', $queryRequests[1][0]->header('Authorization')[0]);
    }

    // -------------------------------------------------------------------------
    // 4. Query error: 400 with nested error shape → QueryException with context
    // -------------------------------------------------------------------------

    public function test_query_error_shape_throws_query_exception_with_code_and_request_id(): void
    {
        Http::fake([
            'http://synapcores.test/v1/query/execute' => Http::response(
                [
                    'error' => [
                        'code'    => 'query_error',
                        'message' => 'Operation timeout',
                    ],
                    'meta' => [
                        'request_id' => 'req-abc-123',
                    ],
                ],
                400,
            ),
        ]);

        $client = $this->makeApiKeyClient();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Operation timeout');

        try {
            $client->query('SELECT * FROM missing_table', 'main');
        } catch (QueryException $e) {
            $this->assertSame('query_error', $e->getErrorCode());
            $this->assertSame('req-abc-123', $e->getRequestId());
            $this->assertSame(400, $e->getHttpStatus());
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // 5. Persistent 401 after retry → AuthException bubbles
    // -------------------------------------------------------------------------

    public function test_persistent_401_after_retry_throws_auth_exception(): void
    {
        Http::fake([
            'http://synapcores.test/v1/auth/login'    => Http::response(
                $this->loginSuccessResponse('new-token'),
                200,
            ),
            'http://synapcores.test/v1/query/execute' => Http::sequence()
                ->push(['error' => 'Unauthorized', 'description' => 'Token expired'], 401)
                ->push(['error' => 'Unauthorized', 'description' => 'Still rejected'], 401),
        ]);

        $client = $this->makeJwtClient();
        Cache::put('synapcores.jwt.' . sha1('admin'), 'old-token', 60);

        $this->expectException(AuthException::class);
        $client->query('SELECT 1');
    }

    // -------------------------------------------------------------------------
    // 6. Container binding resolves to SynapCoresClientInterface
    // -------------------------------------------------------------------------

    public function test_container_binding_resolves_interface(): void
    {
        // Override config so the provider does not throw for missing credentials.
        config(['synapcores.api_key' => 'container-test-key']);

        $client = $this->app->make(SynapCoresClientInterface::class);

        $this->assertInstanceOf(SynapCoresClient::class, $client);
    }
}
