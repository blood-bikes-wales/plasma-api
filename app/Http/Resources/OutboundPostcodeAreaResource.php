<?php

namespace App\Http\Resources;

use App\Models\OutboundPostcodeArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutboundPostcodeArea
 */
class OutboundPostcodeAreaResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outboundPostcode' => $this->outbound_postcode,
            'areaCode' => $this->area_code,
            'areaFullName' => $this->area_full_name,
            'sortOrder' => $this->sort_order,
            'active' => $this->active,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
