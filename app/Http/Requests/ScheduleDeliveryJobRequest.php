<?php

namespace App\Http\Requests;

use App\Enums\JobType;
use App\Models\DeliveryJob;
use App\Rules\FutureMilkCollection;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleDeliveryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('scheduledCollectionAt');

        $this->merge([
            'scheduledCollectionAt' => is_string($value) ? trim($value) : $value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $job = $this->route('job');
        $isMilk = $job instanceof DeliveryJob && $job->job_type === JobType::Milk;

        return [
            'scheduledCollectionAt' => [
                'required',
                'string',
                'date',
                ...($isMilk ? [new FutureMilkCollection] : []),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduledCollectionAt.required' => 'Enter the collection date and time.',
            'scheduledCollectionAt.date' => 'Enter a valid collection date and time.',
        ];
    }
}
