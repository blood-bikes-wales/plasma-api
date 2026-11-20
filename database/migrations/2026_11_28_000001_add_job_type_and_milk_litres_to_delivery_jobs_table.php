<?php

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
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->string('job_type')->default('standard');
            $table->decimal('milk_litres', 5, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropColumn(['job_type', 'milk_litres']);
        });
    }
};
