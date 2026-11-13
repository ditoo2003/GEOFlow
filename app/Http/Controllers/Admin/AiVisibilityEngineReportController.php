<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\AiVisibility\VisibilityEngineReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 多引擎可见性看板与出数 API（关键词 × 引擎 × 联网模式 × 时间区间）。
 *
 * 仅超级管理员可见：这里是对外售卖口径的原始看板，不属于普通运营页面。
 */
class AiVisibilityEngineReportController extends Controller
{
    public function __construct(
        private readonly VisibilityEngineReportService $report,
    ) {}

    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.analytics.ai-visibility-engines', [
            'report' => $this->report->report($filters),
            'filters' => $filters,
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        return response()->json($this->report->report($this->filters($request)));
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'engine' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'in:all,online,offline'],
            'keyword_hash' => ['nullable', 'string', 'max:64'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'engine_keys' => isset($validated['engine']) && $validated['engine'] !== ''
                ? [(string) $validated['engine']]
                : null,
            'search_enabled' => match ($validated['search'] ?? 'all') {
                'online' => true,
                'offline' => false,
                default => null,
            },
            'keyword_hash' => $validated['keyword_hash'] ?? null,
        ];
    }
}
