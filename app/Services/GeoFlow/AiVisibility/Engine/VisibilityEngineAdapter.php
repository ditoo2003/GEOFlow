<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use App\Models\AiModel;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;

/**
 * 引擎适配器：把某个 AI 搜索入口的一次采样，归一成 {@see VisibilitySample}。
 *
 * 适配器只负责"发请求 + 归一返回结构"，不做配额、不做落库、不做聚合；
 * 所有出站必须走 `AiVisibilityHttpClientFactory`（安全出站网关）。
 */
interface VisibilityEngineAdapter
{
    /** 稳定引擎身份，写入 `ai_visibility_runs.engine_key`。 */
    public function key(): string;

    /** 采集通道，P0 固定为 api。 */
    public function variant(): string;

    /**
     * 该引擎能否在"联网检索"与"仅凭模型知识"之间显式切换。
     *
     * 不支持关闭检索的引擎（如 Perplexity）不得接收 `searchEnabled = false`，
     * 否则联网样本会被误标成非联网，破坏聚合口径。
     */
    public function supportsSearchToggle(): bool;

    public function supportsCitations(): bool;

    public function sample(AiModel $model, VisibilitySampleRequest $request): VisibilitySample;
}
