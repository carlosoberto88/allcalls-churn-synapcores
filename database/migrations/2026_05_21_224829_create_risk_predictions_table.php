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
        Schema::create('risk_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_member_id')->constrained('loyalty_members')->cascadeOnDelete();
            $table->string('model_id');
            $table->float('probability')->nullable();
            $table->timestamp('predicted_at');

            $table->unique(['loyalty_member_id', 'model_id']);
            $table->index('probability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_predictions');
    }
};
