<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\BikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['registration', 'last_recorded_mileage'])]
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
            'last_recorded_mileage' => 'integer',
        ];
    }

    /**
     * @return HasMany<OperationalShift, $this>
     */
    public function operationalShifts(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }

    /**
     * @return HasMany<MileageReading, $this>
     */
    public function mileageReadings(): HasMany
    {
        return $this->hasMany(MileageReading::class);
    }
}
