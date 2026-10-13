<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOutboundPostcodeAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [
            'outboundPostcode' => $this->input('outboundPostcode', $this->input('outbound_postcode')),
            'areaCode' => $this->input('areaCode', $this->input('area_code')),
            'areaFullName' => $this->input('areaFullName', $this->input('area_full_name')),
            'sortOrder' => $this->input('sortOrder', $this->input('sort_order')),
        ];

        if ($this->exists('active')) {
            $payload['active'] = $this->input('active');
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outboundPostcode' => ['required', 'string', 'max:8'],
            'areaCode' => ['required', 'string', 'max:16'],
            'areaFullName' => ['required', 'string', 'max:255'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
