<?php

namespace App\Services\Shifts;

use App\Services\ThreeRings\Data\Shift;
use App\Services\ThreeRings\Exceptions\ThreeRingsException;
use App\Services\ThreeRings\ThreeRingsClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out the Three Rings shift type (for example Milk, Sepsis or Swansea Bay)
 * to show against a rider who logs on. Read-only: it only reads rota data from
 * Three Rings and never fails the caller, because the label is a nicety.
 */
final class ShiftTypeResolver
{
    public const SOURCE_ROTA = 'rota';

    public const SOURCE_TITLE = 'title';

    /**
     * How far either side of the logon time to look. A shift can span midnight
     * (for example 19:00 + 12h), so the lookup is a window around the logon
     * time rather than a calendar day.
     */
    private const WINDOW_HOURS = 24;

    /** Upper bound on the Three Rings call, which runs inside the logon request. */
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly ThreeRingsClient $threeRings) {}

    /**
     * @param  int  $externalId  The rider's Three Rings volunteer id.
     */
    public function resolve(int $externalId, CarbonInterface $at): ?string
    {
        // Shift::volunteerIds uses 0 for an entry without an id, so never match on it.
        if ($externalId <= 0) {
            return null;
        }

        try {
            $at = CarbonImmutable::instance($at)->utc();
            $from = $at->subHours(self::WINDOW_HOURS);
            $to = $at->addHours(self::WINDOW_HOURS);

            $shift = $this->pickShift(
                $this->threeRings->shifts($from, $to, self::TIMEOUT_SECONDS)
                    ->filter(fn (Shift $shift): bool => in_array($externalId, $shift->volunteerIds, true))
                    ->all(),
                $at,
            );

            return $shift === null ? null : $this->label($shift);
        } catch (ThreeRingsException $exception) {
            Log::warning('Could not look up the Three Rings shift type; logging on without one.', [
                'external_id' => $externalId,
                'exception' => $exception,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unexpected error looking up the Three Rings shift type; logging on without one.', [
                'external_id' => $externalId,
                'exception' => $exception,
            ]);
        }

        return null;
    }

    /**
     * The shift whose start..end window contains the time, otherwise the next
     * one to start (within the lookup window). A shift that has already ended
     * is never chosen: a rider who is not rostered now gets no type.
     *
     * @param  list<Shift>  $shifts
     */
    private function pickShift(array $shifts, CarbonImmutable $at): ?Shift
    {
        $windowSeconds = self::WINDOW_HOURS * 3600;

        $candidates = [];

        foreach ($shifts as $shift) {
            $untilStart = $shift->startsAt->getTimestamp() - $at->getTimestamp();
            $contains = $untilStart <= 0 && $shift->endsAt !== null && $at < $shift->endsAt;
            $upcoming = $untilStart > 0 && $untilStart <= $windowSeconds;

            if ($contains || $upcoming) {
                $candidates[] = ['shift' => $shift, 'contains' => $contains, 'distance' => abs($untilStart)];
            }
        }

        usort($candidates, fn (array $a, array $b): int => [! $a['contains'], $a['distance'], $a['shift']->id]
            <=> [! $b['contains'], $b['distance'], $b['shift']->id]);

        return $candidates[0]['shift'] ?? null;
    }

    private function label(Shift $shift): ?string
    {
        $source = strtolower(trim((string) config('services.three_rings.shift_type_source', self::SOURCE_ROTA)));

        // Anything other than `title` (including a typo) uses the rota name.
        $label = trim($source === self::SOURCE_TITLE ? $shift->title : $shift->rota);

        return $label === '' ? null : $label;
    }
}
