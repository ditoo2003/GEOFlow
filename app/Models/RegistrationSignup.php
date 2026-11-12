<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 公开注册的待验证申请：邮箱验证通过后才创建租户与管理员。
 */
class RegistrationSignup extends Model
{
    protected $table = 'registration_signups';

    protected $fillable = [
        'email',
        'name',
        'tenant_name',
        'password',
        'verification_token_hash',
        'sent_count',
        'last_sent_at',
        'expires_at',
        'consumed_at',
        'ip_address',
        'locale',
    ];

    protected $hidden = ['password', 'verification_token_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'sent_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
