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
            'noRecipient' => $this->normaliseNoRecipient($this->input('noRecipient')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $noRecipient = $this->input('noRecipient') === true;

        return [
            'noRecipient' => ['boolean'],
            // When nobody received the delivery any recipient sent alongside is ignored, so it is not validated or kept.
            'recipient' => $noRecipient
                ? ['nullable']
                : ['required', 'string', 'max:255'],
            'deliveredAt' => ['nullable', 'date'],
        ];
    }

    /**
     * An older client omits the flag: that means a recipient is expected. Anything that is not a
     * recognisable boolean is left as sent so the `boolean` rule rejects it.
     */
    private function normaliseNoRecipient(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $value;
    }
}
