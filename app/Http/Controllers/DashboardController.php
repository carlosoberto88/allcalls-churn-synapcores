<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ChurnRiskService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Render the churn-risk dashboard.
     *
     * Query-string parameters:
     *   - limit     integer 1–500 (default 50)
     *   - threshold numeric 0–1   (default 0.5)
     */
    public function __invoke(Request $request, ChurnRiskService $service): View
    {
        $validated = $request->validate([
            'limit'     => 'integer|min:1|max:500',
            'threshold' => 'numeric|min:0|max:1',
        ]);

        $limit     = (int) ($validated['limit'] ?? 50);
        $threshold = (float) ($validated['threshold'] ?? 0.5);

        $summary = $service->summary();
        $members = $service->topAtRisk(limit: $limit, minProbability: $threshold);

        return view('dashboard', compact('summary', 'members', 'limit', 'threshold'));
    }
}
