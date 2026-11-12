<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminActivityLogger;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 平台级品牌设置（一份部署一套品牌，仅超管可改）。
 *
 * 只允许改名称、Logo、主色、支持邮箱、源码地址与版权；
 * 许可证固定为 AGPL，源码入口不可留空，也没有关闭开关。
 */
class BrandingSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.branding.index', [
            'pageTitle' => __('platform_admin.branding.title'),
            'activeMenu' => 'branding_settings',
            'brand' => Branding::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'primary_color' => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'support_email' => ['nullable', 'email', 'max:100'],
            'source_url' => ['required', 'url', 'max:500'],
            'copyright' => ['required', 'string', 'max:200'],
        ], [
            'primary_color.regex' => __('platform_admin.branding.errors.color'),
            'source_url.required' => __('platform_admin.branding.errors.source_required'),
        ]);

        Branding::save([
            'name' => (string) $data['name'],
            'logo_url' => (string) ($data['logo_url'] ?? ''),
            'primary_color' => (string) $data['primary_color'],
            'support_email' => (string) ($data['support_email'] ?? ''),
            'source_url' => (string) $data['source_url'],
            'copyright' => (string) $data['copyright'],
        ]);

        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'branding:update', [
            'name' => $data['name'],
            'source_url' => $data['source_url'],
        ]);

        return back()->with('status', __('platform_admin.branding.updated'));
    }
}
