<?php

namespace App\Enums;

enum JobScope: string
{
    case Active = 'active';
    case Completed = 'completed';

    /**
     * @return list<JobStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Active => [
                JobStatus::New,
                JobStatus::Allocated,
                JobStatus::Collected,
            ],
            self::Completed => [
                JobStatus::Delivered,
                JobStatus::Cancelled,
            ],
        };
    }
}
