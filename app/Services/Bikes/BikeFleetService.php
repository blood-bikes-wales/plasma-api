<?php

namespace App\Services\Bikes;

use App\Enums\BikeStatus;
use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class BikeFleetService
{
    /**
     * @param  array{
     *     registration: string,
     *     area: string,
     *     lastRecordedMileage: int,
     *     purchasedAt?: string|null
     * }  $payload
     */
    public function create(User $actor, array $payload): Bike
    {
        $bike = Bike::query()->create([
            'registration' => $payload['registration'],
            'area' => $payload['area'],
            'last_recorded_mileage' => $payload['lastRecordedMileage'],
            'status' => BikeStatus::Active->value,
            'purchased_at' => $payload['purchasedAt'] ?? null,
        ]);

        Log::info('Bike created.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike;
    }

    /**
     * @param  array{
     *     registration: string,
     *     area: string,
     *     purchasedAt?: string|null
     * }  $payload
     */
    public function update(User $actor, Bike $bike, array $payload): Bike
    {
        $this->assertNotRetired($bike);

        $bike->fill([
            'registration' => $payload['registration'],
            'area' => $payload['area'],
            'purchased_at' => $payload['purchasedAt'] ?? null,
        ])->save();

        Log::info('Bike updated.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike;
    }

    public function retire(User $actor, Bike $bike): Bike
    {
        if ($bike->status === BikeStatus::Retired->value) {
            throw ValidationException::withMessages([
                'bike' => ['This bike has already been retired.'],
            ]);
        }

        $this->assertNotOnActiveShift($bike);

        $bike->fill([
            'status' => BikeStatus::Retired->value,
            'retired_at' => now(),
        ])->save();

        Log::info('Bike retired.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike;
    }

    private function assertNotRetired(Bike $bike): void
    {
        if ($bike->status === BikeStatus::Retired->value) {
            throw ValidationException::withMessages([
                'bike' => ['Retired bikes cannot be updated.'],
            ]);
        }
    }

    private function assertNotOnActiveShift(Bike $bike): void
    {
        $onShift = OperationalShift::query()
            ->active()
            ->where('bike_id', $bike->id)
            ->exists();

        if ($onShift) {
            throw ValidationException::withMessages([
                'bike' => ['This bike is on an active shift and cannot be retired.'],
            ]);
        }
    }
}
