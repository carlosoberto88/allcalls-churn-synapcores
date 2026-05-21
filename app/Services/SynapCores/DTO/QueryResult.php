<?php

declare(strict_types=1);

namespace App\Services\SynapCores\DTO;

/**
 * Immutable result from a SQL query executed via /v1/query/execute.
 */
final readonly class QueryResult
{
    /**
     * @param  list<string>           $columns        Column names in result order.
     * @param  list<array<string,mixed>> $rows         Each row as an associative array keyed by column name.
     * @param  int                    $rowsAffected   For DML statements; 0 for SELECT.
     * @param  float                  $executionTimeMs Wall-clock execution time in milliseconds as reported by SynapCores.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $rowsAffected,
        public readonly float $executionTimeMs,
    ) {}

    /**
     * Build a QueryResult from the raw JSON-decoded response body.
     *
     * Expected shape (based on observed wire protocol):
     *   {
     *     "columns": ["col1", ...],
     *     "rows": [[val, ...], ...],   // parallel arrays, not keyed
     *     "rows_affected": 0,
     *     "execution_time_ms": 1.23
     *   }
     *
     * @param  array<string,mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $columns = (array) ($body['columns'] ?? []);
        $rawRows = (array) ($body['rows'] ?? []);

        // Zip each positional row array into an associative array keyed by column name.
        $rows = array_map(
            static fn (array $row): array => array_combine($columns, $row) ?: [],
            $rawRows,
        );

        return new self(
            columns: $columns,
            rows: $rows,
            rowsAffected: (int) ($body['rows_affected'] ?? 0),
            executionTimeMs: (float) ($body['execution_time_ms'] ?? 0.0),
        );
    }
}
