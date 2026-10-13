<?php

namespace Tests\Concerns;

use App\Models\OutboundPostcodeArea;

trait SeedsServiceAreas
{
    protected function seedServiceAreas(): void
    {
        OutboundPostcodeArea::factory()->create([
            'outbound_postcode' => 'CF14',
            'area_code' => 'E',
            'area_full_name' => 'East',
            'sort_order' => 1,
            'active' => true,
        ]);

        OutboundPostcodeArea::factory()->create([
            'outbound_postcode' => 'LL57',
            'area_code' => 'NW',
            'area_full_name' => 'North West',
            'sort_order' => 2,
            'active' => true,
        ]);

        OutboundPostcodeArea::factory()->inactive()->create([
            'outbound_postcode' => 'ZZ99',
            'area_code' => 'X',
            'area_full_name' => 'Inactive Area',
            'sort_order' => 3,
        ]);
    }
}
