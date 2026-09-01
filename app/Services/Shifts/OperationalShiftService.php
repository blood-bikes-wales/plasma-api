<?php

namespace App\Services\Shifts;

use App\Models\Bike;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\User;
use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsException;
use App\Services\ThreeRings\ThreeRingsClient;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Plasma-owned on-duty sessions. Three Rings volunteers identify riders;
 * bikes and mileage live in the Plasma bike log. Never writes back to Three Rings.
 */
final class OperationalShiftService
{
    public function __construct(private readonly ThreeRingsClient $threeRings) {}

    /**
     * @return Collection<int, OperationalShift>
     */
    public function listActive(): Collection
    {
        return OperationalShift::query()
            ->active()
            ->with('bike')
            ->orderBy('logged_on_at')
            ->get();
    }

    public function logon(
        User $actor,
        int $riderId,
        string $bikeId,
        int $startMileage,
        ?string $mileageVarianceReason,
    ): OperationalShift {
        $volunteer = $this->findVolunteer($riderId);
        $reason = $this->normaliseOptionalText($mileageVarianceReason);

        try {
            $shift = DB::transaction(fn (): OperationalShift => $this->createActiveShift(
                $actor,
                $volunteer,
                $bikeId,
                $startMileage,
                $reason,
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'riderId' => ['A rider or bike cannot hold two active shifts at once.'],
            ]);
        }

        Log::info('Rider logged on to an operational shift.', [
            'shift_id' => $shift->id,
            'rider_id' => $shift->rider_id,
            'bike_id' => $shift->bike_id,
            'user_id' => $actor->id,
        ]);

        return $shift;
    }

    public function logoff(
        User $actor,
        OperationalShift $shift,
        int $endMileage,
        ?string $faults,
    ): OperationalShift {
        $faults = $this->normaliseOptionalText($faults);

        $shift = DB::transaction(fn (): OperationalShift => $this->completeShift(
            $actor,
            $shift,
            $endMileage,
            $faults,
        ));

        Log::info('Rider logged off an operational shift.', [
            'shift_id' => $shift->id,
            'rider_id' => $shift->rider_id,
            'bike_id' => $shift->bike_id,
            'user_id' => $actor->id,
        ]);

        return $shift;
    }

    private function createActiveShift(
        User $actor,
        Volunteer $volunteer,
        string $bikeId,
        int $startMileage,
        ?string $reason,
    ): OperationalShift {
        $bike = $this->lockBike($bikeId);

        $this->assertNoActiveShiftForRider($volunteer->id);
        $this->assertNoActiveShiftForBike($bike->id);
        $this->assertMileageVarianceReason($bike, $startMileage, $reason);

        $shift = OperationalShift::query()->create([
            'rider_id' => $volunteer->id,
            'rider_name' => $volunteer->name,
            'bike_id' => $bike->id,
            'start_mileage' => $startMileage,
            'mileage_variance_reason' => $this->storedMileageVarianceReason($bike, $startMileage, $reason),
            'logged_on_at' => now(),
            'logged_on_by_user_id' => $actor->id,
        ]);

        $shift->setRelation('bike', $bike);

        return $shift;
    }

    private function completeShift(
        User $actor,
        OperationalShift $shift,
        int $endMileage,
        ?string $faults,
    ): OperationalShift {
        $locked = $this->lockActiveShift($shift->id);
        $loggedOffAt = now();

        $this->assertLogoffIsAfterLogon($locked, $loggedOffAt);
        $this->assertEndMileageNotBelowStart($locked, $endMileage);

        $locked->fill([
            'logged_off_at' => $loggedOffAt,
            'end_mileage' => $endMileage,
            'faults' => $faults,
            'logged_off_by_user_id' => $actor->id,
        ])->save();

        $bike = $locked->bike;
        $bike->last_recorded_mileage = $endMileage;
        $bike->save();

        MileageReading::query()->create([
            'bike_id' => $bike->id,
            'operational_shift_id' => $locked->id,
            'mileage' => $endMileage,
            'reason' => $locked->mileage_variance_reason,
            'recorded_at' => $loggedOffAt,
            'recorded_by_user_id' => $actor->id,
        ]);

        return $locked;
    }

