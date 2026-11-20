<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/jobs/completed lists a job when updated_at (the server-recorded completion time) OR
 * the client-claimed delivered_at / cancelled_at for its status is within the last 24 hours.
 *
 * Postgres can only bitmap-OR those three ranges if each has its own index; with any one missing
 * the whole predicate is a sequential scan of delivery_jobs (600k rows: ~3ms with all three,
 * ~105ms otherwise). The (status, delivered_at) and (status, cancelled_at) indexes added by the
 * previous migration serve neither the updated_at branch nor, usefully, the other two, so they are
 * replaced by three single-column indexes. Every step is guarded so the migration can be re-run.
 */
return new class extends Migration
{
    private const array OLD = [
        'delivery_jobs_status_delivered_at_index' => ['status', 'delivered_at'],
        'delivery_jobs_status_cancelled_at_index' => ['status', 'cancelled_at'],
    ];

    private const array NEW = [
        'delivery_jobs_updated_at_index' => 'updated_at',
        'delivery_jobs_delivered_at_index' => 'delivered_at',
        'delivery_jobs_cancelled_at_index' => 'cancelled_at',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            foreach (array_keys(self::OLD) as $name) {
                if (Schema::hasIndex('delivery_jobs', $name)) {
                    $table->dropIndex($name);
                }
            }

            foreach (self::NEW as $name => $column) {
                if (! Schema::hasIndex('delivery_jobs', $name)) {
                    $table->index($column, $name);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            foreach (array_keys(self::NEW) as $name) {
                if (Schema::hasIndex('delivery_jobs', $name)) {
                    $table->dropIndex($name);
                }
            }

            foreach (self::OLD as $name => $columns) {
                if (! Schema::hasIndex('delivery_jobs', $name)) {
                    $table->index($columns, $name);
                }
            }
        });
    }
};
