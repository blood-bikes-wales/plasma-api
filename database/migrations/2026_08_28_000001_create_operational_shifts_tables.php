<?php

use App\Support\Migration as MigrationHelper;
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
        Schema::create('riders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('external_id')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('bikes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('registration')->unique();
            $table->foreignUuid('service_area_id')->constrained('service_areas')->restrictOnDelete();
            $table->unsignedInteger('initial_mileage');
            $table->timestamp('retired_at')->nullable();
            $table->date('purchased_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operational_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rider_id')->constrained('riders')->restrictOnDelete();
            $table->foreignUuid('bike_id')->constrained('bikes')->restrictOnDelete();
            $table->unsignedInteger('start_mileage');
            $table->text('mileage_variance_reason')->nullable();
            $table->timestamp('logged_on_at');
            $table->foreignUuid('logged_on_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('logged_off_at')->nullable();
            $table->unsignedInteger('end_mileage')->nullable();
            $table->text('faults')->nullable();
            $table->foreignUuid('logged_off_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('mileage_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operational_shift_id')->unique()->constrained('operational_shifts')->restrictOnDelete();
            $table->unsignedInteger('mileage');
            $table->text('reason')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        MigrationHelper::setUuidPrimaryKeyDefault('riders');
        MigrationHelper::setUuidPrimaryKeyDefault('bikes');
        MigrationHelper::setUuidPrimaryKeyDefault('operational_shifts');
        MigrationHelper::setUuidPrimaryKeyDefault('mileage_readings');

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
        Schema::dropIfExists('riders');
    }
};
