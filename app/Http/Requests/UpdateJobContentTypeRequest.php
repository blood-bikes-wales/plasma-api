<?php

namespace App\Http\Requests;

use App\Models\JobContentType;
use App\Rules\UniqueJobContentName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobContentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('name')) {
            $payload['name'] = UniqueJobContentName::normalise($this->input('name'));
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
        /** @var JobContentType $entry */
        $entry = $this->route('jobContent');

        return [
            'name' => [
                'required_without_all:active,sortOrder',
                'filled',
                'string',
                'max:100',
                new UniqueJobContentName($entry->id),
            ],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without_all' => 'Send a name, an active flag or a sort order to change.',
            'name.filled' => 'Enter a name for the contents.',
        ];
    }
}
