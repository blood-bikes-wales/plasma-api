<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs `Bike::latestLoggedOffShift()`, which looks up the newest logged-off
 * shift per bike for the log on bike list. Without it PostgreSQL scans every
 * shift for every shift row. The schema builder cannot declare DESC columns, but
 * a btree is scanned backwards just as well.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->index(
                ['bike_id', 'logged_off_at', 'id'],
                'operational_shifts_bike_id_logged_off_at_id_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->dropIndex('operational_shifts_bike_id_logged_off_at_id_index');
        });
    }
};
