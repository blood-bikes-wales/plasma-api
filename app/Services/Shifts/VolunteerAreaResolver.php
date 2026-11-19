<?php

namespace App\Services\Shifts;

use App\Models\ServiceArea;
use App\Services\ThreeRings\Data\Volunteer;
use Illuminate\Support\Str;

/**
 * Works out which service areas a rider covers. Three Rings has no area field:
 * a rider holds one "Rider - <area>" role per area, and each service area
 * records the role that marks it (`service_areas.three_rings_role`).
 */
final class VolunteerAreaResolver
{
    /** @var array<string, string>|null service-area code keyed by lower-cased role name */
    private ?array $codesByRole = null;

    /**
     * @return list<string> service-area codes, empty when no role matches
     */
    public function codesFor(Volunteer $volunteer): array
    {
        $codesByRole = $this->codesByRole();

        return collect($volunteer->roles)
            ->map(fn (string $role): ?string => $codesByRole[$this->normalise($role)] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function codesByRole(): array
    {
        return $this->codesByRole ??= ServiceArea::query()
            ->whereNotNull('three_rings_role')
            ->get(['code', 'three_rings_role'])
            ->mapWithKeys(fn (ServiceArea $area): array => [
                $this->normalise((string) $area->three_rings_role) => $area->code,
            ])
            ->all();
    }

    private function normalise(string $role): string
    {
        return Str::lower(trim($role));
    }
}
