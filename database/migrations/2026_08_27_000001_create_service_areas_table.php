<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();
            $table->string('full_name', 255);
            $table->timestamps();
        });

        MigrationHelper::setUuidPrimaryKeyDefault('service_areas');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_areas');
    }
};
