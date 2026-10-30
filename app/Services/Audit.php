<?php

namespace App\Services;

use App\Models\AuditEvent;

final class Audit
{
    public static function record(string $action, string $subject, array $details = []): void
    {
        AuditEvent::create(['actor_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'details' => $details, 'created_at' => now()]);
    }
}
