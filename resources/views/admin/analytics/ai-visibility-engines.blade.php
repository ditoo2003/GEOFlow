@extends('admin.layouts.app')

@php
    $engineLabels = \App\Services\GeoFlow\AiVisibility\VisibilityEngineReportService::ENGINE_LABELS;
    $percent = static fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1).'%';
    $modeLabel = static fn (bool $searchEnabled): string => $searchEnabled ? '联网检索' : '非联网';
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-ai-visibility-engine-report>
        <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-gray-200 pb-4">
            <div>
                <h1 class="text-lg font-bold tracking-tight text-gray-950">多引擎 AI 可见性</h1>
                <p class="mt-1 text-xs text-gray-500">
                    关键词 × 引擎 × 联网模式；单格满 {{ $report['required_samples'] }} 个有效样本才出比率，口径为 API 口径。
                </p>
            </div>
            <form method="GET" action="{{ route('admin.analytics.ai-visibility.engines') }}" class="flex flex-wrap items-end gap-2">
                <label class="text-xs font-medium text-gray-600">起
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs font-medium text-gray-600">止
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs font-medium text-gray-600">引擎
                    <select name="engine" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="">全部</option>
                        @foreach ($engineLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['engine_keys'][0] ?? null) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-medium text-gray-600">检索
                    <select name="search" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="all" @selected($filters['search_enabled'] === null)>全部（分线展示）</option>
                        <option value="online" @selected($filters['search_enabled'] === true)>仅联网</option>
                        <option value="offline" @selected($filters['search_enabled'] === false)>仅非联网</option>
                    </select>
                </label>
                <button type="submit" class="min-h-9 rounded-md bg-violet-600 px-3 text-sm font-semibold text-white">筛选</button>
            </form>
        </header>

        <p class="mb-4 text-xs text-gray-500">
            统计区间 {{ $report['period']['from'] }} ~ {{ $report['period']['to'] }}；历史豆包/DeepSeek 数据已标记 legacy，不进入本口径。
        </p>

        @forelse ($report['engines'] as $engine)
            <section class="mb-6 rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-bold text-gray-900">{{ $engine['label'] }} <span class="ml-1 font-mono text-xs font-normal text-gray-400">{{ $engine['engine_key'] }}</span></h2>
                    <span class="text-xs text-gray-500">{{ $engine['ready_cells'] }}/{{ $engine['total_cells'] }} 格可出数</span>
                </div>

                @if ($engine['cells'] === [])
                    <p class="px-4 py-6 text-sm text-gray-500">该引擎在所选区间暂无已完成样本。</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-xs text-gray-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">关键词</th>
                                    <th class="px-3 py-2 text-left font-medium">模式</th>
                                    <th class="px-3 py-2 text-right font-medium">有效样本</th>
                                    <th class="px-3 py-2 text-right font-medium">提及率</th>
                                    <th class="px-3 py-2 text-right font-medium">95% CI</th>
                                    <th class="px-3 py-2 text-right font-medium">首位率</th>
                                    <th class="px-3 py-2 text-right font-medium">引用覆盖</th>
                                    <th class="px-3 py-2 text-right font-medium">前三自有引用</th>
                                    <th class="px-3 py-2 text-left font-medium">SOV / 主要信源</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($engine['cells'] as $cell)
                                    <tr class="{{ $cell['ready'] ? '' : 'bg-amber-50/40' }}">
                                        <td class="px-4 py-2 font-medium text-gray-900">{{ $cell['keyword'] }}</td>
                                        <td class="px-3 py-2 text-gray-600">{{ $modeLabel($cell['search_enabled']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $cell['valid_samples'] }}</td>
                                        @if ($cell['ready'])
                                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{{ $percent($cell['mention_rate']) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-xs text-gray-500">{{ $percent($cell['mention_rate_ci'][0]) }} ~ {{ $percent($cell['mention_rate_ci'][1]) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $percent($cell['first_position_rate']) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $percent($cell['citation_coverage_rate']) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $percent($cell['top3_owned_citation_rate']) }}</td>
                                            <td class="px-3 py-2 text-xs text-gray-600">
                                                @foreach (array_slice($cell['sov'], 0, 3) as $sov)
                                                    <span class="mr-2 inline-block">{{ $sov['canonical_name'] }} {{ $percent($sov['share']) }}</span>
                                                @endforeach
                                                @if ($cell['top_domains'] !== [])
                                                    <div class="mt-1 text-gray-400">
                                                        @foreach (array_slice($cell['top_domains'], 0, 3) as $domain)
                                                            <span class="mr-2">{{ $domain['domain'] }}（{{ $domain['citations'] }}）</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                        @else
                                            <td colspan="6" class="px-3 py-2 text-right text-xs font-medium text-amber-700">
                                                样本不足，仅报数：还需 {{ max(0, $cell['required_samples'] - $cell['valid_samples']) }} 个有效样本
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @empty
            <p class="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500">
                所选区间没有多引擎样本。请先完成引擎模型绑定并采集样本。
            </p>
        @endforelse
    </div>
@endsection
