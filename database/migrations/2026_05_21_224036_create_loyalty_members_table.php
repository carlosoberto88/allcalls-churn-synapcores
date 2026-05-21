<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_members', function (Blueprint $table) {
            $table->id();
            $table->string('member_id')->unique()->index();
            $table->unsignedInteger('tenure_months');
            $table->unsignedInteger('points_balance');
            $table->unsignedInteger('last_purchase_days_ago');
            $table->unsignedTinyInteger('support_tickets_30d');
            $table->decimal('email_open_rate', 4, 3);
            $table->decimal('avg_monthly_spend', 10, 2);
            $table->string('tier')->index();
            $table->boolean('churned')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_members');
    }
};
