<?php

/**
 * 平台级品牌（一份部署一套品牌）。
 *
 * 后台「品牌与注册」页可覆盖这里的值（存 `system_states.branding`），
 * 环境变量是默认值。品牌仅影响名称、Logo、主色、支持邮箱、发件人名称与邮件模板变量；
 * 不提供每租户独立品牌、自定义域名或自由编辑邮件 HTML。
 *
 * AGPL 红线：`source_url` 用于「获取源代码」入口，必须始终可访问且不能留空；
 * 品牌配置不会（也不能）移除许可证/版权声明与源码入口。
 */
return [
    'name' => env('BRAND_NAME', env('APP_NAME', 'GEOFlow')),

    'logo_url' => env('BRAND_LOGO_URL'),

    'primary_color' => env('BRAND_PRIMARY_COLOR', '#2563eb'),

    'support_email' => env('BRAND_SUPPORT_EMAIL'),

    'source_url' => env('BRAND_SOURCE_URL', 'https://github.com/ditoo2003/GEOFlow'),

    'copyright' => env('BRAND_COPYRIGHT', 'GEOFlow contributors'),

    'license' => 'AGPL-3.0-or-later',
];
