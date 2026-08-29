<?php

namespace App\Http\Resources;

use App\Services\ThreeRings\Data\Volunteer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Directory volunteer shape for Plasma Controller (`app/lib/directory.ts`).
 *
 * @mixin Volunteer
 */
class DirectoryVolunteerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'roles' => $this->roles,
            'area' => $this->area(),
            'email' => $this->email,
            'phone' => $this->phone(),
        ];
    }
}
