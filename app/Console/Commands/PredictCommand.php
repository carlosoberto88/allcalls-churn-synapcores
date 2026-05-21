<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoyaltyMember;
use App\Services\SynapCores\Contracts\SynapCoresClientInterface;
use App\Services\SynapCores\DTO\PredictionResult;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PredictCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'synapcores:predict
                            {--model= : Model ID to use (falls back to storage/app/synapcores/model_id.txt)}
                            {--limit=50 : Maximum number of active members to score}
                            {--threshold=0.5 : Probability threshold for flagging a member as at-risk}';

    /**
     * @var string
     */
    protected $description = 'Score active members with a trained SynapCores model and persist predictions.';

    /** Chunk size for predictBatch calls. */
    private const BATCH_SIZE = 50;

    /**
     * Feature columns forwarded to the model (must match the schema used during training).
     * Excludes id, member_id, churned, created_at, updated_at.
     *
     * @var list<string>
     */
    private const FEATURE_COLUMNS = [
        'tenure_months',
        'points_balance',
        'last_purchase_days_ago',
        'support_tickets_30d',
        'email_open_rate',
        'avg_monthly_spend',
        'tier',
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

        $modelId = $this->resolveModelId();

        if ($modelId === null) {
            $this->error(
                'No model ID provided. Pass --model=<id> or run synapcores:train first.'
            );
            return self::FAILURE;
        }

        $limit     = (int) $this->option('limit');
        $threshold = (float) $this->option('threshold');

        /** @var \Illuminate\Database\Eloquent\Collection<int, LoyaltyMember> $members */
        $members = LoyaltyMember::active()->limit($limit)->get();

        if ($members->isEmpty()) {
            $this->line('No active members to score.');
            return self::SUCCESS;
        }

        $this->line("Scoring {$members->count()} active members with model {$modelId}…");

        /** @var list<array{member: LoyaltyMember, result: PredictionResult}> $scored */
        $scored = [];

        foreach ($members->chunk(self::BATCH_SIZE) as $chunk) {
            /** @var \Illuminate\Support\Collection<int, LoyaltyMember> $chunk */
            $featuresBatch = $chunk->map(
                fn (LoyaltyMember $m) => $this->extractFeatures($m)
            )->values()->all();

            try {
                $results = $this->client->predictBatch($modelId, $featuresBatch);
            } catch (SynapCoresException $e) {
                $this->error(sprintf('[%s] %s', get_class($e), $e->getMessage()));
                return self::FAILURE;
            }

            foreach ($chunk->values() as $index => $member) {
                /** @var LoyaltyMember $member */
                $scored[] = ['member' => $member, 'result' => $results[$index]];
            }
        }

        $this->persistPredictions($scored, $modelId);

        $this->printSummary($scored, $threshold);

        return self::SUCCESS;
    }

    /**
     * Resolve the model ID from --model option or the persisted text file.
     */
    private function resolveModelId(): ?string
    {
        $option = $this->option('model');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        if (Storage::exists('synapcores/model_id.txt')) {
            $id = trim((string) Storage::get('synapcores/model_id.txt'));
            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * Extract the feature vector for a single member, keyed by column name.
     *
     * @return array<string, mixed>
     */
    private function extractFeatures(LoyaltyMember $member): array
    {
        return array_intersect_key(
            $member->toArray(),
            array_flip(self::FEATURE_COLUMNS),
        );
    }

    /**
     * Upsert predictions into risk_predictions, keyed by (loyalty_member_id, model_id).
     *
     * @param  list<array{member: LoyaltyMember, result: PredictionResult}> $scored
     */
    private function persistPredictions(array $scored, string $modelId): void
    {
        $now = now()->toDateTimeString();

        $rows = array_map(static function (array $item) use ($modelId, $now): array {
            return [
                'loyalty_member_id' => $item['member']->id,
                'model_id'          => $modelId,
                'probability'       => $item['result']->probability,
                'predicted_at'      => $now,
            ];
        }, $scored);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('risk_predictions')->upsert(
                $chunk,
                uniqueBy: ['loyalty_member_id', 'model_id'],
                update:   ['probability', 'predicted_at'],
            );
        }
    }

    /**
     * Print a summary: total scored, count above threshold, top 5 by probability.
     *
     * @param  list<array{member: LoyaltyMember, result: PredictionResult}> $scored
     */
    private function printSummary(array $scored, float $threshold): void
    {
        $total     = count($scored);
        $aboveThreshold = array_filter(
            $scored,
            fn (array $item) => ($item['result']->probability ?? 0.0) >= $threshold,
        );

        $this->info("Scored: {$total} | Above threshold ({$threshold}): " . count($aboveThreshold));

        usort($scored, static function (array $a, array $b): int {
            return ($b['result']->probability ?? 0.0) <=> ($a['result']->probability ?? 0.0);
        });

        $top5 = array_slice($scored, 0, 5);

        if (empty($top5)) {
            return;
        }

        $this->line('');
        $this->line('Top 5 members by churn probability:');

        $rows = array_map(static function (array $item): array {
            return [
                $item['member']->member_id,
                number_format($item['result']->probability ?? 0.0, 4),
            ];
        }, $top5);

        $this->table(['member_id', 'probability'], $rows);
    }
}