    private function lockBike(string $bikeId): Bike
    {
        $bike = Bike::query()->whereKey($bikeId)->lockForUpdate()->first();

        if ($bike === null) {
            throw ValidationException::withMessages([
                'bikeId' => ['The selected bike is invalid.'],
            ]);
        }

        if (! $bike->isActive()) {
            throw ValidationException::withMessages([
                'bikeId' => ['The selected bike has been retired.'],
            ]);
        }

        return $bike;
    }

    private function lockActiveShift(string $shiftId): OperationalShift
    {
        /** @var OperationalShift|null $locked */
        $locked = OperationalShift::query()
            ->with('bike')
            ->whereKey($shiftId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'shift' => ['The selected shift is invalid.'],
            ]);
        }

        if (! $locked->isActive()) {
            throw ValidationException::withMessages([
                'shift' => ['This shift has already been logged off.'],
            ]);
        }

        return $locked;
    }

    private function findVolunteer(int $riderId): Volunteer
    {
        $volunteer = $this->volunteerFromDirectory($riderId);

        if ($volunteer === null) {
            throw ValidationException::withMessages([
                'riderId' => ['The selected rider is invalid.'],
            ]);
        }

        return $volunteer;
    }

    private function volunteerFromDirectory(int $riderId): ?Volunteer
    {
        try {
            return $this->threeRings->volunteers()->first(
                static fn (Volunteer $candidate): bool => $candidate->id === $riderId,
            );
        } catch (Throwable $exception) {
            $this->throwDirectoryUnavailable($riderId, $exception);
        }
    }

    private function throwDirectoryUnavailable(int $riderId, Throwable $exception): never
    {
        if ($exception instanceof ThreeRingsException) {
            Log::warning('Three Rings directory unavailable while logging a rider on.', [
                'rider_id' => $riderId,
                'exception' => $exception,
            ]);

            throw new HttpException(503, 'Volunteer directory is unavailable.');
        }

        Log::error('Unexpected failure looking up a rider for logon.', [
            'rider_id' => $riderId,
            'exception' => $exception,
        ]);

        throw new HttpException(503, 'Volunteer directory is unavailable.');
    }

    private function assertNoActiveShiftForRider(int $riderId): void
    {
        $exists = OperationalShift::query()
            ->active()
            ->where('rider_id', $riderId)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'riderId' => ['This rider already has an active shift.'],
            ]);
        }
    }

    private function assertNoActiveShiftForBike(string $bikeId): void
    {
        $exists = OperationalShift::query()
            ->active()
            ->where('bike_id', $bikeId)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'bikeId' => ['This bike already has an active shift.'],
            ]);
        }
    }

    private function assertMileageVarianceReason(Bike $bike, int $startMileage, ?string $reason): void
    {
        if ($startMileage === $bike->last_recorded_mileage) {
            return;
        }

        if ($reason === null) {
            throw ValidationException::withMessages([
                'mileageVarianceReason' => ['Enter a reason for the mileage difference.'],
            ]);
        }
    }

    private function assertLogoffIsAfterLogon(OperationalShift $shift, CarbonInterface $loggedOffAt): void
    {
        if ($loggedOffAt->lt($shift->logged_on_at)) {
            throw ValidationException::withMessages([
                'shift' => ['Logoff time must be after logon time.'],
            ]);
        }
    }

    private function assertEndMileageNotBelowStart(OperationalShift $shift, int $endMileage): void
    {
        if ($endMileage < $shift->start_mileage) {
            throw ValidationException::withMessages([
                'endMileage' => ["End mileage cannot be lower than the start mileage of {$shift->start_mileage}."],
            ]);
        }
    }

    private function storedMileageVarianceReason(Bike $bike, int $startMileage, ?string $reason): ?string
    {
        if ($startMileage === $bike->last_recorded_mileage) {
            return null;
        }

        return $reason;
    }

    private function normaliseOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
