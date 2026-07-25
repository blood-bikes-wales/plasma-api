<?php

namespace App\Services\ThreeRings\Data;

use Carbon\CarbonImmutable;

final readonly class Shift
{
    /**
     * @param  list<int>  $volunteerIds
     * @param  list<string>  $volunteerNames
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $rota,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public array $volunteerIds,
        public array $volunteerNames,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $shift = $data['shift'] ?? $data;

        $rota = $shift['rota'] ?? '';
        $volunteers = array_map(
            fn (array $entry): array => $entry['volunteer'] ?? $entry,
            $shift['volunteer_shifts'] ?? $shift['volunteers'] ?? [],
        );

        $startsAt = CarbonImmutable::parse($shift['start_datetime']);

        $endsAt = match (true) {
            isset($shift['end_datetime']) => CarbonImmutable::parse($shift['end_datetime']),
            isset($shift['duration']) => $startsAt->addSeconds((int) $shift['duration']),
            default => null,
        };

        return new self(
            id: (int) ($shift['id'] ?? 0),
            title: (string) ($shift['title'] ?? ''),
            rota: is_array($rota) ? (string) ($rota['name'] ?? '') : (string) $rota,
            startsAt: $startsAt,
            endsAt: $endsAt,
            volunteerIds: array_values(array_map(fn (array $volunteer): int => (int) ($volunteer['id'] ?? 0), $volunteers)),
            volunteerNames: array_values(array_map(fn (array $volunteer): string => (string) ($volunteer['name'] ?? ''), $volunteers)),
        );
    }
}
