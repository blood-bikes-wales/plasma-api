<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bikes', function (Blueprint $table) {
            $table->string('area')->default('South')->after('registration');
            $table->string('status')->default('active')->after('last_recorded_mileage');
            $table->timestamp('retired_at')->nullable()->after('status');
            $table->date('purchased_at')->nullable()->after('retired_at');
        });

        DB::table('bikes')->update(['area' => 'South']);
    }

    public function down(): void
    {
        Schema::table('bikes', function (Blueprint $table) {
            $table->dropColumn(['area', 'status', 'retired_at', 'purchased_at']);
        });
    }
};
