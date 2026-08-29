<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bikes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('registration')->unique();
            $table->unsignedInteger('last_recorded_mileage');
            $table->timestamps();
        });

        Schema::create('operational_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('rider_id');
            $table->string('rider_name');
            $table->foreignUuid('bike_id')->constrained('bikes')->restrictOnDelete();
            $table->unsignedInteger('start_mileage');
            $table->text('mileage_variance_reason')->nullable();
            $table->timestamp('logged_on_at');
            $table->foreignId('logged_on_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('logged_off_at')->nullable();
            $table->unsignedInteger('end_mileage')->nullable();
            $table->text('faults')->nullable();
            $table->foreignId('logged_off_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('mileage_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bike_id')->constrained('bikes')->restrictOnDelete();
            $table->foreignUuid('operational_shift_id')->unique()->constrained('operational_shifts')->restrictOnDelete();
            $table->unsignedInteger('mileage');
            $table->text('reason')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX operational_shifts_active_rider_unique ON operational_shifts (rider_id) WHERE logged_off_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX operational_shifts_active_bike_unique ON operational_shifts (bike_id) WHERE logged_off_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mileage_readings');
        Schema::dropIfExists('operational_shifts');
        Schema::dropIfExists('bikes');
    }
};
