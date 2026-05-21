<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Auth\AuthStrategy;
use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\DTO\PredictionResult;
use App\Services\SynapCores\DTO\QueryResult;
use App\Services\SynapCores\DTO\TrainJob;
use App\Services\SynapCores\Exceptions\AuthException;
use App\Services\SynapCores\Exceptions\QueryException;
use App\Services\SynapCores\Exceptions\TrainingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Laravel HTTP-client–based implementation of the SynapCores AIDB SDK.
 *
 * Auth selection (resolved by the service provider at bind time):
 *   - SYNAPCORES_API_KEY set       → ApiKeyAuth  (preferred; stable on CE)
 *   - JWT credentials set          → JwtAuth with 1-hour TTL cache and 401 auto-retry
 *   - Neither set                  → AuthException thrown on first use
 *
 * Error mapping:
 *   - Auth-layer shape  {"error":"<short>","description":"<long>"}  → AuthException
 *   - Query-layer shape {"error":{"code":"...","message":"..."}, "meta":{...}} → QueryException
 *   - AutoML errors                                                 → TrainingException
 *
 * 401 retry:
 *   A 401 on any request causes one automatic invalidate + re-auth.
 *   If the retry also returns 401, AuthException is bubbled immediately.
 *   There is intentionally no loop.
 */
final class SynapCoresClient implements SynapCoresClientInterface
{
    public function __construct(
        private readonly AuthStrategy $auth,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly string $defaultDatabase,
    ) {}

    // -------------------------------------------------------------------------
    // SynapCoresClientInterface
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function query(string $sql, ?string $database = null): QueryResult
    {
        $response = $this->send(
            method: 'post',
            path: '/v1/query/execute',
            body: [
                'sql'      => $sql,
                'database' => $database ?? $this->defaultDatabase,
            ],
        );

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $this->throwQueryError($response, $body);
        }

