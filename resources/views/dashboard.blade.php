<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Churn Risk — SynapCores Dashboard</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #1a1a1a;
            background: #f5f5f5;
            padding: 24px;
        }

        h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 13px;
            color: #666;
            margin-bottom: 24px;
        }

        /* Summary cards */
        .cards {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }

        .card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 16px 20px;
            min-width: 150px;
            flex: 1;
        }

        .card-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #888;
            margin-bottom: 6px;
        }

        .card-value {
            font-size: 26px;
            font-weight: 700;
            color: #111;
        }

        .card-value.high   { color: #c0392b; }
        .card-value.medium { color: #d67e00; }
        .card-value.meta   { font-size: 14px; font-weight: 500; color: #444; word-break: break-all; }

        /* Empty state */
        .empty-state {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 32px 24px;
            text-align: center;
        }

        .empty-state p {
            color: #555;
            margin-bottom: 16px;
        }

        .empty-state pre {
            display: inline-block;
            background: #f0f0f0;
            border: 1px solid #d0d0d0;
            border-radius: 4px;
            padding: 12px 16px;
            font-size: 13px;
            text-align: left;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Table */
        .table-wrap {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid #e0e0e0;
        }

        .table-header h2 {
            font-size: 14px;
            font-weight: 600;
        }

        .table-header span {
            font-size: 12px;
            color: #888;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #888;
            border-bottom: 1px solid #e8e8e8;
            white-space: nowrap;
        }

        tbody tr:hover { background: #fafafa; }

        tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }

        .tier-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .tier-bronze   { background: #fbe9d3; color: #7a4510; }
        .tier-silver   { background: #e8e8e8; color: #444; }
        .tier-gold     { background: #fef3c7; color: #92400e; }
        .tier-platinum { background: #e0e7ff; color: #3730a3; }

        .risk-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .risk-high   { background: #fee2e2; color: #991b1b; }
        .risk-medium { background: #fef3c7; color: #92400e; }
        .risk-low    { background: #dcfce7; color: #166534; }

        .mono { font-family: "SF Mono", "Fira Code", monospace; font-size: 12px; }

        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>

<h1>Loyalty Churn Risk — SynapCores Dashboard</h1>
<p class="subtitle">
    Predictions are precomputed by <code>synapcores:predict</code> and read from local SQLite — no live ML calls at page load.
</p>

{{-- Summary cards --}}
<div class="cards">
    <div class="card">
        <div class="card-label">Total Active Members</div>
        <div class="card-value">{{ number_format($summary['total_active']) }}</div>
    </div>
    <div class="card">
        <div class="card-label">Members Predicted</div>
        <div class="card-value">{{ number_format($summary['total_predicted']) }}</div>
    </div>
    <div class="card">
        <div class="card-label">High Risk (&ge;70%)</div>
        <div class="card-value high">{{ number_format($summary['high_risk_count']) }}</div>
    </div>
    <div class="card">
        <div class="card-label">Medium Risk (50–70%)</div>
        <div class="card-value medium">{{ number_format($summary['medium_risk_count']) }}</div>
    </div>
    <div class="card" style="min-width: 200px;">
        <div class="card-label">Latest Model</div>
        <div class="card-value meta">
            @if($summary['latest_model_id'])
                {{ Str::limit($summary['latest_model_id'], 22) }}
            @else
                —
            @endif
        </div>
    </div>
    <div class="card" style="min-width: 200px;">
        <div class="card-label">Last Predicted At</div>
        <div class="card-value meta">
            @if($summary['latest_predicted_at'])
                {{ $summary['latest_predicted_at']->format('Y-m-d H:i') }} UTC
            @else
                —
            @endif
        </div>
    </div>
</div>

@if($summary['total_predicted'] === 0)
    {{-- Empty state --}}
    <div class="empty-state">
        <p>No predictions yet. Run the following commands to seed, train, and score members:</p>
        <pre>php artisan synapcores:push-dataset &amp;&amp; php artisan synapcores:train &amp;&amp; php artisan synapcores:predict</pre>
    </div>
@else
    {{-- At-risk member table --}}
    <div class="table-wrap">
        <div class="table-header">
            <h2>At-Risk Members</h2>
            <span>
                Threshold: {{ $threshold * 100 }}% &nbsp;|&nbsp;
                Showing {{ $members->count() }} of up to {{ $limit }}
            </span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Member ID</th>
                    <th>Tier</th>
                    <th>Tenure (mo)</th>
                    <th>Days Since Purchase</th>
                    <th>Support Tickets (30d)</th>
                    <th>Risk Probability</th>
                    <th>Predicted At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $row)
                    @php
                        $pct = round($row->probability * 100, 1);
                        $riskClass = $row->probability >= 0.7 ? 'risk-high'
                            : ($row->probability >= 0.5 ? 'risk-medium' : 'risk-low');
                        $tierClass = 'tier-' . strtolower($row->tier);
                    @endphp
                    <tr>
                        <td class="mono">{{ $row->member_id }}</td>
                        <td>
                            <span class="tier-badge {{ $tierClass }}">{{ $row->tier }}</span>
                        </td>
                        <td>{{ $row->tenure_months }}</td>
                        <td>{{ $row->last_purchase_days_ago }}</td>
                        <td>{{ $row->support_tickets_30d }}</td>
                        <td>
                            <span class="risk-pill {{ $riskClass }}">{{ $pct }}%</span>
                        </td>
                        <td class="mono">{{ $row->predicted_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center; padding: 24px; color:#888;">
                            No members match the current threshold ({{ $threshold * 100 }}%).
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endif

<p class="footer">
    SynapCores Loyalty Churn Predictor &mdash; read-only dashboard
</p>

</body>
</html>
