<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoyaltyMember;
use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\Exceptions\QueryException;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PushDatasetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'synapcores:push-dataset
                            {--name=loyalty_members_v1 : Name to register the dataset under}
                            {--limit= : Cap the number of rows to upload (defaults to all rows)}';

    /**
     * @var string
     */
    protected $description = 'Serialise LoyaltyMember rows and push them to SynapCores as a dataset.';

    /**
     * Feature columns to include in the dataset.
     * Deliberately excludes id, member_id, created_at, updated_at.
     *
     * @var list<array{name: string, type: string}>
     */
    private const SCHEMA = [
        ['name' => 'tenure_months',        'type' => 'int'],
        ['name' => 'points_balance',        'type' => 'int'],
        ['name' => 'last_purchase_days_ago','type' => 'int'],
        ['name' => 'support_tickets_30d',   'type' => 'int'],
        ['name' => 'email_open_rate',       'type' => 'float'],
        ['name' => 'avg_monthly_spend',     'type' => 'float'],
        ['name' => 'tier',                  'type' => 'string'],
        ['name' => 'churned',               'type' => 'bool'],
    ];

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

        $name  = (string) $this->option('name');
        $limit = $this->option('limit');

        $query = LoyaltyMember::query();

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, LoyaltyMember> $members */
        $members = $query->get();

        if ($members->isEmpty()) {
            $this->error('No LoyaltyMember rows found. Run db:seed first.');
            return self::FAILURE;
        }

        $this->line("Uploading {$members->count()} rows as dataset \"{$name}\"…");

        $rows   = $this->serialiseRows($members);
        $schema = self::SCHEMA;

        try {
            $datasetId = $this->client->createDataset($name, $rows, $schema);
        } catch (QueryException $e) {
            $this->error(sprintf(
                '[%s] %s (request_id: %s)',
                $e->getErrorCode() ?: get_class($e),
                $e->getMessage(),
                $e->getRequestId() ?? 'n/a',
            ));
            return self::FAILURE;
        } catch (SynapCoresException $e) {
            $this->error(sprintf('[%s] %s', get_class($e), $e->getMessage()));
            return self::FAILURE;
        }

        Storage::put('synapcores/dataset_id.txt', $datasetId);

        $this->info("Dataset created. ID: {$datasetId}");
        $this->line("Saved to storage/app/synapcores/dataset_id.txt");

        return self::SUCCESS;
    }

    /**
     * Convert a collection of LoyaltyMember models into plain arrays for the API,
     * keeping only the columns declared in SCHEMA.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LoyaltyMember> $members
     * @return list<array<string, mixed>>
     */
    private function serialiseRows(\Illuminate\Database\Eloquent\Collection $members): array
    {
        $columnNames = array_column(self::SCHEMA, 'name');

        return $members->map(
            fn (LoyaltyMember $m) => array_intersect_key(
                $m->toArray(),
                array_flip($columnNames),
            )
        )->values()->all();
    }
}
