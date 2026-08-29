<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LogoffShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'endMileage' => $this->input('endMileage', $this->input('end_mileage')),
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
        ];
    }
}
