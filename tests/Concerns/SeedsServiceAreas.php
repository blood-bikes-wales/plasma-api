<?php

namespace Tests\Concerns;

use App\Models\OutboundPostcodeArea;
use App\Models\ServiceArea;

trait SeedsServiceAreas
{
    protected function seedServiceAreas(): void
    {
        $east = ServiceArea::query()->create([
            'code' => 'E',
            'full_name' => 'East',
        ]);
        $northWest = ServiceArea::query()->create([
            'code' => 'NW',
            'full_name' => 'North West',
        ]);
        $inactive = ServiceArea::query()->create([
            'code' => 'X',
            'full_name' => 'Inactive Area',
        ]);

        OutboundPostcodeArea::factory()->create([
            'outbound_postcode' => 'CF14',
            'service_area_id' => $east->id,
            'sort_order' => 1,
            'active' => true,
        ]);

        OutboundPostcodeArea::factory()->create([
            'outbound_postcode' => 'LL57',
            'service_area_id' => $northWest->id,
            'sort_order' => 2,
            'active' => true,
        ]);

        OutboundPostcodeArea::factory()->inactive()->create([
            'outbound_postcode' => 'ZZ99',
            'service_area_id' => $inactive->id,
            'sort_order' => 3,
        ]);
    }
}
