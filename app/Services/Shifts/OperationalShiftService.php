<?php

namespace App\Services\Shifts;

use App\Enums\BikeKeptAt;
use App\Models\Bike;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\Rider;
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
    /**
     * A change in mileage over one shift (end - start) at or above this needs an explanation.
     * Keep in sync with SILLY_MILEAGE_THRESHOLD in plasma-controller `app/lib/shifts.ts`.
     */
    public const SILLY_MILEAGE_THRESHOLD = 500;

    public function __construct(
        private readonly ThreeRingsClient $threeRings,
        private readonly VolunteerAreaResolver $areas,
    ) {}

    /**
     * @return Collection<int, OperationalShift>
     */
    public function listActive(): Collection
    {
        return OperationalShift::query()
            ->active()
            ->with(['bike', 'rider'])
            ->orderBy('logged_on_at')
            ->get();
    }

    public function logon(
        User $actor,
        int $riderId,
        ?string $bikeId,
        ?int $startMileage,
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

    /**
     * Attach a bike to an active shift that was logged on without one.
     * Does not change when the rider logged on.
     */
    public function assignBike(
        User $actor,
        OperationalShift $shift,
        string $bikeId,
        int $startMileage,
        ?string $mileageVarianceReason,
    ): OperationalShift {
        $volunteer = $this->findVolunteer((int) $shift->loadMissing('rider')->rider->external_id);
        $reason = $this->normaliseOptionalText($mileageVarianceReason);

        try {
            $shift = DB::transaction(fn (): OperationalShift => $this->attachBike(
                $shift,
                $volunteer,
                $bikeId,
                $startMileage,
                $reason,
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'bikeId' => ['This bike already has an active shift.'],
            ]);
        }

        Log::info('Bike assigned to an operational shift.', [
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
        ?int $endMileage,
        ?string $faults,
        ?BikeKeptAt $bikeKeptAt = null,
        ?string $mileageExplanation = null,
    ): OperationalShift {
        $faults = $this->normaliseOptionalText($faults);
        $mileageExplanation = $this->normaliseOptionalText($mileageExplanation);

        $shift = DB::transaction(fn (): OperationalShift => $this->completeShift(
            $actor,
            $shift,
            $endMileage,
            $faults,
            $bikeKeptAt,
            $mileageExplanation,
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
        ?string $bikeId,
        ?int $startMileage,
        ?string $reason,
    ): OperationalShift {
        $bike = $bikeId === null ? null : $this->lockBike($bikeId);
        $rider = $this->upsertRider($volunteer);

        $this->assertNoActiveShiftForRider($rider->id);

        if ($bike !== null && $startMileage !== null) {
            $this->assertBikeCanBeUsed($bike, $volunteer, $startMileage, $reason);
        }

        $shift = OperationalShift::query()->create([
            'rider_id' => $rider->id,
            'bike_id' => $bike?->id,
            'start_mileage' => $bike === null ? null : $startMileage,
            'mileage_variance_reason' => $bike === null || $startMileage === null
                ? null
                : $this->storedMileageVarianceReason($bike, $startMileage, $reason),
            'logged_on_at' => now(),
            'logged_on_by_user_id' => $actor->id,
        ]);

        $shift->setRelation('bike', $bike);
        $shift->setRelation('rider', $rider);

        return $shift;
    }

    private function attachBike(
        OperationalShift $shift,
        Volunteer $volunteer,
        string $bikeId,
        int $startMileage,
        ?string $reason,
    ): OperationalShift {
        $locked = $this->lockActiveShift($shift->id);

        if ($locked->bike_id !== null) {
            throw ValidationException::withMessages([
                'shift' => ['This shift already has a bike.'],
            ]);
        }

        $bike = $this->lockBike($bikeId);

        $this->assertBikeCanBeUsed($bike, $volunteer, $startMileage, $reason);

        $locked->fill([
            'bike_id' => $bike->id,
            'start_mileage' => $startMileage,
            'mileage_variance_reason' => $this->storedMileageVarianceReason($bike, $startMileage, $reason),
        ])->save();

        $locked->setRelation('bike', $bike);

        return $locked;
    }

    /**
     * The checks shared by logging on with a bike and assigning one later.
     */
    private function assertBikeCanBeUsed(Bike $bike, Volunteer $volunteer, int $startMileage, ?string $reason): void
    {
        $this->assertNoActiveShiftForBike($bike->id);
        $this->assertBikeInRiderArea($bike, $volunteer);
        $this->assertMileageVarianceReason($bike, $startMileage, $reason);
    }

    private function completeShift(
        User $actor,
        OperationalShift $shift,
        ?int $endMileage,
        ?string $faults,
        ?BikeKeptAt $bikeKeptAt,
        ?string $mileageExplanation,
    ): OperationalShift {
        $locked = $this->lockActiveShift($shift->id);
        $loggedOffAt = now();

        $this->assertLogoffIsAfterLogon($locked, $loggedOffAt);

        if ($locked->bike_id === null) {
            // Never had a bike: there is no mileage, bike location or reading to record.
            $locked->fill([
                'logged_off_at' => $loggedOffAt,
                'faults' => $faults,
                'logged_off_by_user_id' => $actor->id,
            ])->save();

            return $locked;
        }

        if ($endMileage === null) {
            throw ValidationException::withMessages([
                'endMileage' => ['Enter the end mileage.'],
            ]);
        }

        $this->assertEndMileageNotBelowStart($locked, $endMileage);
        $this->assertMileageExplanationGiven($locked, $endMileage, $mileageExplanation);

        $locked->fill([
            'logged_off_at' => $loggedOffAt,
            'end_mileage' => $endMileage,
            'faults' => $faults,
            'bike_kept_at' => $bikeKeptAt ?? BikeKeptAt::Lockup,
            'end_mileage_reason' => $this->isSillyMileageChange($locked, $endMileage) ? $mileageExplanation : null,
            'logged_off_by_user_id' => $actor->id,
        ])->save();

        MileageReading::query()->create([
            'operational_shift_id' => $locked->id,
            'mileage' => $endMileage,
            'reason' => $locked->mileage_variance_reason,
            'recorded_at' => $loggedOffAt,
            'recorded_by_user_id' => $actor->id,
        ]);

        return $locked;
    }

    private function upsertRider(Volunteer $volunteer): Rider
    {
        $rider = Rider::query()->where('external_id', $volunteer->id)->first();

        if ($rider === null) {
            return Rider::query()->create([
                'external_id' => $volunteer->id,
                'name' => $volunteer->name,
            ]);
        }

        if ($rider->name !== $volunteer->name) {
            $rider->name = $volunteer->name;
            $rider->save();
        }

        return $rider;
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
            ->with(['bike', 'rider'])
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

    private function assertNoActiveShiftForRider(string $riderId): void
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

    private function assertBikeInRiderArea(Bike $bike, Volunteer $volunteer): void
    {
        $riderAreas = $this->areas->codesFor($volunteer);

        if ($riderAreas === []) {
            throw ValidationException::withMessages([
                'bikeId' => ['This rider has no area role in Three Rings, so no bike can be assigned.'],
            ]);
        }

        if (! in_array($bike->serviceArea?->code, $riderAreas, true)) {
            throw ValidationException::withMessages([
                'bikeId' => ["The selected bike is not in this rider's area."],
            ]);
        }
    }

    private function assertMileageVarianceReason(Bike $bike, int $startMileage, ?string $reason): void
    {
        if ($startMileage === $bike->currentMileage()) {
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

    private function assertMileageExplanationGiven(OperationalShift $shift, int $endMileage, ?string $explanation): void
    {
        if ($this->isSillyMileageChange($shift, $endMileage) && $explanation === null) {
            throw ValidationException::withMessages([
                'mileageExplanation' => ['Enter a reason for the mileage change.'],
            ]);
        }
    }

    private function isSillyMileageChange(OperationalShift $shift, int $endMileage): bool
    {
        return $endMileage - $shift->start_mileage >= self::SILLY_MILEAGE_THRESHOLD;
    }

    private function storedMileageVarianceReason(Bike $bike, int $startMileage, ?string $reason): ?string
    {
        if ($startMileage === $bike->currentMileage()) {
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
