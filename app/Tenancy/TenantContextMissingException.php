<?php

namespace App\Tenancy;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    public static function forTable(string $table): self
    {
        return new self(sprintf('租户上下文缺失，拒绝访问表 [%s]。', $table));
    }
}
