@extends('admin.layouts.app')

@php
    $isGlobal = ($scope ?? 'tenant') === 'global';
    $summary = is_array($summary ?? null) ? $summary : [];
    $alerts = $alerts ?? collect();
    $recentEvents = $recentEvents ?? collect();
    $rows = $rows ?? [];
    $models = $models ?? collect();
    $plans = $plans ?? [];
    $singleTenant = (bool) ($singleTenant ?? false);
    $reconciliation = is_array($reconciliation ?? null) ? $reconciliation : null;
    $periods = $periods ?? [];

    $levelLabels = [
        \App\Models\TenantQuotaAlert::LEVEL_BLOCK => __('admin.usage.alerts.level.block'),
        \App\Models\TenantQuotaAlert::LEVEL_DEGRADE => __('admin.usage.alerts.level.degrade'),
        \App\Models\TenantQuotaAlert::LEVEL_DEGRADE_UNAVAILABLE => __('admin.usage.alerts.level.degrade_unavailable'),
    ];
    $levelTones = [
        \App\Models\TenantQuotaAlert::LEVEL_BLOCK => 'bg-red-50 text-red-700 border-red-200',
        \App\Models\TenantQuotaAlert::LEVEL_DEGRADE => 'bg-amber-50 text-amber-700 border-amber-200',
        \App\Models\TenantQuotaAlert::LEVEL_DEGRADE_UNAVAILABLE => 'bg-gray-50 text-gray-700 border-gray-200',
    ];
    $pressureTones = [
        'normal' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'degrade' => 'border-amber-200 bg-amber-50 text-amber-900',
        'block' => 'border-red-200 bg-red-50 text-red-900',
    ];
    $percent = function (int $used, int $limit, bool $configured): ?int {
        if (! $configured) {
            return null;
        }

        return $limit > 0 ? (int) min(100, round($used / $limit * 100)) : 100;
    };
    $articleQuota = (int) ($summary['article_quota'] ?? 0);
    $tokenBudget = (int) ($summary['token_budget'] ?? 0);
    $cards = [
        [
            'key' => 'articles',
            'used' => (int) ($summary['article_count'] ?? 0),
            'limit' => (int) ($summary['article_effective_quota'] ?? 0),
            'configured' => $articleQuota > 0,
            'carryover' => (int) ($summary['article_carryover_in'] ?? 0),
            'tone' => 'bg-blue-600',
            'icon' => 'file-check-2',
            'label' => __('admin.usage.metrics.articles'),
        ],
        [
            'key' => 'sites',
            'used' => (int) ($summary['site_count'] ?? 0),
            'limit' => (int) ($summary['site_limit'] ?? 0),
            'configured' => (int) ($summary['site_limit'] ?? 0) > 0,
            'carryover' => 0,
            'tone' => 'bg-cyan-600',
            'icon' => 'globe',
            'label' => __('admin.usage.metrics.sites'),
        ],
        [
            'key' => 'tokens',
            'used' => (int) ($summary['token_usage'] ?? 0),
            'limit' => (int) ($summary['token_effective_budget'] ?? 0),
            'configured' => $tokenBudget > 0,
            'carryover' => (int) ($summary['token_carryover_in'] ?? 0),
            'tone' => 'bg-violet-600',
            'icon' => 'coins',
            'label' => __('admin.usage.metrics.tokens'),
        ],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-usage-scope="{{ $isGlobal ? 'global' : 'tenant' }}" @unless ($isGlobal) data-usage-tenant="{{ $tenantId }}" @endunless>
        <header class="mb-8">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="break-words text-3xl font-bold tracking-tight text-gray-950 text-balance">{{ $pageTitle }}</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.usage.subtitle') }}</p>
                </div>
                <div class="text-sm text-gray-500">{{ __('admin.usage.period', ['period' => $period]) }}</div>
            </div>
            @if (! $isGlobal && ! $singleTenant && $tenant !== null)
                <p class="mt-3 inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600">
                    <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                    {{ $tenant->name }}
                    @if ($plan !== null)
                        <span class="text-gray-400">·</span>
                        {{ $plan['label'] }}
                    @endif
                </p>
            @endif
        </header>

        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        @if ($isGlobal)
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="usage-tenants-title">
                <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                    <h2 id="usage-tenants-title" class="text-lg font-semibold text-gray-950">{{ __('admin.usage.tenants.title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.usage.tenants.subtitle') }}</p>
                </div>

                @if (count($rows) === 0)
                    <p class="px-5 py-8 text-center text-sm text-gray-500 sm:px-6">{{ __('admin.usage.tenants.empty') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-3 sm:px-6">{{ __('admin.usage.tenants.tenant') }}</th>
                                    <th class="px-4 py-3">{{ __('admin.usage.tenants.plan') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('admin.usage.tenants.sites') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('admin.usage.tenants.articles') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('admin.usage.tenants.tokens') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('admin.usage.tenants.cost') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('admin.usage.tenants.alerts') }}</th>
                                    <th class="px-5 py-3 sm:px-6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rows as $row)
                                    @php
                                        $rowSummary = $row['summary'] ?? [];
                                        $rowPlan = $row['plan'] ?? null;
                                    @endphp
                                    <tr data-usage-tenant-row="{{ $row['tenant']->id }}">
                                        <td class="px-5 py-4 sm:px-6">
                                            <p class="font-medium text-gray-900">{{ $row['tenant']->name }}</p>
                                            <p class="mt-0.5 text-xs text-gray-400">#{{ $row['tenant']->id }}</p>
                                        </td>
                                        <td class="px-4 py-4 text-gray-700">{{ $rowPlan['label'] ?? '—' }}</td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums text-gray-900">
                                            {{ number_format((int) ($rowSummary['site_count'] ?? 0)) }} / {{ (int) ($rowSummary['site_limit'] ?? 0) > 0 ? number_format((int) $rowSummary['site_limit']) : '∞' }}
                                        </td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums text-gray-900">
                                            {{ number_format((int) ($rowSummary['article_count'] ?? 0)) }} / {{ (int) ($rowSummary['article_quota'] ?? 0) > 0 ? number_format((int) $rowSummary['article_quota']) : '∞' }}
                                        </td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums text-gray-900">
                                            {{ number_format((int) ($rowSummary['token_usage'] ?? 0)) }} / {{ (int) ($rowSummary['token_budget'] ?? 0) > 0 ? number_format((int) $rowSummary['token_budget']) : '∞' }}
                                        </td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums text-gray-900">{{ number_format((float) ($row['estimated_cost'] ?? 0), 2) }}</td>
                                        <td class="px-4 py-4 text-right font-mono tabular-nums {{ (int) ($row['alert_count'] ?? 0) > 0 ? 'text-amber-700' : 'text-gray-400' }}">{{ (int) ($row['alert_count'] ?? 0) }}</td>
                                        <td class="px-5 py-4 text-right sm:px-6">
                                            @if ($canEditQuota)
                                                <details class="group relative inline-block text-left">
                                                    <summary class="inline-flex min-h-9 cursor-pointer list-none items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                                        {{ __('admin.usage.tenants.manage') }}
                                                    </summary>
                                                    <div class="mt-3 w-72 rounded-lg border border-gray-200 bg-white p-4 text-left shadow-lg">
                                                        @include('admin.usage._quota-form', [
                                                            'formTenant' => $row['tenant'],
                                                            'formPlan' => $rowPlan,
                                                            'plans' => $plans,
                                                            'models' => $models,
                                                        ])
                                                    </div>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @else
            <section class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('admin.usage.metrics.label') }}">
                @foreach ($cards as $card)
                    @php($cardPercent = $percent($card['used'], $card['limit'], $card['configured']))
                    <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" data-usage-metric="{{ $card['key'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full text-white {{ $card['tone'] }}">
                                    <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                                </span>
                                <p class="text-sm font-medium text-gray-600">{{ $card['label'] }}</p>
                            </div>
                        </div>
                        <p class="mt-4 font-mono text-3xl font-semibold tabular-nums text-gray-950">{{ number_format($card['used']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $card['configured'] ? __('admin.usage.labels.'.$card['key'], ['limit' => number_format($card['limit'])]) : __('admin.usage.labels.unlimited') }}
                        </p>
                        @if ($card['carryover'] > 0)
                            <p class="mt-1 text-xs font-medium text-amber-600">
                                {{ __('admin.usage.labels.carryover_'.$card['key'], ['count' => number_format($card['carryover'])]) }}
                            </p>
                        @endif
                        @if ($cardPercent !== null)
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full {{ $cardPercent >= 100 ? 'bg-red-500' : ($cardPercent >= 80 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $cardPercent }}%"></div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>

            @if ($reconciliation !== null)
                <section class="mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="usage-reconciliation-title">
                    <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                        <h2 id="usage-reconciliation-title" class="text-lg font-semibold text-gray-950">{{ __('admin.usage.reconciliation.title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.usage.reconciliation.subtitle') }}</p>
                    </div>

                    @if (count($periods) === 0)
                        <p class="px-5 py-6 text-sm text-gray-500 sm:px-6">{{ __('admin.usage.reconciliation.empty') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-5 py-3 sm:px-6">{{ __('admin.usage.reconciliation.period') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('admin.usage.reconciliation.quota') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('admin.usage.reconciliation.used') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('admin.usage.reconciliation.carryover') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('admin.usage.reconciliation.overage') }}</th>
                                        <th class="px-4 py-3 text-right">{{ __('admin.usage.reconciliation.effective') }}</th>
                                        <th class="px-5 py-3 sm:px-6">{{ __('admin.usage.reconciliation.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($periods as $row)
                                        <tr data-usage-period="{{ $row['period'] }}">
                                            <td class="px-5 py-3 font-mono text-gray-900 sm:px-6">{{ $row['period'] }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums text-gray-900">{{ (int) $row['article_quota'] > 0 ? number_format((int) $row['article_quota']) : '∞' }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums text-gray-900">{{ number_format((int) $row['article_used']) }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums {{ (int) $row['article_carryover_in'] > 0 ? 'text-amber-700' : 'text-gray-500' }}">{{ number_format((int) $row['article_carryover_in']) }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums {{ (int) $row['article_overage'] > 0 ? 'text-amber-700' : 'text-gray-500' }}">{{ number_format((int) $row['article_overage']) }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums text-gray-900">{{ (int) $row['article_quota'] > 0 ? number_format((int) $row['article_effective_quota']) : '∞' }}</td>
                                            <td class="px-5 py-3 sm:px-6">
                                                @if ($row['frozen'])
                                                    <span class="inline-flex rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600">{{ __('admin.usage.reconciliation.frozen') }}</span>
                                                @elseif ($row['finalized'])
                                                    <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('admin.usage.reconciliation.finalized') }}</span>
                                                @else
                                                    <span class="inline-flex rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ __('admin.usage.reconciliation.open') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            <section class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-600">{{ __('admin.usage.metrics.cost') }}</p>
                    <p class="mt-4 font-mono text-3xl font-semibold tabular-nums text-gray-950">{{ number_format((float) $estimatedCost, 2) }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.usage.labels.cost') }}</p>
                </article>

                <aside class="rounded-lg border p-5 shadow-sm lg:col-span-2 {{ $pressureTones[$pressure] ?? $pressureTones['normal'] }}" data-usage-pressure="{{ $pressure ?? 'normal' }}">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/70">
                            <i data-lucide="{{ ($pressure ?? 'normal') === 'normal' ? 'shield-check' : 'triangle-alert' }}" class="h-5 w-5"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold">{{ __('admin.usage.pressure.'.($pressure ?? 'normal').'.title') }}</p>
                            <p class="mt-1 text-sm leading-6">{{ __('admin.usage.pressure.'.($pressure ?? 'normal').'.description') }}</p>
                        </div>
                    </div>
                </aside>
            </section>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="usage-alerts-title">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 id="usage-alerts-title" class="text-lg font-semibold text-gray-950">{{ __('admin.usage.alerts.title') }}</h2>
                    </div>
                    @if ($alerts->isEmpty())
                        <p class="px-5 py-6 text-sm text-gray-500">{{ __('admin.usage.alerts.empty') }}</p>
                    @else
                        <ul class="divide-y divide-gray-100" data-usage-alerts>
                            @foreach ($alerts as $alert)
                                @php($tone = $levelTones[$alert->level] ?? $levelTones[\App\Models\TenantQuotaAlert::LEVEL_DEGRADE_UNAVAILABLE])
                                <li class="flex items-start gap-3 px-5 py-4" data-usage-alert-level="{{ $alert->level }}">
                                    <span class="mt-0.5 inline-flex shrink-0 rounded-full border px-2 py-0.5 text-xs font-semibold {{ $tone }}">
                                        {{ $levelLabels[$alert->level] ?? $alert->level }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm leading-6 text-gray-800">{{ $alert->message }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ $alert->created_at?->format('Y-m-d H:i') }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="usage-events-title">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 id="usage-events-title" class="text-lg font-semibold text-gray-950">{{ __('admin.usage.events.title') }}</h2>
                    </div>
                    @if ($recentEvents->isEmpty())
                        <p class="px-5 py-6 text-sm text-gray-500">{{ __('admin.usage.events.empty') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-100 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-5 py-2">{{ __('admin.usage.events.occurred_at') }}</th>
                                        <th class="px-4 py-2">{{ __('admin.usage.events.meter') }}</th>
                                        <th class="px-5 py-2 text-right">{{ __('admin.usage.events.quantity') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @foreach ($recentEvents as $event)
                                        <tr data-usage-event="{{ $event->id }}">
                                            <td class="px-5 py-2.5 text-gray-700">{{ $event->occurred_at?->format('Y-m-d H:i') }}</td>
                                            <td class="px-4 py-2.5 text-gray-700">{{ __('admin.usage.meter.'.$event->meter) }}</td>
                                            <td class="px-5 py-2.5 text-right font-mono tabular-nums text-gray-900">{{ number_format((int) $event->quantity) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>

            @if ($canEditQuota)
                <section class="mt-4 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="usage-quota-title">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 id="usage-quota-title" class="text-lg font-semibold text-gray-950">{{ __('admin.usage.quota_form.title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.usage.quota_form.hint') }}</p>
                    </div>
                    <div class="px-5 py-5">
                        @include('admin.usage._quota-form', [
                            'formTenant' => $tenant,
                            'formPlan' => $plan,
                            'plans' => $plans,
                            'models' => $models,
                        ])
                    </div>
                </section>
            @endif
        @endif
    </div>
@endsection
