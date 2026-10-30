<?php

namespace App\Services;

final class TenantContext
{
    public ?int $id = null;

    public function requireId(): int
    {
        return $this->id ?? throw new \LogicException('Tenant context is required.');
    }
}
