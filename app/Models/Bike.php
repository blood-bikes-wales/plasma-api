<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\BikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Fillable([
    'registration',
    'service_area_id',
    'initial_mileage',
    'retired_at',
    'purchased_at',
])]
class Bike extends Model
{
    /** @use HasFactory<BikeFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_mileage' => 'integer',
            'retired_at' => 'datetime',
            'purchased_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ServiceArea, $this>
     */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * @return HasMany<OperationalShift, $this>
     */
    public function operationalShifts(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }

    /**
     * The most recently finished shift on this bike. Shifts logged on without a
     * bike have no bike_id, so they never count.
     *
     * Not `ofMany()`: Laravel always adds max(id) to it and PostgreSQL has no
     * max(uuid). The correlated subquery picks the newest logged_off_at, with
     * the id as a (arbitrary but stable) tie-break, and still eager loads in a
     * single query.
     *
     * @return HasOne<OperationalShift, $this>
     */
    public function latestLoggedOffShift(): HasOne
    {
        return $this->hasOne(OperationalShift::class)->where(
            'operational_shifts.id',
            static function (QueryBuilder $query): void {
                $query->select('latest.id')
                    ->from('operational_shifts as latest')
                    ->whereColumn('latest.bike_id', 'operational_shifts.bike_id')
                    ->whereNotNull('latest.logged_off_at')
                    ->orderByDesc('latest.logged_off_at')
                    ->orderByDesc('latest.id')
                    ->limit(1);
            },
        );
    }

    /**
     * @return HasManyThrough<MileageReading, OperationalShift, $this>
     */
    public function mileageReadings(): HasManyThrough
    {
        return $this->hasManyThrough(
            MileageReading::class,
            OperationalShift::class,
            'bike_id',
            'operational_shift_id',
            'id',
            'id',
        );
    }

    public function isActive(): bool
    {
        return $this->retired_at === null;
    }

    public function currentMileage(): int
    {
        $latest = $this->relationLoaded('mileageReadings')
            ? $this->mileageReadings->sortByDesc('recorded_at')->first()
            : $this->mileageReadings()->orderByDesc('recorded_at')->first();

        if ($latest !== null) {
            return (int) $latest->mileage;
        }

        return (int) $this->initial_mileage;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }
}
