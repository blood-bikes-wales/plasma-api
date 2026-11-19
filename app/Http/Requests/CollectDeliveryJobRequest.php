<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contentsConfirmed' => $this->input('contentsConfirmed', $this->input('contents_confirmed')),
            'receiptNumber' => $this->input('receiptNumber', $this->input('receipt_number')),
            'collectedAt' => $this->input('collectedAt', $this->input('collected_at')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contentsConfirmed' => ['required', 'boolean'],
            'receiptNumber' => ['required', 'string', 'max:255'],
            'collectedAt' => ['nullable', 'date'],
        ];
    }
}
