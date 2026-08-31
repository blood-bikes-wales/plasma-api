<?php

namespace App\Services\Roles;

use App\Enums\Role;
use App\Models\User;
use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsException;
use App\Services\ThreeRings\ThreeRingsClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve effective Plasma roles for a user.
 *
 * Three Rings directory roles are mapped via {@see Role::tryFromThreeRingsName};
 * local {@see User::$is_admin} adds {@see Role::Admin}. Directory data is
 * served through the client's fresh/stale cache so /me does not hit 3R on
 * every request.
 */
final class UserRoleResolver
{
    public function __construct(
        private readonly ThreeRingsClient $threeRings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<Role>
     */
    public function forUser(User $user): array
    {
        $roles = [];

        if ($user->is_admin) {
            $roles[] = Role::Admin;
        }

        $volunteer = $this->findVolunteerByEmail($user->email);

        if ($volunteer !== null) {
            foreach ($volunteer->roles as $name) {
                $mapped = Role::tryFromThreeRingsName($name);

                if ($mapped !== null) {
                    $roles[] = $mapped;

                    continue;
                }

                $this->logger->debug('Ignoring unmapped Three Rings role.', [
                    'email' => $user->email,
                    'role' => $name,
                ]);
            }
        }

        return Role::uniqueSorted($roles);
    }

    /**
     * @return list<string>
     */
    public function valuesForUser(User $user): array
    {
        return array_map(
            static fn (Role $role): string => $role->value,
            $this->forUser($user),
        );
    }

    private function findVolunteerByEmail(string $email): ?Volunteer
    {
        try {
            $volunteers = $this->threeRings->volunteers();
        } catch (ThreeRingsException $exception) {
            $this->logger->warning('Three Rings directory unavailable while resolving roles.', [
                'email' => $email,
                'exception' => $exception,
            ]);

            return null;
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected failure resolving Three Rings roles.', [
                'email' => $email,
                'exception' => $exception,
            ]);

            return null;
        }

        return $volunteers->first(
            static fn (Volunteer $volunteer): bool => $volunteer->matchesEmail($email),
        );
    }
}
