<?php

namespace App\Http\Requests;

use App\Rules\UniqueJobContentName;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobContentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [
            'name' => UniqueJobContentName::normalise($this->input('name')),
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
            'name' => ['required', 'string', 'max:100', new UniqueJobContentName],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the contents.',
        ];
    }
}
