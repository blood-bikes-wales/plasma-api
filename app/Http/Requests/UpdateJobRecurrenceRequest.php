<?php

namespace App\Http\Requests;

use App\Enums\RecurrenceFrequency;
use App\Rules\ActiveServiceAreaCode;
use App\Rules\UkPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobRecurrenceRequest extends FormRequest
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

        $merge = [
            'timezone' => $this->input('timezone'),
            'timeOfDay' => $this->input('timeOfDay', $this->input('time_of_day')),
            'frequency' => $this->input('frequency'),
            'byWeekday' => $this->input('byWeekday', $this->input('by_weekday')),
            'startsOn' => $this->input('startsOn', $this->input('starts_on')),
            'endsOn' => $this->input('endsOn', $this->input('ends_on')),
            'horizonDays' => $this->input('horizonDays', $this->input('horizon_days')),
            'isBereavement' => $this->input('isBereavement'),
            'contents' => $this->input('contents'),
            'milkLitres' => $this->input('milkLitres'),
            'serviceAreas' => $serviceAreas,
        ];

        if (is_array($this->input('sender'))) {
            $sender = $this->input('sender');
            $merge['sender'] = [
                'name' => $sender['name'] ?? null,
                'phone' => $sender['phone'] ?? null,
                'organisation' => $sender['organisation'] ?? null,
            ];
        }

        if (is_array($this->input('mother'))) {
            $mother = $this->input('mother');
            $merge['mother'] = [
                'name' => $mother['name'] ?? null,
                'phone' => $mother['phone'] ?? null,
                'secondaryPhone' => $mother['secondaryPhone'] ?? $mother['secondary_phone'] ?? null,
            ];
        }

        foreach (['collection', 'delivery'] as $key) {
            if (! is_array($this->input($key))) {
                continue;
            }
            $location = $this->input($key);
            $merge[$key] = [
                'placeId' => $location['placeId'] ?? $location['place_id'] ?? null,
                'address' => $location['address'] ?? null,
                'postcode' => $location['postcode'] ?? null,
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'notes' => $location['notes'] ?? null,
            ];
        }

        $this->merge(array_filter(
            $merge,
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $frequency = $this->input('frequency');

        return [
            'sender' => ['sometimes', 'array'],
            'sender.name' => ['required_with:sender', 'string', 'max:255'],
            'sender.phone' => ['required_with:sender', 'string', 'max:50', new UkPhoneNumber],
            'sender.organisation' => ['nullable', 'string', 'max:255'],
            'mother' => ['sometimes', 'array'],
            'mother.name' => ['nullable', 'string', 'max:255'],
            'mother.phone' => ['nullable', 'string', 'max:50', new UkPhoneNumber],
            'mother.secondaryPhone' => ['nullable', 'string', 'max:50', new UkPhoneNumber],
            'milkLitres' => ['sometimes', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'isBereavement' => ['sometimes', 'boolean'],
            'collection' => ['sometimes', 'array'],
            'collection.placeId' => ['required_with:collection', 'string', 'max:255'],
            'collection.address' => ['required_with:collection', 'string', 'max:500'],
            'collection.postcode' => ['nullable', 'string', 'max:10'],
            'collection.latitude' => ['required_with:collection', 'numeric', 'between:-90,90'],
            'collection.longitude' => ['required_with:collection', 'numeric', 'between:-180,180'],
            'collection.notes' => ['nullable', 'string', 'max:500'],
            'delivery' => ['sometimes', 'array'],
            'delivery.placeId' => ['required_with:delivery', 'string', 'max:255'],
            'delivery.address' => ['required_with:delivery', 'string', 'max:500'],
            'delivery.postcode' => ['nullable', 'string', 'max:10'],
            'delivery.latitude' => ['required_with:delivery', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['required_with:delivery', 'numeric', 'between:-180,180'],
            'delivery.notes' => ['nullable', 'string', 'max:500'],
            'contents' => [
                'sometimes',
                'string',
                'max:2000',
                Rule::exists('job_content_types', 'name')->where('active', true),
            ],
            'serviceAreas' => ['sometimes', 'array', 'min:1'],
            'serviceAreas.*' => ['required', 'string', 'max:16', new ActiveServiceAreaCode],
            'timezone' => ['sometimes', 'timezone:all'],
            'timeOfDay' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'frequency' => ['sometimes', Rule::enum(RecurrenceFrequency::class)],
            'byWeekday' => [
                Rule::requiredIf($frequency === RecurrenceFrequency::Weekly->value),
                'nullable',
                'array',
            ],
            'byWeekday.*' => ['integer', 'between:1,7'],
            'startsOn' => ['sometimes', 'date'],
            'endsOn' => ['nullable', 'date'],
            'horizonDays' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
