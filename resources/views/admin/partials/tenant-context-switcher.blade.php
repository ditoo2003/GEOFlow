@php
    $tenantContextOptions = \App\Support\AdminTenantContext::options();
    $tenantContextSelected = \App\Support\AdminTenantContext::selectedTenantId();
@endphp
@if (count($tenantContextOptions) > 1)
    <form method="POST" action="{{ \App\Support\AdminWeb::routePath('admin.tenant-context.switch') }}" class="px-4 py-2" data-no-unsaved>
        @csrf
        <label for="admin-tenant-context" class="block text-xs font-medium text-gray-500 mb-1">
            {{ __('admin.tenant_context.label') }}
        </label>
        <select id="admin-tenant-context" name="tenant_id" class="w-full text-sm text-gray-700 border border-gray-200 rounded-md px-2 py-1" onchange="this.form.submit()">
            <option value="">{{ __('admin.tenant_context.platform') }}</option>
            @foreach ($tenantContextOptions as $tenantContextId => $tenantContextName)
                <option value="{{ $tenantContextId }}" @selected($tenantContextSelected === (int) $tenantContextId)>{{ $tenantContextName }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-[11px] leading-snug text-gray-400">{{ __('admin.tenant_context.hint') }}</p>
    </form>
@endif
