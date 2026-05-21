<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyMember;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetentionOfferController extends Controller
{
    /**
     * Generate a retention offer for a member based on their tier and latest risk probability.
     *
     * POST /api/members/{memberId}/retention-offer
     *
     * Looks up the member by the member_id column (not the PK).
     * Returns 404 if the member does not exist.
     * No offer is persisted — generation only (a retention_offers table is out of scope).
     */
    public function store(Request $request, string $memberId): JsonResponse
    {
        $member = LoyaltyMember::where('member_id', $memberId)->first();

        if ($member === null) {
            return response()->json([
                'error'   => 'Member not found.',
                'member_id' => $memberId,
            ], 404);
        }

        $latestPrediction = DB::table('risk_predictions')
            ->where('loyalty_member_id', $member->id)
            ->orderByDesc('predicted_at')
            ->first();

        $probability = $latestPrediction?->probability ?? 0.0;

        $offer = $this->selectOffer((float) $probability, $member->tier);

        return response()->json([
            'data' => [
                'offer_code'   => $offer['code'],
                'description'  => $offer['description'],
                'bonus_points' => $offer['bonus_points'],
                'member_id'    => $member->member_id,
            ],
            'meta' => [
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Pick an offer based on churn probability and tier.
     *
     * Bands:
     *   >= 0.7  → platinum_save  (20 % bonus points + 1 free month)
     *   >= 0.5  → silver_save    (10 % bonus points)
     *   < 0.5   → thank_you      (small token offer)
     *
     * @return array{code: string, description: string, bonus_points: int}
     */
    private function selectOffer(float $probability, string $tier): array
    {
        if ($probability >= 0.7) {
            return [
                'code'         => 'platinum_save',
                'description'  => '20% bonus points credited to your account plus one free membership month — our way of saying thank you for your loyalty.',
                'bonus_points' => 2000,
            ];
        }

        if ($probability >= 0.5) {
            return [
                'code'         => 'silver_save',
                'description'  => '10% bonus points on your next qualifying purchase. Valid for 30 days.',
                'bonus_points' => 500,
            ];
        }

        return [
            'code'         => 'thank_you',
            'description'  => 'A small thank-you: 100 bonus points added to your account. We appreciate you.',
            'bonus_points' => 100,
        ];
    }
}
