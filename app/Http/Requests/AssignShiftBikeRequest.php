<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftBikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bikeId' => $this->input('bikeId', $this->input('bike_id')),
            'startMileage' => $this->input('startMileage', $this->input('start_mileage')),
            'mileageVarianceReason' => $this->input('mileageVarianceReason', $this->input('mileage_variance_reason')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bikeId' => ['required', 'uuid', 'exists:bikes,id'],
            'startMileage' => ['required', 'integer', 'min:0'],
            'mileageVarianceReason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
