<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mileage lives only on mileage_readings (kind start|end).
 * Drops duplicated odometer columns from operational_shifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mileage_readings', function (Blueprint $table) {
            $table->string('kind', 16)->default('end')->after('operational_shift_id');
        });

        // Existing rows were written at logoff as the end odometer.
        DB::table('mileage_readings')->update(['kind' => 'end']);

        // Backfill start readings from the denormalized shift columns.
        $shifts = DB::table('operational_shifts')
            ->whereNotNull('start_mileage')
            ->select([
                'id',
                'start_mileage',
                'mileage_variance_reason',
                'logged_on_at',
                'logged_on_by_user_id',
                'created_at',
                'updated_at',
            ])
            ->get();

        foreach ($shifts as $shift) {
            $exists = DB::table('mileage_readings')
                ->where('operational_shift_id', $shift->id)
                ->where('kind', 'start')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('mileage_readings')->insert([
                'id' => (string) Str::uuid(),
                'operational_shift_id' => $shift->id,
                'kind' => 'start',
                'mileage' => $shift->start_mileage,
                'reason' => $shift->mileage_variance_reason,
                'recorded_at' => $shift->logged_on_at,
                'recorded_by_user_id' => $shift->logged_on_by_user_id,
                'created_at' => $shift->created_at ?? $shift->logged_on_at,
                'updated_at' => $shift->updated_at ?? $shift->logged_on_at,
            ]);
        }

        // Prefer end_mileage_reason on end readings when present (portable across PG/SQLite).
        $endShifts = DB::table('operational_shifts')
            ->whereNotNull('end_mileage_reason')
            ->select(['id', 'end_mileage_reason'])
            ->get();

        foreach ($endShifts as $shift) {
            DB::table('mileage_readings')
                ->where('operational_shift_id', $shift->id)
                ->where('kind', 'end')
                ->update(['reason' => $shift->end_mileage_reason]);
        }

        Schema::table('mileage_readings', function (Blueprint $table) {
            $table->dropUnique(['operational_shift_id']);
            $table->unique(['operational_shift_id', 'kind']);
        });

        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'start_mileage',
                'end_mileage',
                'mileage_variance_reason',
                'end_mileage_reason',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->unsignedInteger('start_mileage')->nullable();
            $table->unsignedInteger('end_mileage')->nullable();
            $table->text('mileage_variance_reason')->nullable();
            $table->text('end_mileage_reason')->nullable();
        });

        $startReadings = DB::table('mileage_readings')->where('kind', 'start')->get();
        foreach ($startReadings as $reading) {
            DB::table('operational_shifts')
                ->where('id', $reading->operational_shift_id)
                ->update([
                    'start_mileage' => $reading->mileage,
                    'mileage_variance_reason' => $reading->reason,
                ]);
        }

        $endReadings = DB::table('mileage_readings')->where('kind', 'end')->get();
        foreach ($endReadings as $reading) {
            DB::table('operational_shifts')
                ->where('id', $reading->operational_shift_id)
                ->update([
                    'end_mileage' => $reading->mileage,
                    'end_mileage_reason' => $reading->reason,
                ]);
        }

        DB::table('mileage_readings')->where('kind', 'start')->delete();

        Schema::table('mileage_readings', function (Blueprint $table) {
            $table->dropUnique(['operational_shift_id', 'kind']);
            $table->unique(['operational_shift_id']);
            $table->dropColumn('kind');
        });
    }
};
