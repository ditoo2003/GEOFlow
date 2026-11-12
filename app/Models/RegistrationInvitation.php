<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 邀请制注册链接：只存 token 哈希，明文仅在生成时展示一次。
 */
class RegistrationInvitation extends Model
{
    protected $table = 'registration_invitations';

    protected $fillable = [
        'token_hash',
        'email',
        'tenant_name',
        'note',
        'created_by_admin_id',
        'expires_at',
        'used_at',
        'used_tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_by_admin_id' => 'integer',
            'used_tenant_id' => 'integer',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'used';
        }

        return $this->isUsable() ? 'active' : 'expired';
    }
}
