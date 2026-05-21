<?php

declare(strict_types=1);

namespace App\Services\SynapCores\DTO;

/**
 * Immutable result from a single prediction via /v1/automl/models/{id}/predict.
 */
final readonly class PredictionResult
{
    /**
     * @param  array<string,mixed> $features    The feature vector that was submitted.
     * @param  mixed               $prediction  The model's output (class label, regression value, etc.).
     * @param  float|null          $probability Confidence score for binary/multiclass classification; null for regression.
     */
    public function __construct(
        public readonly array $features,
        public readonly mixed $prediction,
        public readonly ?float $probability,
    ) {}

    /**
     * Build from the raw JSON-decoded response body.
     *
     * @param  array<string,mixed>  $features  The original features submitted, echoed back.
     * @param  array<string,mixed>  $body      Raw response body.
     */
    public static function fromArray(array $features, array $body): self
    {
        $probability = null;

        if (isset($body['probability'])) {
            $probability = (float) $body['probability'];
        } elseif (isset($body['probabilities']) && is_array($body['probabilities'])) {
            // Some binary classifiers return {"probabilities": {"0": 0.3, "1": 0.7}}
            $probability = (float) (max($body['probabilities']));
        }

        return new self(
            features: $features,
            prediction: $body['prediction'] ?? $body['result'] ?? null,
            probability: $probability,
        );
    }
}
