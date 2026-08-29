<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliverDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'deliveredAt' => $this->input('deliveredAt', $this->input('delivered_at')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:255'],
            'deliveredAt' => ['nullable', 'date'],
        ];
    }
}
