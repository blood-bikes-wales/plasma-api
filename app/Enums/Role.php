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
        return match (mb_strtolower(trim($name))) {
            'controller' => self::Controller,
            'driver' => self::Driver,
            'rider' => self::Rider,
            'trustee', 'trustees' => self::Trustee,
            default => null,
        };
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
