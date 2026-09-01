<?php

namespace App\Http\Requests;

use App\Enums\ServiceArea;
use App\Models\Bike;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $registration = $this->input('registration');
        if (is_string($registration)) {
            $registration = strtoupper(trim($registration));
        }

        $this->merge([
            'registration' => $registration,
            'area' => $this->input('area'),
            'purchasedAt' => $this->input('purchasedAt', $this->input('purchased_at')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Bike $bike */
        $bike = $this->route('bike');

        return [
            'registration' => [
                'required',
                'string',
                'max:20',
                Rule::unique('bikes', 'registration')->ignore($bike->id),
            ],
            'area' => ['required', Rule::enum(ServiceArea::class)],
            'purchasedAt' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
