<?php

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use Illuminate\Support\Collection;

final class RelayJobStatusAggregator
{
    /**
     * @param  Collection<int, DeliveryJob>  $legs
     */
    public function aggregate(Collection $legs): JobStatus
    {
        if ($legs->isEmpty()) {
            return JobStatus::New;
        }

        $statuses = $legs->map(static fn (DeliveryJob $leg): JobStatus => $leg->status);

        if ($statuses->every(static fn (JobStatus $status): bool => $status === JobStatus::Cancelled)) {
            return JobStatus::Cancelled;
        }

        if ($statuses->every(static fn (JobStatus $status): bool => $status === JobStatus::Delivered)) {
            return JobStatus::Delivered;
        }

        $activeStatuses = $statuses
            ->filter(static fn (JobStatus $status): bool => $status !== JobStatus::Cancelled)
            ->values();

        if ($activeStatuses->isEmpty()) {
            return JobStatus::Cancelled;
        }

        return $this->minimumProgress($activeStatuses);
    }

    /**
     * @param  Collection<int, JobStatus>  $statuses
     */
    private function minimumProgress(Collection $statuses): JobStatus
    {
        $order = [
            JobStatus::New,
            JobStatus::Allocated,
            JobStatus::Collected,
            JobStatus::Delivered,
        ];

        foreach ($order as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return JobStatus::Delivered;
    }
}
