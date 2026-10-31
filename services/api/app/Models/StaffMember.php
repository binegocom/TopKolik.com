<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class StaffMember extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'mfa_secret', 'mfa_pending_secret', 'mfa_setup_token_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_pending_secret' => 'encrypted',
            'mfa_setup_expires_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'mfa_last_step' => 'integer',
            'email_verified_at' => 'immutable_datetime',
            'mfa_enrolled_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }
}
