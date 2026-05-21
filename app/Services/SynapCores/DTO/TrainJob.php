<?php

declare(strict_types=1);

namespace App\Services\SynapCores\DTO;

/**
 * Represents an AutoML training job returned by /v1/automl/train or /v1/automl/jobs/{id}.
 */
final readonly class TrainJob
{
    /**
     * @param  string      $id       Job identifier used to poll /v1/automl/jobs/{id}.
     * @param  string      $status   Training status string (e.g. "pending", "running", "completed", "failed").
     * @param  string|null $modelId  Populated once training completes; null while the job is in progress.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $modelId,
    ) {}

    /**
     * Build from the raw JSON-decoded response body.
     *
     * @param  array<string,mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            id: (string) ($body['id'] ?? $body['job_id'] ?? ''),
            status: (string) ($body['status'] ?? 'unknown'),
            modelId: isset($body['model_id']) ? (string) $body['model_id'] : null,
        );
    }
}
