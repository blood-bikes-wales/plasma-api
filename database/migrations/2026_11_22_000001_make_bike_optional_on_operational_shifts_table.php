<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rider can be logged on before a bike is chosen, so the bike and start mileage
 * are only known once the bike is assigned. The partial unique index on active
 * bikes keeps working because NULLs never collide in a unique index.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->foreignUuid('bike_id')->nullable()->change();
            $table->unsignedInteger('start_mileage')->nullable()->change();
        });

        $this->restorePartialIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('operational_shifts')->whereNull('bike_id')->delete();

        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->foreignUuid('bike_id')->nullable(false)->change();
            $table->unsignedInteger('start_mileage')->nullable(false)->change();
        });

        $this->restorePartialIndexes();
    }

    /**
     * SQLite rebuilds the table to change a column and recreates indexes without
     * their WHERE clause, so put the partial unique indexes back as they were.
     */
    private function restorePartialIndexes(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS operational_shifts_active_rider_unique');
        DB::statement('DROP INDEX IF EXISTS operational_shifts_active_bike_unique');
        DB::statement('CREATE UNIQUE INDEX operational_shifts_active_rider_unique ON operational_shifts (rider_id) WHERE logged_off_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX operational_shifts_active_bike_unique ON operational_shifts (bike_id) WHERE logged_off_at IS NULL');
    }
};
