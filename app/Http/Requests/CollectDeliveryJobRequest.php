<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'suitablySealed' => $this->input('suitablySealed', $this->input('suitably_sealed')),
            'sealNumber' => $this->input('sealNumber', $this->input('seal_number')),
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
            'suitablySealed' => ['required', 'boolean'],
            'sealNumber' => ['nullable', 'string', 'max:255'],
            'receiptNumber' => ['required', 'string', 'max:255'],
            'collectedAt' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $suitablySealed = filter_var(
                $this->input('suitablySealed'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            $sealNumber = $this->input('sealNumber');

            if ($suitablySealed !== true) {
                return;
            }

            if (! is_string($sealNumber) || trim($sealNumber) === '') {
                $validator->errors()->add(
                    'sealNumber',
                    'Enter a seal number when the package is suitably sealed.',
                );
            }
        });
    }
}