        return QueryResult::fromArray($body);
    }

    /**
     * {@inheritdoc}
     */
    public function createDataset(string $name, array $rows, array $schema): string
    {
        $response = $this->send(
            method: 'post',
            path: '/v1/automl/datasets',
            body: [
                'name'   => $name,
                'rows'   => $rows,
                'schema' => $schema,
            ],
        );

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $this->throwTrainingError($response, $body);
        }

        $id = $body['id'] ?? $body['dataset_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new TrainingException(
                message: 'createDataset response missing id field.',
                httpStatus: $response->status(),
                rawPayload: $response->body(),
            );
        }

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function train(
        string $datasetId,
        string $targetColumn,
        string $task = 'binary_classification',
    ): TrainJob {
        $response = $this->send(
            method: 'post',
            path: '/v1/automl/train',
            body: [
                'dataset_id'    => $datasetId,
                'target_column' => $targetColumn,
                'task'          => $task,
            ],
        );

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $this->throwTrainingError($response, $body);
        }

        return TrainJob::fromArray($body);
    }

    /**
     * {@inheritdoc}
     */
    public function getJob(string $jobId): TrainJob
    {
        $response = $this->send(
            method: 'get',
            path: '/v1/automl/jobs/' . rawurlencode($jobId),
        );

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $this->throwTrainingError($response, $body);
        }

        return TrainJob::fromArray($body);
    }

    /**
     * {@inheritdoc}
     */
    public function predict(string $modelId, array $features): PredictionResult
    {
        $response = $this->send(
            method: 'post',
            path: '/v1/automl/models/' . rawurlencode($modelId) . '/predict',
            body: ['features' => $features],
        );

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $this->throwTrainingError($response, $body);
        }

        return PredictionResult::fromArray($features, $body);
    }

    /**
     * {@inheritdoc}
     */
    public function predictBatch(string $modelId, array $featuresBatch): array
    {
        return array_map(
            fn (array $features): PredictionResult => $this->predict($modelId, $features),
            $featuresBatch,
        );
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Build a base PendingRequest with shared configuration (no auth applied yet).
     */
    private function buildRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Dispatch an authenticated request, handling 401 with a single auto-retry.
     *
     * @param  string               $method  'get', 'post', 'put', 'delete'
     * @param  string               $path    URL path relative to baseUrl
     * @param  array<string,mixed>  $body    Request body (ignored for GET)
     *
     * @throws AuthException       On persistent 401 after one retry.
     */
    private function send(string $method, string $path, array $body = []): Response
    {
        $response = $this->dispatch($method, $path, $body);

        if ($response->status() === 401) {
            // Invalidate cached credentials and retry exactly once.
            $this->auth->invalidate();
            $response = $this->dispatch($method, $path, $body);

            if ($response->status() === 401) {
                $bodyArr = $response->json() ?? [];
                $message = $this->extractAuthErrorMessage($bodyArr, 401);
                throw new AuthException(
                    message: $message,
                    httpStatus: 401,
                    rawPayload: $response->body(),
                );
            }
        }

        return $response;
    }

    /**
     * Execute one HTTP request with auth applied.
     *
     * @param  array<string,mixed> $body
     */
    private function dispatch(string $method, string $path, array $body): Response
    {
        $request = $this->auth->applyTo($this->buildRequest());

        return match ($method) {
            'get'    => $request->get($path),
            'post'   => $request->post($path, $body),
            'put'    => $request->put($path, $body),
            'delete' => $request->delete($path),
            default  => $request->post($path, $body),
        };
    }

    // -------------------------------------------------------------------------
    // Error-shape mappers
    // -------------------------------------------------------------------------

    /**
     * Throw a QueryException from a failed query-layer response.
     *
     * Query-layer error shape:
     *   {"error": {"code": "...", "message": "..."}, "meta": {"request_id": "..."}}
     *
     * Also handles the flat auth-layer shape for defence in depth.
     *
     * @param  array<string,mixed> $body
     *
     * @throws QueryException
     * @throws AuthException   If the error originates from the auth layer.
     */
    private function throwQueryError(Response $response, array $body): never
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new AuthException(
                message: $this->extractAuthErrorMessage($body, $status),
                httpStatus: $status,
                rawPayload: $response->body(),
            );
        }

        // Nested object shape: {"error": {"code": "...", "message": "..."}, "meta": {...}}
        if (isset($body['error']) && is_array($body['error'])) {
            $code      = (string) ($body['error']['code'] ?? 'unknown');
            $message   = (string) ($body['error']['message'] ?? 'Query error');
            $requestId = isset($body['meta']['request_id'])
                ? (string) $body['meta']['request_id']
                : null;

            throw new QueryException(
                message: $message,
                errorCode: $code,
                httpStatus: $status,
                requestId: $requestId,
                rawPayload: $response->body(),
            );
        }

        // Flat string shape or unrecognised — surface what we have.
        $message = isset($body['error']) && is_string($body['error'])
            ? $body['error']
            : "Query failed with HTTP {$status}.";

        throw new QueryException(
            message: $message,
            httpStatus: $status,
            rawPayload: $response->body(),
        );
    }

    /**
     * Throw a TrainingException from a failed AutoML response.
     *
     * @param  array<string,mixed> $body
     *
     * @throws TrainingException
     * @throws AuthException
     */
    private function throwTrainingError(Response $response, array $body): never
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new AuthException(
                message: $this->extractAuthErrorMessage($body, $status),
                httpStatus: $status,
                rawPayload: $response->body(),
            );
        }

        $message = match (true) {
            isset($body['error']['message']) => (string) $body['error']['message'],
            isset($body['error']) && is_string($body['error']) => $body['error'],
            default => "AutoML request failed with HTTP {$status}.",
        };

        throw new TrainingException(
            message: $message,
            httpStatus: $status,
            rawPayload: $response->body(),
        );
    }

    /**
     * Extract a human-readable message from the auth-layer flat error shape.
     *
     * Auth-layer wire shape: {"error": "<short>", "description": "<long>"}
     *
     * @param  array<string,mixed> $body
     */
    private function extractAuthErrorMessage(array $body, int $status): string
    {
        if (isset($body['error']) && is_string($body['error'])) {
            $desc = isset($body['description']) ? ': ' . $body['description'] : '';
            return $body['error'] . $desc;
        }

        return "Authentication failed with HTTP {$status}.";
    }
}
