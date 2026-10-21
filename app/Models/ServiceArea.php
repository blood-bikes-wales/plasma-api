<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'full_name',
])]
class ServiceArea extends Model
{
    /** @use HasFactory<ServiceAreaFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return HasMany<OutboundPostcodeArea, $this>
     */
    public function outboundPostcodeAreas(): HasMany
    {
        return $this->hasMany(OutboundPostcodeArea::class);
    }

    /**
     * @return HasMany<Bike, $this>
     */
    public function bikes(): HasMany
    {
        return $this->hasMany(Bike::class);
    }
}
