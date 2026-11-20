<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relay legs are looked up by parent (listing, cancelling, and finding the previous
 * leg before a collect or deliver). PostgreSQL does not index a foreign key column, so
 * without this every lookup scans delivery_jobs. A leg number is unique within its
 * relay; standalone jobs have NULLs, which a unique index treats as distinct.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->unique(['parent_job_id', 'leg_number'], 'delivery_jobs_parent_job_id_leg_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropUnique('delivery_jobs_parent_job_id_leg_number_unique');
        });
    }
};
