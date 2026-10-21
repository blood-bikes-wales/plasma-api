<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id')->unique();
            $table->string('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });

        Schema::create('delivery_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->string('status');
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('sender_organisation')->nullable();
            $table->foreignUuid('collection_place_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('delivery_place_id')->constrained('places')->restrictOnDelete();
            $table->text('contents');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('operational_shift_id')
                ->nullable()
                ->constrained('operational_shifts')
                ->nullOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->boolean('contents_confirmed')->nullable();
            $table->boolean('suitably_sealed')->nullable();
            $table->string('seal_number')->nullable();
            $table->string('receipt_number')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('recipient')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('parent_job_id')->nullable();
            $table->unsignedSmallInteger('leg_number')->nullable();
            $table->timestamps();
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->foreign('parent_job_id')
                ->references('id')
                ->on('delivery_jobs')
                ->cascadeOnDelete();
        });

        Schema::create('delivery_job_service_areas', function (Blueprint $table) {
            $table->foreignUuid('delivery_job_id')
                ->constrained('delivery_jobs')
                ->cascadeOnDelete();
            $table->foreignUuid('service_area_id')
                ->constrained('service_areas')
                ->restrictOnDelete();
            $table->primary(['delivery_job_id', 'service_area_id']);
        });

        MigrationHelper::setUuidPrimaryKeyDefault('places');
        MigrationHelper::setUuidPrimaryKeyDefault('delivery_jobs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_job_service_areas');
        Schema::dropIfExists('delivery_jobs');
        Schema::dropIfExists('places');
    }
};
