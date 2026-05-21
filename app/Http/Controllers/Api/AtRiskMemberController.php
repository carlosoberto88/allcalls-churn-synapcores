<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChurnRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtRiskMemberController extends Controller
{
    /**
     * Return a JSON list of at-risk members from the local risk_predictions store.
     *
     * GET /api/members/at-risk
     *
     * Query parameters:
     *   - limit     integer 1–500 (default 50)
     *   - threshold numeric 0–1   (default 0.5)
     */
    public function index(Request $request, ChurnRiskService $service): JsonResponse
    {
        $validated = $request->validate([
            'limit'     => 'integer|min:1|max:500',
            'threshold' => 'numeric|min:0|max:1',
        ]);

        $limit     = (int) ($validated['limit'] ?? 50);
        $threshold = (float) ($validated['threshold'] ?? 0.5);

        $members = $service->topAtRisk(limit: $limit, minProbability: $threshold);
        $summary = $service->summary();

        return response()->json([
            'data' => $members->values(),
            'meta' => [
                'limit'        => $limit,
                'threshold'    => $threshold,
                'model_id'     => $summary['latest_model_id'],
                'predicted_at' => $summary['latest_predicted_at']?->toIso8601String(),
            ],
        ]);
    }
}
