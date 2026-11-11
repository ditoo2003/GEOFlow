<?php

namespace App\Ai\Adapter;

/**
 * 单次模型调用的预算（秒）。
 *
 * 取值顺序：场景画像 ∩ 接入点显式超时画像 ∩ 调用方请求值，三者取 min；
 * 业务截止时间再与本次预算取 min，保证 read ≤ total ≤ business_deadline。
 */
final readonly class CallBudget
{
    public function __construct(
        public int $connect,
        public int $read,
        public int $total,
        public ?int $businessDeadline = null,
    ) {}

    public static function for(
        ModelEndpoint $endpoint,
        CallScene $scene,
        int $requestedTotal = 0,
        ?int $requestedRead = null,
        ?int $remainingBusinessSeconds = null,
    ): self {
        $profile = $scene->budget();
        $endpointProfile = $endpoint->timeoutProfile;

        $total = self::minPositive([
            $profile['total'],
            $endpointProfile['total'] ?? null,
            $requestedTotal > 0 ? $requestedTotal : null,
            $remainingBusinessSeconds,
        ]);
        $read = self::minPositive([
            $profile['read'],
            $endpointProfile['read'] ?? null,
            $requestedRead,
            $total,
        ]);
        $connect = self::minPositive([
            $profile['connect'],
            $endpointProfile['connect'] ?? null,
            $read,
        ]);

        return new self(
            connect: max(1, $connect),
            read: max(1, $read),
            total: max(1, $total),
            businessDeadline: $remainingBusinessSeconds,
        );
    }

    /**
     * @param  array<int, int|null>  $values
     */
    private static function minPositive(array $values): int
    {
        $positive = array_values(array_filter(
            $values,
            static fn (?int $value): bool => is_int($value) && $value > 0,
        ));

        return $positive === [] ? 0 : min($positive);
    }
}
