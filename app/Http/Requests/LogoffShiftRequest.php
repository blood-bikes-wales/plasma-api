<?php

namespace App\Http\Requests;

use App\Enums\BikeKeptAt;
use App\Models\OperationalShift;
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
            'endMileage' => [Rule::requiredIf($this->shiftHasBike(...)), 'nullable', 'integer', 'min:0'],
            'faults' => ['nullable', 'string', 'max:2000'],
            'bikeKeptAt' => [Rule::requiredIf($this->shiftHasBike(...)), 'nullable', Rule::enum(BikeKeptAt::class)],
            'mileageExplanation' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A shift logged on without a bike has no mileage or bike location to report.
     */
    private function shiftHasBike(): bool
    {
        $shift = $this->route('shift');

        return ! ($shift instanceof OperationalShift) || $shift->bike_id !== null;
    }
}
