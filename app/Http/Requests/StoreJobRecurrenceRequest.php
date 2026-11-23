<?php

namespace App\Http\Requests;

use App\Enums\JobType;
use App\Enums\RecurrenceFrequency;
use App\Rules\ActiveServiceAreaCode;
use App\Rules\UkPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRecurrenceRequest extends FormRequest
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
            'isBereavement' => $this->normaliseBereavement($this->input('isBereavement')),
            'sender' => $this->normaliseSender($this->arrayInput('sender')),
            'mother' => $this->normaliseMother($this->input('mother')),
            'collection' => $this->normaliseLocation($this->arrayInput('collection')),
            'delivery' => $this->normaliseLocation($this->arrayInput('delivery')),
            'contents' => $this->input('contents'),
            'serviceAreas' => $serviceAreas,
            'timezone' => $this->input('timezone', 'Europe/London'),
            'timeOfDay' => $this->input('timeOfDay', $this->input('time_of_day')),
            'frequency' => $this->input('frequency'),
            'byWeekday' => $this->input('byWeekday', $this->input('by_weekday')),
            'startsOn' => $this->input('startsOn', $this->input('starts_on')),
            'endsOn' => $this->input('endsOn', $this->input('ends_on')),
            'horizonDays' => $this->input('horizonDays', $this->input('horizon_days', 14)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $jobType = $this->input('jobType');
        $frequency = $this->input('frequency');

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
            'isBereavement' => $jobType === JobType::Milk->value
                ? ['boolean']
                : ['boolean', 'declined'],
            'sender' => ['required', 'array'],
            'sender.name' => ['required', 'string', 'max:255'],
            'sender.phone' => ['required', 'string', 'max:50', new UkPhoneNumber],
            'sender.organisation' => ['nullable', 'string', 'max:255'],
            'mother' => [
                Rule::prohibitedIf($jobType !== JobType::Milk->value),
                Rule::requiredIf($jobType === JobType::Milk->value),
                'nullable',
                'array',
            ],
            'mother.name' => [Rule::requiredIf($jobType === JobType::Milk->value), 'nullable', 'string', 'max:255'],
            'mother.phone' => [Rule::requiredIf($jobType === JobType::Milk->value), 'nullable', 'string', 'max:50', new UkPhoneNumber],
            'mother.secondaryPhone' => ['nullable', 'string', 'max:50', new UkPhoneNumber],
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
            'contents' => [
                'required',
                'string',
                'max:2000',
                Rule::exists('job_content_types', 'name')->where('active', true),
            ],
            'serviceAreas' => ['required', 'array', 'min:1'],
            'serviceAreas.*' => ['required', 'string', 'max:16', new ActiveServiceAreaCode],
            'timezone' => ['required', 'timezone:all'],
            'timeOfDay' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'byWeekday' => [
                Rule::requiredIf($frequency === RecurrenceFrequency::Weekly->value),
                'nullable',
                'array',
                'min:1',
            ],
            'byWeekday.*' => ['integer', 'between:1,7'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'horizonDays' => ['nullable', 'integer', 'min:1', 'max:90'],
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
            'phone' => $sender['phone'] ?? $sender['contactNumber'] ?? null,
            'organisation' => $sender['organisation'] ?? $sender['organization'] ?? null,
        ];
    }

    private function normaliseMother(mixed $mother): mixed
    {
        if (! is_array($mother)) {
            return $mother;
        }

        return [
            'name' => $mother['name'] ?? null,
            'phone' => $mother['phone'] ?? null,
            'secondaryPhone' => $mother['secondaryPhone'] ?? $mother['secondary_phone'] ?? null,
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

        $notes = $location['notes'] ?? null;
        if (is_string($notes)) {
            $notes = trim($notes);
            $notes = $notes === '' ? null : $notes;
        }

        $postcode = $location['postcode'] ?? null;
        if (is_string($postcode)) {
            $postcode = trim($postcode);
            $postcode = $postcode === '' ? null : $postcode;
        }

        return [
            'placeId' => $location['placeId'] ?? $location['place_id'] ?? null,
            'address' => $location['address'] ?? null,
            'postcode' => $postcode,
            'latitude' => $location['latitude'] ?? $location['lat'] ?? null,
            'longitude' => $location['longitude'] ?? $location['lng'] ?? null,
            'notes' => $notes,
        ];
    }

    private function normaliseJobType(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return JobType::Standard->value;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function normaliseBereavement(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        return $value;
    }
}
