<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_recurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('job_type')->default('standard');
            $table->boolean('is_bereavement')->default(false);
            $table->foreignUuid('sender_party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('content_type_id')
                ->nullable()
                ->constrained('job_content_types')
                ->nullOnDelete();
            $table->text('content_label_snapshot');
            $table->foreignUuid('collection_place_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('delivery_place_id')->constrained('places')->restrictOnDelete();
            $table->text('collection_notes')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('timezone')->default('Europe/London');
            $table->time('time_of_day');
            $table->string('frequency');
            $table->json('by_weekday')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('horizon_days')->default(14);
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('milk_recurrence_details', function (Blueprint $table) {
            $table->foreignUuid('job_recurrence_id')
                ->primary()
                ->constrained('job_recurrences')
                ->cascadeOnDelete();
            $table->decimal('milk_litres', 5, 2);
            $table->foreignUuid('mother_party_id')->constrained('parties')->restrictOnDelete();
        });

        Schema::create('job_recurrence_service_areas', function (Blueprint $table) {
            $table->foreignUuid('job_recurrence_id')
                ->constrained('job_recurrences')
                ->cascadeOnDelete();
            $table->foreignUuid('service_area_id')
                ->constrained('service_areas')
                ->restrictOnDelete();
            $table->primary(['job_recurrence_id', 'service_area_id'], 'job_recurrence_service_areas_pk');
        });

        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->foreignUuid('recurrence_id')
                ->nullable()
                ->constrained('job_recurrences')
                ->nullOnDelete();
            $table->date('occurrence_date')->nullable();
            $table->unique(['recurrence_id', 'occurrence_date'], 'delivery_requests_recurrence_occurrence_unique');
        });

        MigrationHelper::setUuidPrimaryKeyDefault('job_recurrences');
    }

    public function down(): void
    {
        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->dropUnique('delivery_requests_recurrence_occurrence_unique');
            $table->dropConstrainedForeignId('recurrence_id');
            $table->dropColumn('occurrence_date');
        });

        Schema::dropIfExists('job_recurrence_service_areas');
        Schema::dropIfExists('milk_recurrence_details');
        Schema::dropIfExists('job_recurrences');
    }
};
