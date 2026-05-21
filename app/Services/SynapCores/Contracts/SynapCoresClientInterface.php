<?php

declare(strict_types=1);

namespace App\Services\SynapCores\Contracts;

use App\Services\SynapCores\DTO\PredictionResult;
use App\Services\SynapCores\DTO\QueryResult;
use App\Services\SynapCores\DTO\TrainJob;
use App\Services\SynapCores\Exceptions\AuthException;
use App\Services\SynapCores\Exceptions\QueryException;
use App\Services\SynapCores\Exceptions\TrainingException;

/**
 * Public API surface for the SynapCores AIDB SDK.
 *
 * All methods:
 *   - Throw typed subclasses of SynapCoresException on failure.
 *   - Never return null — use the typed DTO or throw.
 *   - Are synchronous from the caller's perspective.
 */
interface SynapCoresClientInterface
{
    /**
     * Execute a SQL statement against the SynapCores SQL engine.
     *
     * Sends POST /v1/query/execute with {sql, database}.
     * The database parameter defaults to the configured default_database ("main")
     * when not supplied.
     *
     * @throws QueryException  On 4xx/5xx responses from the query layer.
     * @throws AuthException   On unrecoverable 401 (after one retry).
     */
    public function query(string $sql, ?string $database = null): QueryResult;

    /**
     * Register a dataset with SynapCores AutoML.
     *
     * Sends POST /v1/automl/datasets with {name, rows, schema}.
     *
     * @param  string               $name    Human-readable dataset name.
     * @param  list<array<string,mixed>> $rows Rows to upload.
     * @param  array<string,mixed>  $schema  Column schema definition.
     * @return string               The dataset ID assigned by SynapCores.
     *
     * @throws TrainingException   On 4xx/5xx responses.
     * @throws AuthException       On unrecoverable 401.
     */
    public function createDataset(string $name, array $rows, array $schema): string;

    /**
     * Start an AutoML training job.
     *
     * Sends POST /v1/automl/train with {dataset_id, target_column, task}.
     *
     * @param  string $datasetId     The dataset ID returned by createDataset().
     * @param  string $targetColumn  The column the model should predict.
     * @param  string $task          AutoML task type; default "binary_classification".
     *
     * @throws TrainingException   On 4xx/5xx responses.
     * @throws AuthException       On unrecoverable 401.
     */
    public function train(
        string $datasetId,
        string $targetColumn,
        string $task = 'binary_classification',
    ): TrainJob;

    /**
     * Poll the status of an AutoML training job.
     *
     * Sends GET /v1/automl/jobs/{id}.
     *
     * @throws TrainingException   On 4xx/5xx responses.
     * @throws AuthException       On unrecoverable 401.
     */
    public function getJob(string $jobId): TrainJob;

    /**
     * Run a single prediction against a trained model.
     *
     * Sends POST /v1/automl/models/{id}/predict with {features}.
     *
     * @param  string               $modelId   The model ID from a completed TrainJob.
     * @param  array<string,mixed>  $features  Feature vector.
     *
     * @throws TrainingException   On 4xx/5xx responses.
     * @throws AuthException       On unrecoverable 401.
     */
    public function predict(string $modelId, array $features): PredictionResult;

    /**
     * Run predictions for a batch of feature vectors.
     *
     * Calls predict() for each item in $featuresBatch.
     *
     * @param  string                        $modelId        The model ID.
     * @param  list<array<string,mixed>>     $featuresBatch  One feature vector per member.
     * @return list<PredictionResult>
     *
     * @throws TrainingException   On 4xx/5xx responses for any item.
     * @throws AuthException       On unrecoverable 401.
     */
    public function predictBatch(string $modelId, array $featuresBatch): array;
}
