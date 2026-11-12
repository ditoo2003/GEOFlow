<?php

namespace App\Support;

use App\Models\SystemState;
use Throwable;

/**
 * 平台级品牌解析：`config/branding.php` 为默认值，后台可在 `system_states.branding` 覆盖。
 *
 * 品牌只覆盖名称、Logo、主色、支持邮箱、发件人名称与邮件模板变量。
 * `source_url`（AGPL「获取源代码」入口）永远有值，且没有关闭开关。
 */
final class Branding
{
    public const STATE_KEY = 'branding';

    /** @var array<string, string>|null */
    private static ?array $resolved = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $values = [
            'name' => (string) config('branding.name', 'GEOFlow'),
            'logo_url' => (string) config('branding.logo_url', ''),
            'primary_color' => (string) config('branding.primary_color', '#2563eb'),
            'support_email' => (string) config('branding.support_email', ''),
            'source_url' => (string) config('branding.source_url', 'https://github.com/ditoo2003/GEOFlow'),
            'copyright' => (string) config('branding.copyright', 'GEOFlow contributors'),
            'license' => (string) config('branding.license', 'AGPL-3.0-or-later'),
        ];

        $stored = self::stored();
        foreach ($values as $key => $default) {
            $value = $stored[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        // AGPL 红线：源码入口与许可证不可为空。
        if (trim($values['source_url']) === '') {
            $values['source_url'] = 'https://github.com/ditoo2003/GEOFlow';
        }
        if (trim($values['license']) === '') {
            $values['license'] = 'AGPL-3.0-or-later';
        }
        if (trim($values['name']) === '') {
            $values['name'] = 'GEOFlow';
        }

        return self::$resolved = $values;
    }

    public static function name(): string
    {
        return self::all()['name'];
    }

    public static function logoUrl(): ?string
    {
        $url = self::all()['logo_url'];

        return $url !== '' ? $url : null;
    }

    public static function primaryColor(): string
    {
        return self::all()['primary_color'];
    }

    public static function supportEmail(): ?string
    {
        $email = self::all()['support_email'];

        return $email !== '' ? $email : null;
    }

    public static function sourceUrl(): string
    {
        return self::all()['source_url'];
    }

    public static function copyright(): string
    {
        return self::all()['copyright'];
    }

    public static function license(): string
    {
        return self::all()['license'];
    }

    /**
     * 写入覆盖值；空字符串表示回到环境变量默认。
     *
     * @param array<string, string|null> $values
     */
    public static function save(array $values): void
    {
        $current = self::stored();
        foreach ($values as $key => $value) {
            $current[$key] = is_string($value) ? trim($value) : '';
        }

        SystemState::query()->updateOrCreate(
            ['key' => self::STATE_KEY],
            ['value' => $current],
        );

        self::$resolved = null;
    }

    public static function flush(): void
    {
        self::$resolved = null;
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        try {
            $state = SystemState::query()->where('key', self::STATE_KEY)->first();
        } catch (Throwable) {
            return [];
        }

        $value = $state?->value;

        return is_array($value) ? $value : [];
    }
}
