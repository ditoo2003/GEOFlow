<?php

namespace App\Services\GeoFlow\AiVisibility;

use RuntimeException;

/**
 * 当日采样已达频率上限（预算降频）：不是配额硬拒，调度侧按"本次跳过"处理即可。
 */
final class VisibilitySamplingDeferredException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'daily_cap_reached')
    {
        parent::__construct('ai_visibility_sampling_deferred');
    }
}
