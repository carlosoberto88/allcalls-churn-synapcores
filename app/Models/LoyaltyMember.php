<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LoyaltyMember extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'tenure_months',
        'points_balance',
        'last_purchase_days_ago',
        'support_tickets_30d',
        'email_open_rate',
        'avg_monthly_spend',
        'tier',
        'churned',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'churned'               => 'bool',
        'email_open_rate'       => 'decimal:3',
        'avg_monthly_spend'     => 'decimal:2',
        'tenure_months'         => 'int',
        'points_balance'        => 'int',
        'last_purchase_days_ago' => 'int',
        'support_tickets_30d'   => 'int',
    ];

    /**
     * Scope to members who have not churned.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('churned', false);
    }

    /**
     * Scope to members showing early churn signals:
     * no purchase in over 90 days and 2+ support tickets in the last 30 days.
     */
    public function scopeAtRisk(Builder $query): Builder
    {
        return $query
            ->where('last_purchase_days_ago', '>', 90)
            ->where('support_tickets_30d', '>=', 2);
    }
}
