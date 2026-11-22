<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\DeliveryJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'request_id',
    'leg_number',
    'reference',
    'status',
    'collection_place_id',
    'delivery_place_id',
    'collection_notes',
    'delivery_notes',
    'estimated_collection_at',
    'estimated_delivery_at',
    'planned_arrival_at',
    'operational_shift_id',
    'allocated_at',
    'contents_confirmed',
    'receipt_number',
    'collected_at',
    'recipient',
    'no_recipient',
    'delivered_at',
    'cancellation_reason',
    'cancelled_at',
])]
class DeliveryLeg extends Model
{
    /** @use HasFactory<DeliveryJobFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected $table = 'delivery_legs';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'no_recipient' => false,
    ];

    protected static function newFactory(): DeliveryJobFactory
    {
        return DeliveryJobFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'leg_number' => 'integer',
            'contents_confirmed' => 'boolean',
            'no_recipient' => 'boolean',
            'estimated_collection_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
            'allocated_at' => 'datetime',
            'collected_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DeliveryRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'request_id');
    }

    /**
     * Legacy name used by older tests/call sites for the request header.
     *
     * @return BelongsTo<DeliveryRequest, $this>
     */
    public function parentJob(): BelongsTo
    {
        return $this->request();
    }

    /**
     * Sibling hops on the same request (including this leg).
     *
     * @return HasMany<DeliveryLeg, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(self::class, 'request_id', 'request_id')->orderBy('leg_number');
    }

    /**
     * @return BelongsTo<OperationalShift, $this>
     */
    public function operationalShift(): BelongsTo
    {
        return $this->belongsTo(OperationalShift::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function collectionPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'collection_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function deliveryPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'delivery_place_id');
    }

    /**
     * Service areas live on the request; exposed here so list/detail eager-loads stay familiar.
     *
     * @return BelongsToMany<ServiceArea, $this>
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceArea::class,
            'delivery_request_service_areas',
            'delivery_request_id',
            'service_area_id',
            'request_id',
            'id',
        );
    }

    public function isRelayParent(): bool
    {
        return false;
    }

    public function isRelayLeg(): bool
    {
        return $this->requestIsRelay();
    }

    public function requestIsRelay(): bool
    {
        $request = $this->request;
        if ($request === null) {
            return false;
        }

        return $request->isRelay();
    }

    public function needsMotherContact(): bool
    {
        if ($this->requestIsRelay()) {
            return false;
        }

        return $this->request?->needsMotherContact() ?? false;
    }

    /**
     * API `parentJobId`: request id when this hop is part of a multi-leg relay; null for a
     * single-leg (standalone) job so the SPA keeps treating it as top-level.
     */
    public function apiParentJobId(): ?string
    {
        if (! $this->requestIsRelay()) {
            return null;
        }

        return $this->request_id;
    }

    /**
     * API `legNumber`: only exposed for relay legs.
     */
    public function apiLegNumber(): ?int
    {
        if (! $this->requestIsRelay()) {
            return null;
        }

        return $this->leg_number;
    }

    public function previousLeg(): ?self
    {
        if ($this->leg_number === null || $this->leg_number <= 1) {
            return null;
        }

        $siblings = $this->request?->legs;
        if ($siblings === null) {
            return null;
        }

        return $siblings
            ->filter(fn (self $sibling): bool => $sibling->leg_number !== null && $sibling->leg_number < $this->leg_number)
            ->sortByDesc('leg_number')
            ->first();
    }

    public function waitingForLeg(): ?int
    {
        if (! in_array($this->status, [JobStatus::New, JobStatus::Allocated, JobStatus::Collected], true)) {
            return null;
        }

        $previous = $this->previousLeg();
        if ($previous === null || $previous->status === JobStatus::Delivered) {
            return null;
        }

        return $previous->leg_number;
    }

    /**
     * @return list<string>
     */
    public function allowedActionNames(): array
    {
        $actions = array_map(
            static fn (JobAction $action): string => $action->value,
            JobAction::forStatus($this->status),
        );

        if ($this->waitingForLeg() !== null) {
            $actions = array_values(array_filter(
                $actions,
                static fn (string $action): bool => $action !== JobAction::Collect->value && $action !== JobAction::Deliver->value,
            ));
        }

        if (! $this->requestIsRelay() && $this->status === JobStatus::New) {
            $actions[] = 'relay';
        }

        return $actions;
    }

    // --- Request-level attributes for DeliveryJobResource compatibility ---

    public function getJobTypeAttribute(): ?JobType
    {
        return $this->request?->job_type;
    }

    public function getMilkLitresAttribute(): ?float
    {
        return $this->request?->milkDetail?->milk_litres;
    }

    public function getIsBereavementAttribute(): bool
    {
        return (bool) ($this->request?->is_bereavement ?? false);
    }

    public function getSenderNameAttribute(): ?string
    {
        return $this->request?->senderParty?->name;
    }

    public function getSenderPhoneAttribute(): ?string
    {
        return $this->request?->senderParty?->phone;
    }

    public function getSenderOrganisationAttribute(): ?string
    {
        return $this->request?->senderParty?->organisation;
    }

    public function getMotherNameAttribute(): ?string
    {
        return $this->request?->milkDetail?->motherParty?->name;
    }

    public function getMotherPhoneAttribute(): ?string
    {
        return $this->request?->milkDetail?->motherParty?->phone;
    }

    public function getMotherPhoneSecondaryAttribute(): ?string
    {
        return $this->request?->milkDetail?->motherParty?->phone_secondary;
    }

    public function getContentsAttribute(): ?string
    {
        return $this->request?->content_label_snapshot;
    }

    public function getScheduledCollectionAtAttribute(): mixed
    {
        return $this->request?->scheduled_collection_at;
    }

    public function getCreatedByUserIdAttribute(): ?string
    {
        return $this->request?->created_by_user_id;
    }

    public function getParentJobIdAttribute(): ?string
    {
        return $this->apiParentJobId();
    }
}
