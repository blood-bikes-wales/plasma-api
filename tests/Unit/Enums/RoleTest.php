<?php

namespace Tests\Unit\Enums;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleTest extends TestCase
{
    #[DataProvider('threeRingsNameProvider')]
    public function test_try_from_three_rings_name(string $name, ?Role $expected): void
    {
        $this->assertSame($expected, Role::tryFromThreeRingsName($name));
    }

    /**
     * @return array<string, array{0: string, 1: ?Role}>
     */
    public static function threeRingsNameProvider(): array
    {
        return [
            'controller' => ['Controller', Role::Controller],
            'controller lowercase' => ['controller', Role::Controller],
            'rider' => ['Rider', Role::Rider],
            'driver padded' => ['  Driver  ', Role::Driver],
            'trustees plural' => ['Trustees', Role::Trustee],
            'trustee singular' => ['Trustee', Role::Trustee],
            'everyone ignored' => ['Everyone', null],
            'statistics ignored' => ['Statistics', null],
            'empty ignored' => ['', null],
        ];
    }

    public function test_expand_grants_admin_every_other_role(): void
    {
        $this->assertSame(
            [Role::Admin, Role::Controller, Role::Driver, Role::Rider, Role::Trustee],
            Role::expand([Role::Admin]),
        );
    }

    public function test_expand_leaves_non_admin_roles_unchanged(): void
    {
        $this->assertSame(
            [Role::Controller, Role::Rider],
            Role::expand([Role::Rider, Role::Controller]),
        );
    }

    public function test_unique_sorted_puts_admin_first_then_alphabetical(): void
    {
        $sorted = Role::uniqueSorted([
            Role::Rider,
            Role::Admin,
            Role::Trustee,
            Role::Controller,
            Role::Rider,
            Role::Driver,
        ]);

        $this->assertSame(
            [Role::Admin, Role::Controller, Role::Driver, Role::Rider, Role::Trustee],
            $sorted,
        );
    }
}
