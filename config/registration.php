<?php

/**
 * 自助注册。
 *
 * `mode` 二选一，两种模式共用同一条注册与租户创建链路：
 * - invite（默认）：管理员生成邀请链接，邀请本身即校验，注册不要求邮箱验证；
 * - public：公开注册，必须完成邮箱验证后才创建租户，并叠加 IP/邮箱限流、
 *   一次性邮箱域名黑名单与可选 Turnstile。
 *
 * 不支持"公开注册 + 人工审核"模式。
 */
return [
    'mode' => env('REGISTRATION_MODE', 'invite'),

    // 邀请链接有效期（天）与单次使用。
    'invitation_ttl_days' => (int) env('REGISTRATION_INVITATION_TTL_DAYS', 7),

    // 公开注册：验证链接有效期与迁移数。
    'verification_ttl_minutes' => (int) env('REGISTRATION_VERIFICATION_TTL_MINUTES', 60),

    'ip_limit_per_hour' => (int) env('REGISTRATION_IP_LIMIT_PER_HOUR', 5),

    'email_limit_per_day' => (int) env('REGISTRATION_EMAIL_LIMIT_PER_DAY', 3),

    /*
     * 一次性邮箱域名黑名单（精确匹配邮箱域名）。
     * 可按需追加；不在此清单中的域名默认可注册。
     */
    'disposable_domains' => [
        '10minutemail.com',
        'dispostable.com',
        'fakeinbox.com',
        'getnada.com',
        'guerrillamail.com',
        'maildrop.cc',
        'mailinator.com',
        'sharklasers.com',
        'temp-mail.org',
        'tempmail.com',
        'trashmail.com',
        'yopmail.com',
    ],

    'turnstile' => [
        'enabled' => (bool) env('REGISTRATION_TURNSTILE_ENABLED', false),
        'site_key' => env('REGISTRATION_TURNSTILE_SITE_KEY'),
        'secret_key' => env('REGISTRATION_TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],
];
