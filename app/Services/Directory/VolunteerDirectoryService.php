<?php

namespace App\Services\Directory;

use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsException;
use App\Services\ThreeRings\ThreeRingsClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class VolunteerDirectoryService
{
    public function __construct(private readonly ThreeRingsClient $threeRings) {}

    /**
     * @return Collection<int, Volunteer>
     */
    public function search(?string $query, ?string $role, ?string $area): Collection
    {
        if ($this->isBlankSearch($query, $role, $area)) {
            return collect();
        }

        return $this->volunteers()
            ->filter(fn (Volunteer $volunteer): bool => $this->matchesSearch($volunteer, $query, $role, $area))
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, Volunteer>
     */
    private function volunteers(): Collection
    {
        try {
            return $this->threeRings->volunteers();
        } catch (Throwable $exception) {
            $this->throwDirectoryUnavailable($exception);
        }
    }

    private function isBlankSearch(?string $query, ?string $role, ?string $area): bool
    {
        return $this->normalised($query) === null
            && $this->normalised($role) === null
            && $this->normalised($area) === null;
    }

    private function matchesSearch(
        Volunteer $volunteer,
        ?string $query,
        ?string $role,
        ?string $area,
    ): bool {
        if (! $this->matchesName($volunteer, $query)) {
            return false;
        }

        if (! $this->matchesRole($volunteer, $role)) {
            return false;
        }

        return $this->matchesArea($volunteer, $area);
    }

    private function matchesName(Volunteer $volunteer, ?string $query): bool
    {
        $needle = $this->normalised($query);
        if ($needle === null) {
            return true;
        }

        return Str::contains(Str::lower($volunteer->name), Str::lower($needle));
    }

    private function matchesRole(Volunteer $volunteer, ?string $role): bool
    {
        $needle = $this->normalised($role);
        if ($needle === null) {
            return true;
        }

        return collect($volunteer->roles)
            ->contains(fn (string $candidate): bool => Str::lower($candidate) === Str::lower($needle));
    }

    private function matchesArea(Volunteer $volunteer, ?string $area): bool
    {
        $needle = $this->normalised($area);
        if ($needle === null) {
            return true;
        }

        $value = $volunteer->area();
        if ($value === null) {
            return false;
        }

        return Str::contains(Str::lower($value), Str::lower($needle));
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

    private function throwDirectoryUnavailable(Throwable $exception): never
    {
        if ($exception instanceof ThreeRingsException) {
            Log::warning('Three Rings directory unavailable while searching volunteers.', [
                'exception' => $exception,
            ]);

            throw new HttpException(503, 'Volunteer directory is unavailable.');
        }

        Log::error('Unexpected failure searching the volunteer directory.', [
            'exception' => $exception,
        ]);

        throw new HttpException(503, 'Volunteer directory is unavailable.');
    }
}
