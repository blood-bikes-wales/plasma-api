<?php

namespace Tests\Unit\Services\ThreeRings;

use App\Services\ThreeRings\Data\Shift;
use PHPUnit\Framework\TestCase;

class ShiftTest extends TestCase
{
    public function test_from_array_maps_recorded_shape_with_duration_and_volunteer_shifts(): void
    {
        $shift = Shift::fromArray([
            'id' => 900001,
            'title' => 'Night Cover',
            'start_datetime' => '2026-07-24T19:00:00.000Z',
            'duration' => 43200,
            'all_day' => false,
            'rota' => ['id' => 7001, 'name' => 'North Wales', 'org' => ['id' => 42, 'name' => 'Blood Bikes Wales']],
            'volunteer_shifts' => [
                ['id' => 800001, 'volunteer' => ['id' => 100001, 'name' => 'Alex Morgan'], 'put_for_swap_at' => null],
                ['id' => 800002, 'volunteer' => ['id' => 100002, 'name' => 'Bethan Hughes'], 'put_for_swap_at' => null],
            ],
        ]);

        $this->assertSame(900001, $shift->id);
        $this->assertSame('Night Cover', $shift->title);
        $this->assertSame('North Wales', $shift->rota);
        $this->assertSame('2026-07-24T19:00:00+00:00', $shift->startsAt->toIso8601String());
        $this->assertSame('2026-07-25T07:00:00+00:00', $shift->endsAt?->toIso8601String());
        $this->assertSame([100001, 100002], $shift->volunteerIds);
        $this->assertSame(['Alex Morgan', 'Bethan Hughes'], $shift->volunteerNames);
    }

    public function test_from_array_handles_wrapped_shift_with_end_datetime_and_plain_volunteers(): void
    {
        $shift = Shift::fromArray([
            'shift' => [
                'id' => 900003,
                'title' => 'Evening Cover',
                'rota' => 'Mid Wales',
                'start_datetime' => '2026-07-25T15:00:00+01:00',
                'end_datetime' => '2026-07-25T19:00:00+01:00',
                'volunteers' => [
                    ['id' => 100003, 'name' => 'Carys Evans'],
                ],
            ],
        ]);

        $this->assertSame('Evening Cover', $shift->title);
        $this->assertSame('Mid Wales', $shift->rota);
        $this->assertSame('2026-07-25T19:00:00+01:00', $shift->endsAt?->toIso8601String());
        $this->assertSame([100003], $shift->volunteerIds);
        $this->assertSame(['Carys Evans'], $shift->volunteerNames);
    }

    public function test_from_array_handles_missing_optional_fields(): void
    {
        $shift = Shift::fromArray([
            'id' => 900002,
            'title' => 'Day Cover',
            'rota' => 'South Wales',
            'start_datetime' => '2026-07-25T07:00:00.000Z',
        ]);

        $this->assertSame('Day Cover', $shift->title);
        $this->assertSame('South Wales', $shift->rota);
        $this->assertNull($shift->endsAt);
        $this->assertSame([], $shift->volunteerIds);
        $this->assertSame([], $shift->volunteerNames);
    }
}
