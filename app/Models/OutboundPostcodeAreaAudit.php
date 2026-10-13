<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outbound_postcode_area_id',
    'action',
    'actor_user_id',
    'changes',
])]
class OutboundPostcodeAreaAudit extends Model
{
    use HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OutboundPostcodeArea, $this>
     */
    public function postcodeArea(): BelongsTo
    {
        return $this->belongsTo(OutboundPostcodeArea::class, 'outbound_postcode_area_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
