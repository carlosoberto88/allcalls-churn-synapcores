<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChurnRiskService
{
    /**
     * Return the top at-risk members by joining loyalty_members with the most
     * recent risk_predictions row per member. No SynapCores call — reads local
     * SQLite only.
     *
     * Each item is a plain object with:
     *   member_id, tier, tenure_months, last_purchase_days_ago,
     *   support_tickets_30d, probability, predicted_at, model_id
     *
     * @return Collection<int, \stdClass>
     */
    public function topAtRisk(int $limit = 50, float $minProbability = 0.5): Collection
    {
        $latest = DB::table('risk_predictions')
            ->select('loyalty_member_id', DB::raw('MAX(predicted_at) AS latest_predicted_at'))
            ->groupBy('loyalty_member_id');

        /** @var Collection<int, \stdClass> */
        return DB::table('loyalty_members AS lm')
            ->joinSub($latest, 'lp', static function ($join): void {
                $join->on('lm.id', '=', 'lp.loyalty_member_id');
            })
            ->join('risk_predictions AS rp', static function ($join): void {
                $join->on('rp.loyalty_member_id', '=', 'lp.loyalty_member_id')
                     ->on('rp.predicted_at', '=', 'lp.latest_predicted_at');
            })
            ->select([
                'lm.member_id',
                'lm.tier',
                'lm.tenure_months',
                'lm.last_purchase_days_ago',
                'lm.support_tickets_30d',
                'rp.probability',
                'rp.predicted_at',
                'rp.model_id',
            ])
            ->where('rp.probability', '>=', $minProbability)
            ->orderByDesc('rp.probability')
            ->limit($limit)
            ->get();
    }

    /**
     * Return aggregate summary stats drawn from local SQLite tables.
     *
     * @return array{
     *     total_active: int,
     *     total_predicted: int,
     *     high_risk_count: int,
     *     medium_risk_count: int,
     *     latest_model_id: string|null,
     *     latest_predicted_at: Carbon|null
     * }
     */
    public function summary(): array
    {
        $totalActive = DB::table('loyalty_members')->where('churned', false)->count();

        $predicted = DB::table('risk_predictions')
            ->select([
                DB::raw('COUNT(DISTINCT loyalty_member_id) AS total_predicted'),
                DB::raw('SUM(CASE WHEN probability >= 0.7 THEN 1 ELSE 0 END) AS high_risk_count'),
                DB::raw('SUM(CASE WHEN probability >= 0.5 AND probability < 0.7 THEN 1 ELSE 0 END) AS medium_risk_count'),
            ])
            ->first();

        $latestRow = DB::table('risk_predictions')
            ->select(['model_id', 'predicted_at'])
            ->orderByDesc('predicted_at')
            ->first();

        return [
            'total_active'       => $totalActive,
            'total_predicted'    => (int) ($predicted->total_predicted ?? 0),
            'high_risk_count'    => (int) ($predicted->high_risk_count ?? 0),
            'medium_risk_count'  => (int) ($predicted->medium_risk_count ?? 0),
            'latest_model_id'    => $latestRow?->model_id,
            'latest_predicted_at' => $latestRow !== null
                ? Carbon::parse($latestRow->predicted_at)
                : null,
        ];
    }
}
