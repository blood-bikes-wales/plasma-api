<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Http\Middleware\AttachUserRoles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<Role>|null $roles */
        $roles = $request->attributes->get(AttachUserRoles::ATTRIBUTE);

        return [
            'id' => $this->id,
            'google_id' => $this->google_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'is_admin' => (bool) $this->is_admin,
            'roles' => array_map(
                static fn (Role $role): string => $role->value,
                is_array($roles) ? $roles : [],
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
