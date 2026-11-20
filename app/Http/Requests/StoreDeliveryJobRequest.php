<?php

namespace App\Http\Requests;

use App\Enums\JobType;
use App\Rules\ActiveServiceAreaCode;
use App\Rules\UkPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $serviceAreas = $this->input('serviceAreas', $this->input('service_areas'));
        if (is_array($serviceAreas)) {
            $serviceAreas = array_map(
                static fn (mixed $area): mixed => is_string($area) ? strtoupper(trim($area)) : $area,
                $serviceAreas,
            );
        }

        $this->merge([
            'jobType' => $this->normaliseJobType($this->input('jobType')),
            'sender' => $this->normaliseSender($this->arrayInput('sender')),
            'collection' => $this->normaliseLocation($this->arrayInput('collection')),
            'delivery' => $this->normaliseLocation($this->arrayInput('delivery')),
            'contents' => $this->input('contents'),
            'serviceAreas' => $serviceAreas,
            'estimatedCollectionAt' => $this->normaliseEstimate($this->input('estimatedCollectionAt')),
            'estimatedDeliveryAt' => $this->normaliseEstimate($this->input('estimatedDeliveryAt')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $jobType = $this->input('jobType');

        return [
            'jobType' => ['required', Rule::enum(JobType::class)],
            'milkLitres' => [
                Rule::requiredIf($jobType === JobType::Milk->value),
                Rule::prohibitedIf($jobType === JobType::Standard->value),
                'nullable',
                'numeric',
                'gt:0',
                'max:'.JobType::MAX_MILK_LITRES,
                'decimal:0,2',
            ],
            'sender' => ['required', 'array'],
            'sender.name' => ['required', 'string', 'max:255'],
            'sender.phone' => ['required', 'string', 'max:50', new UkPhoneNumber],
            'sender.organisation' => ['nullable', 'string', 'max:255'],
            'collection' => ['required', 'array'],
            'collection.placeId' => ['required', 'string', 'max:255'],
            'collection.address' => ['required', 'string', 'max:500'],
            'collection.postcode' => ['nullable', 'string', 'max:10'],
            'collection.latitude' => ['required', 'numeric', 'between:-90,90'],
            'collection.longitude' => ['required', 'numeric', 'between:-180,180'],
            'collection.notes' => ['nullable', 'string', 'max:500'],
            'delivery' => ['required', 'array'],
            'delivery.placeId' => ['required', 'string', 'max:255'],
            'delivery.address' => ['required', 'string', 'max:500'],
            'delivery.postcode' => ['nullable', 'string', 'max:10'],
            'delivery.latitude' => ['required', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['required', 'numeric', 'between:-180,180'],
            'delivery.notes' => ['nullable', 'string', 'max:500'],
            'contents' => ['required', 'string', 'max:2000'],
            'serviceAreas' => ['required', 'array', 'min:1'],
            'serviceAreas.*' => ['required', 'string', 'max:16', new ActiveServiceAreaCode],
            'estimatedCollectionAt' => ['nullable', 'date'],
            'estimatedDeliveryAt' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'milkLitres.required' => 'Enter how many litres of milk are being collected.',
            'milkLitres.prohibited' => 'Litres can only be recorded for milk jobs.',
            'milkLitres.gt' => 'Litres must be greater than 0.',
            'milkLitres.max' => 'Litres must not be more than '.JobType::MAX_MILK_LITRES.'.',
            'milkLitres.decimal' => 'Litres can have at most 2 decimal places.',
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
            'postcode' => $this->normalisePostcode($location['postcode'] ?? null),
            'latitude' => $location['latitude'] ?? $location['lat'] ?? null,
            'longitude' => $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null,
            'notes' => $this->normaliseNotes($location['notes'] ?? null),
        ];
    }

    private function normalisePostcode(mixed $postcode): mixed
    {
        if (! is_string($postcode)) {
            return $postcode;
        }

        $postcode = trim($postcode);

        return $postcode === '' ? null : $postcode;
    }

    private function normaliseNotes(mixed $notes): mixed
    {
        if (! is_string($notes)) {
            return $notes;
        }

        $notes = trim($notes);

        return $notes === '' ? null : $notes;
    }

    /**
     * The deployed SPA never sends jobType, so a missing or blank value means a standard job.
     */
    private function normaliseJobType(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return JobType::Standard->value;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function normaliseEstimate(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
