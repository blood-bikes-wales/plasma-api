<?php

namespace App\Http\Requests;

use App\Enums\JobType;
use App\Models\DeliveryLeg;
use App\Models\DeliveryRequest;
use App\Rules\FutureMilkCollection;
use App\Services\Jobs\DeliveryJobService;
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
        $job = $this->resolvedJob();
        $jobType = $job instanceof DeliveryRequest
            ? $job->job_type
            : $job->request?->job_type;
        $isMilk = $jobType === JobType::Milk;

        return [
            'scheduledCollectionAt' => [
                'required',
                'string',
                'date',
                ...($isMilk ? [new FutureMilkCollection] : []),
            ],
        ];
    }

    private function resolvedJob(): DeliveryRequest|DeliveryLeg
    {
        $param = $this->route('job');
        if ($param instanceof DeliveryRequest || $param instanceof DeliveryLeg) {
            return $param;
        }

        return app(DeliveryJobService::class)->resolve((string) $param);
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
