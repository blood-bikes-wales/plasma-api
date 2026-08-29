<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->foreignUuid('parent_job_id')
                ->nullable()
                ->constrained('delivery_jobs')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('leg_number')->nullable();
            $table->boolean('is_relay')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_job_id');
            $table->dropColumn(['leg_number', 'is_relay']);
        });
    }
};
