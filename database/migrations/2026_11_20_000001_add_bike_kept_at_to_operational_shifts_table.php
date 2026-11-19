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
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->string('bike_kept_at')->default('lockup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->dropColumn('bike_kept_at');
        });
    }
};
