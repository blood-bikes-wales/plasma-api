<?php

namespace App\Services\PostcodeAreas;

use App\Models\OutboundPostcodeArea;
use App\Models\OutboundPostcodeAreaAudit;
use App\Models\User;
use App\Support\UkOutwardPostcode;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PostcodeAreaService
{
    private const LOOKUP_TTL_SECONDS = 3600;

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    public function listDistinctActiveServiceAreas(): Collection
    {
        return DB::table('outbound_postcode_areas')
            ->where('active', true)
            ->select('area_code', 'area_full_name')
            ->selectRaw('MIN(sort_order) as min_sort')
            ->groupBy('area_code', 'area_full_name')
            ->orderBy('min_sort')
            ->get()
            ->map(static fn (object $row): array => [
                'code' => (string) $row->area_code,
                'name' => (string) $row->area_full_name,
            ])
            ->values();
    }

    /**
     * @return EloquentCollection<int, OutboundPostcodeArea>
     */
    public function list(?string $search, bool $includeInactive): EloquentCollection
    {
        $query = OutboundPostcodeArea::query()->orderBy('sort_order');

        if (! $includeInactive) {
            $query->active();
        }

        $needle = $this->trimmed($search);
        if ($needle !== null) {
            $like = '%'.mb_strtolower($needle).'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->whereRaw('LOWER(outbound_postcode) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(area_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(area_full_name) LIKE ?', [$like]);
            });
        }

        return $query->get();
    }

    /**
     * @return array{outboundPostcode: string, areaCode: string, areaFullName: string}|null
     */
    public function lookup(string $postcode): ?array
    {
        $outward = UkOutwardPostcode::normalise($postcode);
        if ($outward === null) {
            return null;
        }

        $cacheKey = $this->lookupCacheKey($outward);

        /** @var array{outboundPostcode: string, areaCode: string, areaFullName: string}|null $cached */
        $cached = Cache::remember($cacheKey, self::LOOKUP_TTL_SECONDS, function () use ($outward): ?array {
            $row = OutboundPostcodeArea::query()
                ->active()
                ->where('outbound_postcode', $outward)
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'outboundPostcode' => $row->outbound_postcode,
                'areaCode' => $row->area_code,
                'areaFullName' => $row->area_full_name,
            ];
        });

        return $cached;
    }

    /**
     * @param  array{
     *     outboundPostcode: string,
     *     areaCode: string,
     *     areaFullName: string,
     *     sortOrder?: int|null,
     *     active?: bool
     * }  $payload
     */
    public function create(User $actor, array $payload): OutboundPostcodeArea
    {
        $outward = $this->requireOutward($payload['outboundPostcode']);
        $this->assertUniqueOutward($outward);

        $sortOrder = $payload['sortOrder'] ?? $this->nextSortOrder();

        $area = OutboundPostcodeArea::query()->create([
            'outbound_postcode' => $outward,
            'area_code' => strtoupper($payload['areaCode']),
            'area_full_name' => $payload['areaFullName'],
            'sort_order' => $sortOrder,
            'active' => $payload['active'] ?? true,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audit($area, $actor, 'created', $area->only([
            'outbound_postcode',
            'area_code',
            'area_full_name',
            'sort_order',
            'active',
        ]));
        $this->forgetLookupCache($outward);

        Log::info('Outbound postcode area created.', [
            'outbound_postcode_area_id' => $area->id,
            'outbound_postcode' => $area->outbound_postcode,
            'user_id' => $actor->id,
        ]);

        return $area;
    }

    /**
     * @param  array{
     *     outboundPostcode?: string,
     *     areaCode?: string,
     *     areaFullName?: string,
     *     sortOrder?: int,
     *     active?: bool
     * }  $payload
     */
    public function update(User $actor, OutboundPostcodeArea $area, array $payload): OutboundPostcodeArea
    {
        $before = $area->only([
            'outbound_postcode',
            'area_code',
            'area_full_name',
            'sort_order',
            'active',
        ]);

        $previousOutward = $area->outbound_postcode;

        if (array_key_exists('outboundPostcode', $payload)) {
            $outward = $this->requireOutward($payload['outboundPostcode']);
            $this->assertUniqueOutward($outward, $area->id);
            $area->outbound_postcode = $outward;
        }

        if (array_key_exists('areaCode', $payload)) {
            $area->area_code = strtoupper($payload['areaCode']);
        }

        if (array_key_exists('areaFullName', $payload)) {
            $area->area_full_name = $payload['areaFullName'];
        }

        if (array_key_exists('sortOrder', $payload)) {
            $area->sort_order = $payload['sortOrder'];
        }

        if (array_key_exists('active', $payload)) {
            $area->active = $payload['active'];
        }

        $area->updated_by_user_id = $actor->id;
        $area->save();

        $after = $area->only([
            'outbound_postcode',
            'area_code',
            'area_full_name',
            'sort_order',
            'active',
        ]);

        $action = 'updated';
        if (($before['active'] ?? null) !== ($after['active'] ?? null)) {
            $action = ($after['active'] ?? false) ? 'activated' : 'deactivated';
        }

        $this->audit($area, $actor, $action, [
            'before' => $before,
            'after' => $after,
        ]);
        $this->forgetLookupCache($previousOutward);
        $this->forgetLookupCache($area->outbound_postcode);

        Log::info('Outbound postcode area updated.', [
            'outbound_postcode_area_id' => $area->id,
            'outbound_postcode' => $area->outbound_postcode,
            'user_id' => $actor->id,
        ]);

        return $area->refresh();
    }

    /**
     * @param  list<array{
     *     outboundPostcode: string,
     *     areaCode: string,
     *     areaFullName: string,
     *     sortOrder?: int|null,
     *     active?: bool|null
     * }>  $rows
     * @return array{created: int, updated: int}
     */
    public function import(User $actor, array $rows): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($actor, $rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $outward = $this->requireOutward($row['outboundPostcode']);
                $existing = OutboundPostcodeArea::query()
                    ->where('outbound_postcode', $outward)
                    ->first();

                if ($existing === null) {
                    $area = OutboundPostcodeArea::query()->create([
                        'outbound_postcode' => $outward,
                        'area_code' => strtoupper($row['areaCode']),
                        'area_full_name' => $row['areaFullName'],
                        'sort_order' => $row['sortOrder'] ?? $this->nextSortOrder(),
                        'active' => $row['active'] ?? true,
                        'updated_by_user_id' => $actor->id,
                    ]);
                    $this->audit($area, $actor, 'imported', [
                        'outbound_postcode' => $area->outbound_postcode,
                        'area_code' => $area->area_code,
                        'area_full_name' => $area->area_full_name,
                        'sort_order' => $area->sort_order,
                        'active' => $area->active,
                    ]);
                    $this->forgetLookupCache($outward);
                    $created++;

                    continue;
                }

                $before = $existing->only([
                    'outbound_postcode',
                    'area_code',
                    'area_full_name',
                    'sort_order',
                    'active',
                ]);

                $existing->fill([
                    'area_code' => strtoupper($row['areaCode']),
                    'area_full_name' => $row['areaFullName'],
                    'active' => $row['active'] ?? $existing->active,
                    'updated_by_user_id' => $actor->id,
                ]);

                // Preserve existing sort order when import omits it.
                if (array_key_exists('sortOrder', $row) && $row['sortOrder'] !== null) {
                    $existing->sort_order = $row['sortOrder'];
                }

                $existing->save();

                $this->audit($existing, $actor, 'imported', [
                    'before' => $before,
                    'after' => $existing->only([
                        'outbound_postcode',
                        'area_code',
                        'area_full_name',
                        'sort_order',
                        'active',
                    ]),
                ]);
                $this->forgetLookupCache($outward);
                $updated++;
            }
        });

        Log::info('Outbound postcode areas imported.', [
            'created' => $created,
            'updated' => $updated,
            'user_id' => $actor->id,
        ]);

        return ['created' => $created, 'updated' => $updated];
    }

    private function requireOutward(string $value): string
    {
        $outward = UkOutwardPostcode::normalise($value);
        if ($outward === null) {
            throw ValidationException::withMessages([
                'outboundPostcode' => ['Invalid UK outward postcode.'],
            ]);
        }

        return $outward;
    }

    private function assertUniqueOutward(string $outward, ?string $ignoreId = null): void
    {
        $query = OutboundPostcodeArea::query()->where('outbound_postcode', $outward);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'outboundPostcode' => ['This outbound postcode is already mapped.'],
            ]);
        }
    }

    private function nextSortOrder(): int
    {
        $max = OutboundPostcodeArea::query()->max('sort_order');

        return is_numeric($max) ? ((int) $max) + 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(
        OutboundPostcodeArea $area,
        User $actor,
        string $action,
        array $changes,
    ): void {
        OutboundPostcodeAreaAudit::query()->create([
            'outbound_postcode_area_id' => $area->id,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'changes' => $changes,
        ]);
    }

    private function lookupCacheKey(string $outward): string
    {
        return 'postcode-area:'.$outward;
    }

    private function forgetLookupCache(string $outward): void
    {
        Cache::forget($this->lookupCacheKey($outward));
    }

    private function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
