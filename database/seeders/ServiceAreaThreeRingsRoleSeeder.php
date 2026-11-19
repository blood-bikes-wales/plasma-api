<?php

namespace Database\Seeders;

use App\Models\ServiceArea;
use Illuminate\Database\Seeder;

/**
 * Links each service area to the Three Rings role that marks a rider as
 * covering it. Run after OutboundPostcodeAreaSeeder, which creates the areas.
 */
class ServiceAreaThreeRingsRoleSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    public const array ROLES = [
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

    public function run(): void
    {
        foreach (self::ROLES as $code => $role) {
            ServiceArea::query()
                ->where('code', $code)
                ->update(['three_rings_role' => $role]);
        }
    }
}
