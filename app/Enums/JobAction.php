<?php

namespace App\Enums;

enum JobAction: string
{
    case Allocate = 'allocate';
    case Collect = 'collect';
    case Deliver = 'deliver';
    case Cancel = 'cancel';

    public function targetStatus(): JobStatus
    {
        return match ($this) {
            self::Allocate => JobStatus::Allocated,
            self::Collect => JobStatus::Collected,
            self::Deliver => JobStatus::Delivered,
            self::Cancel => JobStatus::Cancelled,
        };
    }

    /**
     * Allocate stays available on an Allocated job so a mis-tap can be corrected: the rider
     * can be changed until the job is collected.
     *
     * @return list<self>
     */
    public static function forStatus(JobStatus $status): array
    {
        return match ($status) {
            JobStatus::New => [self::Allocate, self::Cancel],
            JobStatus::Allocated => [self::Allocate, self::Collect, self::Cancel],
            JobStatus::Collected => [self::Deliver, self::Cancel],
            JobStatus::Delivered,
            JobStatus::Cancelled => [],
        };
    }
}
