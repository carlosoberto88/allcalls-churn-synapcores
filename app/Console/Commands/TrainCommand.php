<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\DTO\TrainJob;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TrainCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'synapcores:train
                            {--dataset= : Dataset ID to train on (falls back to storage/app/synapcores/dataset_id.txt)}
                            {--target=churned : Target column name}
                            {--task=binary_classification : AutoML task type}
                            {--poll-seconds=5 : Seconds to wait between job status polls}
                            {--timeout=600 : Maximum seconds to wait for training to complete}';

    /**
     * @var string
     */
    protected $description = 'Start an AutoML training job on SynapCores and poll until it completes.';

    /** @var list<string> Terminal statuses returned by the SynapCores jobs endpoint. */
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'cancelled', 'completed'];

    public function __construct(private readonly SynapCoresClientInterface $client)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Storage::makeDirectory('synapcores');

        $datasetId = $this->resolveDatasetId();

        if ($datasetId === null) {
            $this->error(
                'No dataset ID provided. Pass --dataset=<id> or run synapcores:push-dataset first.'
            );
            return self::FAILURE;
        }

        $target      = (string) $this->option('target');
        $task        = (string) $this->option('task');
        $pollSeconds = (int) $this->option('poll-seconds');
        $timeout     = (int) $this->option('timeout');

        $this->line("Starting training job on dataset {$datasetId} (target: {$target}, task: {$task})…");

        try {
            $job = $this->client->train($datasetId, $target, $task);
        } catch (SynapCoresException $e) {
            $this->error(sprintf('[%s] %s', get_class($e), $e->getMessage()));
            return self::FAILURE;
        }

        $this->line("Job {$job->id} created — status: {$job->status}");

        $result = $this->pollUntilTerminal($job, $pollSeconds, $timeout);

        if ($result === null) {
            $this->error(
                "Training timed out after {$timeout}s. Job {$job->id} is still running. "
                . 'Check the SynapCores admin UI for status.'
            );
            return self::FAILURE;
        }

        if (in_array($result->status, ['failed', 'cancelled'], true)) {
            $this->error("Training job {$result->id} ended with status: {$result->status}");
            return self::FAILURE;
        }

        if ($result->modelId === null) {
            $this->error("Training succeeded but no model_id was returned. Check SynapCores logs.");
            return self::FAILURE;
        }

        Storage::put('synapcores/model_id.txt', $result->modelId);

        $this->info("Training complete. Model ID: {$result->modelId}");
        $this->line("Saved to storage/app/synapcores/model_id.txt");

        return self::SUCCESS;
    }

    /**
     * Resolve the dataset ID from --dataset option or the persisted text file.
     */
    private function resolveDatasetId(): ?string
    {
        $option = $this->option('dataset');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        if (Storage::exists('synapcores/dataset_id.txt')) {
            $id = trim((string) Storage::get('synapcores/dataset_id.txt'));
            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * Poll the job every $pollSeconds until a terminal status is reached or $timeout expires.
     *
     * Returns the final TrainJob on completion, or null on timeout.
     *
     * @throws SynapCoresException  Propagated from getJob() on API errors.
     */
    private function pollUntilTerminal(TrainJob $job, int $pollSeconds, int $timeout): ?TrainJob
    {
        $started = time();

        while (true) {
            if (in_array($job->status, self::TERMINAL_STATUSES, true)) {
                return $job;
            }

            $elapsed = time() - $started;

            if ($elapsed >= $timeout) {
                return null;
            }

            sleep($pollSeconds);

            $this->line(sprintf(
                '  [%ds elapsed] Job %s — status: %s',
                time() - $started,
                $job->id,
                $job->status,
            ));

            try {
                $job = $this->client->getJob($job->id);
            } catch (SynapCoresException $e) {
                $this->error(sprintf('[%s] %s', get_class($e), $e->getMessage()));
                throw $e;
            }
        }
    }
}
