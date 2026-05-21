<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LoyaltyMemberSeeder extends Seeder
{
    private const TOTAL_ROWS  = 7500;
    private const CHUNK_SIZE  = 500;

    /**
     * Tier weights: bronze 50%, silver 30%, gold 15%, platinum 5%.
     *
     * @var array<string, int>
     */
    private const TIER_WEIGHTS = [
        'bronze'   => 50,
        'silver'   => 30,
        'gold'     => 15,
        'platinum' => 5,
    ];

    /**
     * Seed 7,500 loyalty members with a deterministic churn signal.
     *
     * Risk formula:
     *   risk = (last_purchase_days_ago / 365) * 0.45
     *         + (support_tickets_30d / 12)   * 0.30
     *         + (1 - email_open_rate)         * 0.15
     *         + (tier === 'bronze' ? 0.10 : 0)
     *
     * A uniform noise term in [-0.10, +0.10] is added to prevent a
     * perfectly deterministic boundary, giving the AutoML model something
     * real to learn.  Rows with (risk + noise) > 0.55 are marked churned.
     */
    public function run(): void
    {
        $now    = now()->toDateTimeString();
        $chunk  = [];
        $churned = 0;

        DB::transaction(function () use ($now, &$chunk, &$churned): void {
            for ($i = 1; $i <= self::TOTAL_ROWS; $i++) {
                $tier = $this->weightedTier();

                $tenureMonths        = random_int(1, 120);
                $pointsBalance       = random_int(0, 500000);
                $emailOpenRate       = round(mt_rand(0, 1000) / 1000, 3);
                $avgMonthlySpend     = round(mt_rand(0, 999999) / 100, 2);

                // 25% of members are "stale" — last purchase 180–730 days ago.
                $isStale = (random_int(1, 100) <= 25);
                $lastPurchaseDaysAgo = $isStale
                    ? random_int(180, 730)
                    : random_int(0, 179);

                // 15% of members have heavy support load (3–12 tickets).
                $isHeavySupport = (random_int(1, 100) <= 15);
                $supportTickets30d = $isHeavySupport
                    ? random_int(3, 12)
                    : random_int(0, 2);

                $risk = ($lastPurchaseDaysAgo / 365) * 0.45
                    + ($supportTickets30d / 12) * 0.30
                    + (1.0 - $emailOpenRate) * 0.15
                    + ($tier === 'bronze' ? 0.10 : 0.0);

                // Add noise in [-0.10, +0.10].
                $noise  = mt_rand(-100, 100) / 1000.0;
                $isChurned = ($risk + $noise) > 0.55;

                if ($isChurned) {
                    $churned++;
                }

                $chunk[] = [
                    'member_id'              => sprintf('MEM-%06d', $i),
                    'tenure_months'          => $tenureMonths,
                    'points_balance'         => $pointsBalance,
                    'last_purchase_days_ago' => $lastPurchaseDaysAgo,
                    'support_tickets_30d'    => $supportTickets30d,
                    'email_open_rate'        => $emailOpenRate,
                    'avg_monthly_spend'      => $avgMonthlySpend,
                    'tier'                   => $tier,
                    'churned'                => $isChurned ? 1 : 0,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];

                if (count($chunk) === self::CHUNK_SIZE) {
                    DB::table('loyalty_members')->insert($chunk);
                    $chunk = [];
                }
            }

            // Insert any remaining rows.
            if ($chunk !== []) {
                DB::table('loyalty_members')->insert($chunk);
            }
        });

        $rate = round(($churned / self::TOTAL_ROWS) * 100, 1);

        $this->command->info(sprintf(
            'Seeded %d loyalty members — %d churned (%.1f%%).',
            self::TOTAL_ROWS,
            $churned,
            $rate,
        ));
    }

    /**
     * Return a tier name sampled according to TIER_WEIGHTS.
     */
    private function weightedTier(): string
    {
        $roll = random_int(1, 100);
        $cumulative = 0;

        foreach (self::TIER_WEIGHTS as $tier => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $tier;
            }
        }

        // Fallback — should never be reached given weights sum to 100.
        return 'bronze';
    }
}
