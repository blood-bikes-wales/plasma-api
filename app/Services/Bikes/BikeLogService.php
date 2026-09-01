<?php

namespace App\Services\Bikes;

use App\Enums\ServiceArea;
use App\Models\Bike;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BikeLogService
{
    /**
     * @return Collection<int, Bike>
     */
    public function search(?string $query): Collection
    {
        $needle = $this->normalised($query);
        if ($needle === null) {
            return collect();
        }

        return Bike::query()
            ->whereRaw('LOWER(registration) LIKE ?', ['%'.Str::lower($needle).'%'])
            ->orderBy('registration')
            ->get();
    }

    public function findWithHistory(string $bikeId): Bike
    {
        /** @var Bike $bike */
        $bike = Bike::query()
            ->with(['mileageReadings' => static function ($query): void {
                $query->orderByDesc('recorded_at');
            }])
            ->findOrFail($bikeId);

        return $bike;
    }

    /**
     * @return EloquentCollection<int, Bike>
     */
    public function listActive(): EloquentCollection
    {
        return Bike::query()
            ->active()
            ->orderBy('registration')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Bike>
     */
    public function listManaged(?string $status, ?string $area): EloquentCollection
    {
        $query = Bike::query()->orderBy('registration');

        if ($status === 'retired') {
            $query->retired();
        }

        if ($status === 'active') {
            $query->active();
        }

        if ($area !== null && $area !== '') {
            $serviceArea = ServiceArea::tryFrom($area);
            if ($serviceArea !== null) {
                $query->where('area', $serviceArea->value);
            }
        }

        return $query->get();
    }

    private function normalised(?string $value): ?string
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
