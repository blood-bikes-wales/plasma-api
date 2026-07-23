<?php

namespace App\Services\ThreeRings\Data;

final readonly class Role
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $role = $data['role'] ?? $data;

        return new self(
            id: (int) ($role['id'] ?? 0),
            name: (string) ($role['name'] ?? ''),
        );
    }
}
