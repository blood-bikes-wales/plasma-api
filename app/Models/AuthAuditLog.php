<?php

namespace App\Models;

use App\Enums\AuthFailureReason;
use Database\Factories\AuthAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'hosted_domain', 'reason', 'ip_address', 'user_agent'])]
class AuthAuditLog extends Model
{
    /** @use HasFactory<AuthAuditLogFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AuthFailureReason::class,
        ];
    }
}
