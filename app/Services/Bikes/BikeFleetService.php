<?php

namespace App\Services\Bikes;

use App\Models\Bike;
use App\Models\OperationalShift;
use App\Models\ServiceArea;
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
            'service_area_id' => $this->requireServiceAreaId($payload['area']),
            'initial_mileage' => $payload['lastRecordedMileage'],
            'purchased_at' => $payload['purchasedAt'] ?? null,
        ]);

        Log::info('Bike created.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike->load('serviceArea');
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
            'service_area_id' => $this->requireServiceAreaId($payload['area']),
            'purchased_at' => $payload['purchasedAt'] ?? null,
        ])->save();

        Log::info('Bike updated.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike->load('serviceArea');
    }

    public function retire(User $actor, Bike $bike): Bike
    {
        if (! $bike->isActive()) {
            throw ValidationException::withMessages([
                'bike' => ['This bike has already been retired.'],
            ]);
        }

        $this->assertNotOnActiveShift($bike);

        $bike->fill([
            'retired_at' => now(),
        ])->save();

        Log::info('Bike retired.', [
            'bike_id' => $bike->id,
            'registration' => $bike->registration,
            'user_id' => $actor->id,
        ]);

        return $bike->load('serviceArea');
    }

    private function requireServiceAreaId(string $code): string
    {
        $area = ServiceArea::query()->where('code', strtoupper($code))->first();

        if ($area === null) {
            throw ValidationException::withMessages([
                'area' => ['The selected area is invalid.'],
            ]);
        }

        return $area->id;
    }

    private function assertNotRetired(Bike $bike): void
    {
        if (! $bike->isActive()) {
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
