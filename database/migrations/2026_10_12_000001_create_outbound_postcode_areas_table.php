<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_postcode_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('outbound_postcode', 8);
            $table->string('area_code', 16);
            $table->string('area_full_name', 255);
            $table->unsignedInteger('sort_order');
            $table->boolean('active')->default(true);
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('outbound_postcode');
            $table->unique('sort_order');
            $table->index(['area_code', 'active']);
            $table->index('active');
        });

        MigrationHelper::setUuidPrimaryKeyDefault('outbound_postcode_areas');

        Schema::create('outbound_postcode_area_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('outbound_postcode_area_id')
                ->nullable()
                ->constrained('outbound_postcode_areas')
                ->nullOnDelete();
            $table->string('action', 32);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index('action');
            $table->index('outbound_postcode_area_id');
        });

        MigrationHelper::setUuidPrimaryKeyDefault('outbound_postcode_area_audits');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_postcode_area_audits');
        Schema::dropIfExists('outbound_postcode_areas');
    }
};
