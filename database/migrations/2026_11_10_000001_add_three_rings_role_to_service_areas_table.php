<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three Rings role that marks a rider as covering each service area, keyed
     * by service-area code. Kept inline so the migration stays self-contained.
     *
     * @var array<string, string>
     */
    private const array ROLES = [
        'A' => 'Rider - Aberystwyth',
        'C' => 'Rider - Carms',
        'E' => 'Rider - East',
        'M' => 'Rider - Mid',
        'NW' => 'Rider - NW',
        'PE' => 'Rider - Pembrokeshire',
        'PO' => 'Rider - Powys',
        'S' => 'Rider - Swansea Bay\\West',
        'W' => 'Rider - Wrexham',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->string('three_rings_role')->nullable()->unique();
        });

        foreach (self::ROLES as $code => $role) {
            DB::table('service_areas')
                ->where('code', $code)
                ->update(['three_rings_role' => $role]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropUnique(['three_rings_role']);
            $table->dropColumn('three_rings_role');
        });
    }
};
