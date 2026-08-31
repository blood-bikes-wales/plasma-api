<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Controller = 'controller';
    case Driver = 'driver';
    case Rider = 'rider';
    case Trustee = 'trustee';

    /**
     * Map a Three Rings directory role name to a Plasma role.
     *
     * Unknown names (Everyone, Statistics, …) return null.
     * Three Rings uses "Trustees" (plural); both singular and plural map.
     */
    public static function tryFromThreeRingsName(string $name): ?self
    {
        $normalised = mb_strtolower(trim($name));

        if ($normalised === 'controller') {
            return self::Controller;
        }

        if (str_starts_with($normalised, 'rider')) {
            return self::Rider;
        }

        if (str_starts_with($normalised, 'driver')) {
            return self::Driver;
        }

        if (str_starts_with($normalised, 'trustee')) {
            return self::Trustee;
        }

        return null;
    }

    /**
     * Expand hierarchy: admin includes every other Plasma role.
     *
     * @param  list<self>  $roles
     * @return list<self>
     */
    public static function expand(array $roles): array
    {
        $withImplied = array_map(
            static fn (self $role): array => [$role, ...self::impliedBy($role)],
            $roles,
        );

        return self::uniqueSorted(array_merge([], ...$withImplied));
    }

    /**
     * @return list<self>
     */
    public static function impliedBy(self $role): array
    {
        if ($role !== self::Admin) {
            return [];
        }

        return [
            self::Controller,
            self::Driver,
            self::Rider,
            self::Trustee,
        ];
    }

    /**
     * Stable display order: admin first, then alphabetical by value.
     *
     * @param  list<self>  $roles
     * @return list<self>
     */
    public static function uniqueSorted(array $roles): array
    {
        $unique = [];

        foreach ($roles as $role) {
            $unique[$role->value] = $role;
        }

        $values = array_values($unique);

        usort($values, function (self $a, self $b): int {
            if ($a === self::Admin) {
                return -1;
            }

            if ($b === self::Admin) {
                return 1;
            }

            return $a->value <=> $b->value;
        });

        return $values;
    }
}
