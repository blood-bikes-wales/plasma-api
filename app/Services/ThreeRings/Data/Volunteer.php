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
            email: self::primaryEmailFrom($properties),
            roles: self::mapRoleNames($data['roles'] ?? []),
            properties: $properties,
        );
    }

    public function matchesEmail(string $email): bool
    {
        $needle = Str::lower(trim($email));

        foreach (['email', 'email_alt'] as $code) {
            $value = $this->properties[$code] ?? null;

            if (is_string($value) && Str::lower($value) === $needle) {
                return true;
            }
        }

        foreach ($this->properties as $name => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (! Str::contains((string) $name, 'email', ignoreCase: true)) {
                continue;
            }

            if (Str::lower($value) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function area(): ?string
    {
        return self::propertyMatching($this->properties, 'area');
    }

    public function phone(): ?string
    {
        $phone = self::propertyMatching($this->properties, 'telephone');
        if ($phone !== null) {
            return $phone;
        }

        return self::propertyMatching($this->properties, 'phone');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function propertyMatching(array $properties, string $needle): ?string
    {
        foreach ($properties as $name => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (! Str::contains(Str::lower((string) $name), Str::lower($needle))) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Flatten Three Rings' nested volunteer_properties entries into a
     * code/name => value map, tolerating wrapped, flat, and code-keyed entries.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private static function mapProperties(array $entries): array
    {
        $properties = [];

        foreach ($entries as $entry) {
            if (isset($entry['volunteer_property']) && is_array($entry['volunteer_property'])) {
                self::storeProperty($properties, $entry['volunteer_property']);

                continue;
            }

            if (isset($entry['name']) || isset($entry['code'])) {
                self::storeProperty($properties, $entry);

                continue;
            }

            foreach ($entry as $code => $meta) {
                if (! is_array($meta) || ! array_key_exists('value', $meta)) {
                    continue;
                }

                $properties[(string) $code] = $meta['value'];
            }
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $property
     */
    private static function storeProperty(array &$properties, array $property): void
    {
        $value = $property['value'] ?? null;

        if (isset($property['code'])) {
            $properties[(string) $property['code']] = $value;
        }

        if (isset($property['name'])) {
            $properties[(string) $property['name']] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function primaryEmailFrom(array $properties): ?string
    {
        $email = $properties['email'] ?? null;

        if (is_string($email) && $email !== '') {
            return $email;
        }

        foreach ($properties as $name => $value) {
            if ($name === 'email_alt') {
                continue;
            }

            if (Str::contains((string) $name, 'email', ignoreCase: true) && is_string($value) && $value !== '') {
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
