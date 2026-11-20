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
            // Three Rings shift type (Milk, Sepsis, Swansea Bay) captured at logon; null when unknown.
            $table->string('shift_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_shifts', function (Blueprint $table) {
            $table->dropColumn('shift_type');
        });
    }
};
