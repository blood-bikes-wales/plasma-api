<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user preferences, one row per user. A user without a row simply has every
 * setting at its default, so rows are only written when someone changes something.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The unique index is also what makes the first write an atomic upsert.
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('show_completed_jobs')->default(false);
            $table->timestamps();
        });

        MigrationHelper::setUuidPrimaryKeyDefault('user_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
