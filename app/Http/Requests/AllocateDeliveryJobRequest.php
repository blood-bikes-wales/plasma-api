<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AllocateDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'shiftId' => $this->input('shiftId', $this->input('shift_id')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shiftId' => ['required', 'uuid', 'exists:operational_shifts,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shiftId.required' => 'Select an active shift rider to allocate this job.',
            'shiftId.exists' => 'The selected shift is invalid.',
        ];
    }
}
