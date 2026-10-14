<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Centralised audit logging. Never logs secrets. Records old/new values as JSON.
 */
class AuditLogger
{
    public static function log(
        string $action,
        ?string $entityType = null,
        $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $branchId = null
    ): AuditLog {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'branch_id' => $branchId ?? (Auth::check() ? Auth::user()->branch_id : null),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? self::redact($oldValues) : null,
            'new_values' => $newValues ? self::redact($newValues) : null,
            'description' => $description,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);
    }

    /**
     * Strip any sensitive keys before persisting.
     */
    protected static function redact(array $values): array
    {
        $sensitive = ['password', 'password_confirmation', 'token', 'remember_token', 'secret', 'api_token'];

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
