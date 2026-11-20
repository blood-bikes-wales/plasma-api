<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/jobs/completed hides jobs completed more than 24 hours ago unless the user asks
 * for everything. The completion time is delivered_at (Delivered) or cancelled_at (Cancelled),
 * falling back to updated_at when that is null (a relay parent never has delivered_at).
 *
 * The query is written as one range predicate per status on the matching timestamp, so each
 * can use a (status, timestamp) btree and the planner can OR them together. A COALESCE across
 * the three columns would not be able to use these indexes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->index(['status', 'delivered_at'], 'delivery_jobs_status_delivered_at_index');
            $table->index(['status', 'cancelled_at'], 'delivery_jobs_status_cancelled_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropIndex('delivery_jobs_status_delivered_at_index');
            $table->dropIndex('delivery_jobs_status_cancelled_at_index');
        });
    }
};
