<?php

namespace App\Http\Requests;

use App\Enums\ServiceArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $registration = $this->input('registration');
        if (is_string($registration)) {
            $registration = strtoupper(trim($registration));
        }

        $this->merge([
            'registration' => $registration,
            'area' => $this->input('area'),
            'lastRecordedMileage' => $this->input(
                'lastRecordedMileage',
                $this->input('last_recorded_mileage'),
            ),
            'purchasedAt' => $this->input('purchasedAt', $this->input('purchased_at')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required', 'string', 'max:20', Rule::unique('bikes', 'registration')],
            'area' => ['required', Rule::enum(ServiceArea::class)],
            'lastRecordedMileage' => ['required', 'integer', 'min:0'],
            'purchasedAt' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
