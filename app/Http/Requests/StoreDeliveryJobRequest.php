<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sender' => $this->normaliseSender($this->arrayInput('sender')),
            'collection' => $this->normaliseLocation($this->arrayInput('collection')),
            'delivery' => $this->normaliseLocation($this->arrayInput('delivery')),
            'contents' => $this->input('contents'),
            'serviceAreas' => $this->input('serviceAreas', $this->input('service_areas')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sender' => ['required', 'array'],
            'sender.name' => ['required', 'string', 'max:255'],
            'sender.phone' => ['required', 'string', 'max:50'],
            'sender.organisation' => ['nullable', 'string', 'max:255'],
            'collection' => ['required', 'array'],
            'collection.placeId' => ['required', 'string', 'max:255'],
            'collection.address' => ['required', 'string', 'max:500'],
            'collection.latitude' => ['required', 'numeric', 'between:-90,90'],
            'collection.longitude' => ['required', 'numeric', 'between:-180,180'],
            'delivery' => ['required', 'array'],
            'delivery.placeId' => ['required', 'string', 'max:255'],
            'delivery.address' => ['required', 'string', 'max:500'],
            'delivery.latitude' => ['required', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['required', 'numeric', 'between:-180,180'],
            'contents' => ['required', 'string', 'max:2000'],
            'serviceAreas' => ['required', 'array', 'min:1'],
            'serviceAreas.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection.placeId.required' => 'Collection location must include a Google Place ID.',
            'collection.address.required' => 'Collection location must include an address.',
            'collection.latitude.required' => 'Collection location must include coordinates.',
            'collection.longitude.required' => 'Collection location must include coordinates.',
            'delivery.placeId.required' => 'Delivery location must include a Google Place ID.',
            'delivery.address.required' => 'Delivery location must include an address.',
            'delivery.latitude.required' => 'Delivery location must include coordinates.',
            'delivery.longitude.required' => 'Delivery location must include coordinates.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayInput(string $key): mixed
    {
        $value = $this->input($key);
        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $sender
     * @return array<string, mixed>|null
     */
    private function normaliseSender(?array $sender): ?array
    {
        if ($sender === null) {
            return null;
        }

        return [
            'name' => $sender['name'] ?? null,
            'phone' => $sender['phone'] ?? $sender['contactNumber'] ?? $sender['contact_number'] ?? null,
            'organisation' => $sender['organisation'] ?? $sender['organization'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    private function normaliseLocation(?array $location): ?array
    {
        if ($location === null) {
            return null;
        }

        return [
            'placeId' => $location['placeId'] ?? $location['place_id'] ?? null,
            'address' => $location['address'] ?? null,
            'latitude' => $location['latitude'] ?? $location['lat'] ?? null,
            'longitude' => $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null,
        ];
    }
}
