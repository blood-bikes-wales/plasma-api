<?php

namespace App\Http\Resources;

use App\Authorization\Capability;
use App\Authorization\RequestRoles;
use App\Models\JobContentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pickers (controllers) only need `id` and `name`. `active` and `sortOrder` are
 * management details, so they are only returned to callers who can manage the list.
 *
 * @mixin JobContentType
 */
class JobContentTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            $this->mergeWhen(
                RequestRoles::hasCapability($request, Capability::ManageJobContents),
                fn (): array => [
                    'active' => $this->active,
                    'sortOrder' => $this->sort_order,
                ],
            ),
        ];
    }
}
