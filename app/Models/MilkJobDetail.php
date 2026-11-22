<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delivery_request_id',
    'milk_litres',
    'mother_party_id',
])]
class MilkJobDetail extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'delivery_request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'milk_litres' => 'float',
        ];
    }

    /**
     * @return BelongsTo<DeliveryRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'delivery_request_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function motherParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'mother_party_id');
    }
}
