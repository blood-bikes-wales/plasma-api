<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'external_id',
    'address',
    'latitude',
    'longitude',
])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return HasMany<DeliveryJob, $this>
     */
    public function collectionJobs(): HasMany
    {
        return $this->hasMany(DeliveryJob::class, 'collection_place_id');
    }

    /**
     * @return HasMany<DeliveryJob, $this>
     */
    public function deliveryJobs(): HasMany
    {
        return $this->hasMany(DeliveryJob::class, 'delivery_place_id');
    }
}
