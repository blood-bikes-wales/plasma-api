<?php

namespace App\Services\ThreeRings\Data;

use Illuminate\Support\Str;

final readonly class Volunteer
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public array $roles,
        public array $properties,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $properties = self::mapProperties($data['volunteer_properties'] ?? []);

        return new self(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            email: self::emailFrom($properties),
            roles: self::mapRoleNames($data['roles'] ?? []),
            properties: $properties,
        );
    }

    /**
     * Flatten Three Rings' nested volunteer_properties entries into a
     * name => value map, tolerating both wrapped and unwrapped entries.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private static function mapProperties(array $entries): array
    {
        $properties = [];

        foreach ($entries as $entry) {
            $property = $entry['volunteer_property'] ?? $entry;

            if (isset($property['name'])) {
                $properties[(string) $property['name']] = $property['value'] ?? null;
            }
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function emailFrom(array $properties): ?string
    {
        foreach ($properties as $name => $value) {
            if (Str::contains($name, 'email', ignoreCase: true) && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $entries
     * @return list<string>
     */
    private static function mapRoleNames(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $names[] = $entry;

                continue;
            }

            $role = $entry['role'] ?? $entry;

            if (isset($role['name'])) {
                $names[] = (string) $role['name'];
            }
        }

        return $names;
    }
}
