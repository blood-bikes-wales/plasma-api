<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RelayDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $points = $this->input('rendezvousPoints', $this->input('rendezvous_points'));
        if (! is_array($points)) {
            return;
        }

        $this->merge([
            'rendezvousPoints' => array_map(
                fn (mixed $point): array => $this->normaliseLocation(is_array($point) ? $point : []),
                $points,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rendezvousPoints' => ['required', 'array', 'min:1'],
            'rendezvousPoints.*.placeId' => ['required', 'string', 'max:255'],
            'rendezvousPoints.*.address' => ['required', 'string', 'max:500'],
            'rendezvousPoints.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'rendezvousPoints.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rendezvousPoints.required' => 'Add at least one rendezvous point.',
            'rendezvousPoints.min' => 'Add at least one rendezvous point.',
            'rendezvousPoints.*.placeId.required' => 'Each rendezvous point must include a Google Place ID.',
            'rendezvousPoints.*.address.required' => 'Each rendezvous point must include an address.',
            'rendezvousPoints.*.latitude.required' => 'Each rendezvous point must include coordinates.',
            'rendezvousPoints.*.longitude.required' => 'Each rendezvous point must include coordinates.',
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function normaliseLocation(array $location): array
    {
        return [
            'placeId' => $location['placeId'] ?? $location['place_id'] ?? null,
            'address' => $location['address'] ?? null,
            'latitude' => $location['latitude'] ?? $location['lat'] ?? null,
            'longitude' => $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null,
        ];
    }
}
