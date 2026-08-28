<?php

namespace App\Http\Resources;

use App\Services\ThreeRings\Data\Volunteer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape matches Plasma Controller rider options (`id`, `name`).
 *
 * @mixin Volunteer
 */
class VolunteerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
        ];
    }
}
