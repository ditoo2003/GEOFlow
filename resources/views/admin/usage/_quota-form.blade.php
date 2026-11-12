@php
    $formPlan = is_array($formPlan ?? null) ? $formPlan : [];
    $models = $models ?? collect();
    $plans = $plans ?? [];
@endphp

@if ($formTenant === null)
    <p class="text-sm text-gray-500">{{ __('admin.usage.quota_form.unavailable') }}</p>
@else
    <form method="POST" action="{{ route('admin.usage.quota.update', ['tenantId' => $formTenant->id]) }}" class="space-y-4" data-usage-quota-form="{{ $formTenant->id }}">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-gray-700" for="plan_code_{{ $formTenant->id }}">{{ __('admin.usage.quota_form.plan') }}</label>
            <select id="plan_code_{{ $formTenant->id }}" name="plan_code" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                @foreach ($plans as $code => $definition)
                    <option value="{{ $code }}" @selected(($formPlan['code'] ?? null) === $code)>{{ $definition['label'] ?? $code }}</option>
                @endforeach
            </select>
            @error('plan_code')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium text-gray-700" for="site_limit_{{ $formTenant->id }}">{{ __('admin.usage.quota_form.site_limit') }}</label>
                <input id="site_limit_{{ $formTenant->id }}" name="site_limit" type="number" min="0" value="{{ old('site_limit') }}" placeholder="{{ (int) ($formPlan['site_limit'] ?? 0) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                @error('site_limit')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700" for="article_quota_{{ $formTenant->id }}">{{ __('admin.usage.quota_form.article_quota') }}</label>
                <input id="article_quota_{{ $formTenant->id }}" name="article_quota" type="number" min="0" value="{{ old('article_quota') }}" placeholder="{{ (int) ($formPlan['article_quota'] ?? 0) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                @error('article_quota')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700" for="token_budget_{{ $formTenant->id }}">{{ __('admin.usage.quota_form.token_budget') }}</label>
                <input id="token_budget_{{ $formTenant->id }}" name="token_budget" type="number" min="0" value="{{ old('token_budget') }}" placeholder="{{ (int) ($formPlan['token_budget'] ?? 0) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                @error('token_budget')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="downgrade_model_id_{{ $formTenant->id }}">{{ __('admin.usage.quota_form.downgrade_model') }}</label>
            <select id="downgrade_model_id_{{ $formTenant->id }}" name="downgrade_model_id" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">{{ __('admin.usage.quota_form.inherit') }}</option>
                @foreach ($models as $model)
                    <option value="{{ $model->id }}" @selected((int) old('downgrade_model_id') === (int) $model->id)>{{ $model->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ __('admin.usage.quota_form.downgrade_hint') }}</p>
            @error('downgrade_model_id')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">
            {{ __('admin.usage.quota_form.save') }}
        </button>
    </form>
@endif
