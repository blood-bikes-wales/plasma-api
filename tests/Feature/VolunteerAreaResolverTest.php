<?php

namespace Tests\Feature;

use App\Models\ServiceArea;
use App\Services\Shifts\VolunteerAreaResolver;
use App\Services\ThreeRings\Data\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolunteerAreaResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceArea::query()->create(['code' => 'E', 'full_name' => 'East', 'three_rings_role' => 'Rider - East']);
        ServiceArea::query()->create(['code' => 'C', 'full_name' => 'Carms', 'three_rings_role' => 'Rider - Carms']);
        ServiceArea::query()->create(['code' => 'S', 'full_name' => 'Swansea', 'three_rings_role' => 'Rider - Swansea Bay\\West']);
        ServiceArea::query()->create(['code' => 'X', 'full_name' => 'Unmapped']);
    }

    public function test_returns_the_code_for_a_single_area_role(): void
    {
        $this->assertSame(['E'], $this->codesFor(['Everyone', 'Rider - East']));
    }

    public function test_returns_every_code_for_a_multi_area_rider(): void
    {
        $this->assertSame(['E', 'C'], $this->codesFor(['Rider - East', 'Rider - Carms']));
    }

    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(['E', 'C'], $this->codesFor(['rider - east', '  RIDER - CARMS ']));
    }

    public function test_matches_a_role_name_containing_a_backslash(): void
    {
        $this->assertSame(['S'], $this->codesFor(['Rider - Swansea Bay\\West']));
    }

    public function test_ignores_job_type_and_unrelated_roles(): void
    {
        $this->assertSame([], $this->codesFor(['Rider - Milk', 'Rider - Chemo', 'Controller', 'Rota manager - East']));
    }

    public function test_a_volunteer_with_no_roles_has_no_areas(): void
    {
        $this->assertSame([], $this->codesFor([]));
    }

    public function test_a_repeated_role_returns_the_code_once(): void
    {
        $this->assertSame(['E'], $this->codesFor(['Rider - East', 'rider - east']));
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    private function codesFor(array $roleNames): array
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => array_map(
                fn (string $name, int $index): array => ['role' => ['id' => 5000 + $index, 'name' => $name]],
                $roleNames,
                array_keys($roleNames),
            ),
            'volunteer_properties' => [],
        ]);

        return (new VolunteerAreaResolver)->codesFor($volunteer);
    }
}
