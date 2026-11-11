<?php

namespace App\Ai\Adapter;

/**
 * 超时链校验的单一事实源。
 *
 * 保留并推广现有质检链约束：business < job < worker < retry_after，
 * 供队列启动命令与配置自检命令共用，避免每条链路各自实现一份比较逻辑。
 */
final class TimeoutChainValidator
{
    /**
     * 质检链的四段预算（秒）。
     *
     * @return array{business:int,job:int,worker:int,retry_after:int}
     */
    public function qualityChain(): array
    {
        return [
            'business' => (int) config('geoflow.ai_quality_deadline_seconds', 180)
                + (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
                + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10),
            'job' => (int) config('geoflow.ai_quality_job_timeout_seconds', 245),
            'worker' => (int) config('geoflow.ai_quality_worker_timeout_seconds', 250),
            'retry_after' => (int) config('queue.connections.redis.retry_after', 960),
        ];
    }

    /**
     * @param  array{business:int,job:int,worker:int,retry_after:int}  $chain
     * @return list<string>
     */
    public function violations(array $chain): array
    {
        $violations = [];
        if ($chain['business'] >= $chain['job']) {
            $violations[] = 'business must be less than job';
        }
        if ($chain['job'] >= $chain['worker']) {
            $violations[] = 'job must be less than worker';
        }
        if ($chain['worker'] >= $chain['retry_after']) {
            $violations[] = 'worker must be less than retry_after';
        }

        return $violations;
    }

    /**
     * 单次调用预算必须落在业务截止时间内：read ≤ total ≤ business_deadline。
     */
    public function budgetViolation(CallBudget $budget): ?string
    {
        if ($budget->read > $budget->total) {
            return 'read must not exceed total';
        }
        if ($budget->businessDeadline !== null && $budget->total > $budget->businessDeadline) {
            return 'total must not exceed business deadline';
        }

        return null;
    }
}
