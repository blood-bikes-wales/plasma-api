<?php

namespace App\Http\Requests;

use App\Enums\BikeKeptAt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogoffShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $bikeKeptAt = $this->input('bikeKeptAt');

        $this->merge([
            'endMileage' => $this->input('endMileage', $this->input('end_mileage')),
            'bikeKeptAt' => $bikeKeptAt === null || $bikeKeptAt === '' ? BikeKeptAt::Lockup->value : $bikeKeptAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endMileage' => ['required', 'integer', 'min:0'],
            'faults' => ['nullable', 'string', 'max:2000'],
            'bikeKeptAt' => ['required', Rule::enum(BikeKeptAt::class)],
            'mileageExplanation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
