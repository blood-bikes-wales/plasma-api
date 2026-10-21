<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\RiderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'external_id',
    'name',
])]
class Rider extends Model
{
    /** @use HasFactory<RiderFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<OperationalShift, $this>
     */
    public function operationalShifts(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }
}
