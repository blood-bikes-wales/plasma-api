<?php

namespace Database\Factories;

use App\Enums\JobType;
use App\Enums\RecurrenceFrequency;
use App\Models\JobContentType;
use App\Models\JobRecurrence;
use App\Models\Party;
use App\Models\Place;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobRecurrence>
 */
class JobRecurrenceFactory extends Factory
{
    protected $model = JobRecurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_type' => JobType::Standard,
            'is_bereavement' => false,
            'sender_party_id' => Party::factory(),
            'content_label_snapshot' => 'Blood samples',
            'collection_place_id' => Place::factory(),
            'delivery_place_id' => Place::factory(),
            'timezone' => 'Europe/London',
            'time_of_day' => '10:00:00',
            'frequency' => RecurrenceFrequency::Weekly,
            'by_weekday' => [1, 3, 5],
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'is_active' => true,
            'horizon_days' => 14,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (JobRecurrence $recurrence): void {
            if ($recurrence->content_type_id !== null) {
                return;
            }

            $type = JobContentType::query()->firstOrCreate(
                ['name' => $recurrence->content_label_snapshot],
                ['active' => true, 'sort_order' => 1],
            );
            $recurrence->content_type_id = $type->id;
        })->afterCreating(function (JobRecurrence $recurrence): void {
            if ($recurrence->serviceAreas()->exists()) {
                return;
            }

            $area = ServiceArea::query()->where('code', 'E')->first()
                ?? ServiceArea::factory()->create(['code' => 'E']);
            $recurrence->serviceAreas()->sync([$area->id]);
        });
    }
}
