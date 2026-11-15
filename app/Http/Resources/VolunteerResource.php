<?php

namespace App\Http\Resources;

use App\Services\ThreeRings\Data\Volunteer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape matches Plasma Controller rider options (`id`, `name`, `areas`).
 * `areas` holds the service-area codes the rider covers, derived from their
 * Three Rings roles by the controller.
 *
 * @mixin Volunteer
 */
class VolunteerResource extends JsonResource
{
    /**
     * @param  list<string>  $areas
     */
    public function __construct(Volunteer $resource, private readonly array $areas = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'areas' => $this->areas,
        ];
    }
}
