<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutboundPostcodeAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('outboundPostcode') || $this->exists('outbound_postcode')) {
            $payload['outboundPostcode'] = $this->input('outboundPostcode', $this->input('outbound_postcode'));
        }

        if ($this->exists('areaCode') || $this->exists('area_code')) {
            $payload['areaCode'] = $this->input('areaCode', $this->input('area_code'));
        }

        if ($this->exists('areaFullName') || $this->exists('area_full_name')) {
            $payload['areaFullName'] = $this->input('areaFullName', $this->input('area_full_name'));
        }

        if ($this->exists('sortOrder') || $this->exists('sort_order')) {
            $payload['sortOrder'] = $this->input('sortOrder', $this->input('sort_order'));
        }

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
            'outboundPostcode' => ['sometimes', 'string', 'max:8'],
            'areaCode' => ['sometimes', 'string', 'max:16'],
            'areaFullName' => ['sometimes', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
