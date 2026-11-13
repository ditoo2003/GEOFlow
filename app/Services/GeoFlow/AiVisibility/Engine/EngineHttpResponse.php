<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

final readonly class EngineHttpResponse
{
    /**
     * @param  array<string,mixed>  $json
     * @param  array<string,mixed>  $request
     */
    public function __construct(
        public int $status,
        public array $json,
        public array $request,
        public int $latencyMs,
    ) {}
}
