<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    /**
     * Keys that should never be recorded in audit logs.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
        'plain_token',
        'token_hash',
        'remember_token',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'private_key',
        'authorization',
    ];

    /**
     * Record an administrative audit entry.
     */
    public function log(
        User|int|null $actor,
        string $action,
        string $description,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): AdminAuditLog {
        $actorId = $actor instanceof User ? $actor->id : $actor;

        $request = $request ?? (request() instanceof Request ? request() : null);

        $sanitizedOld = $oldValues ? $this->sanitize($oldValues) : null;
        $sanitizedNew = $newValues ? $this->sanitize($newValues) : null;

        return AdminAuditLog::create([
            'user_id'     => $actorId,
            'action'      => $action,
            'entity_type' => $entity ? get_class($entity) : null,
            'entity_id'   => $entity ? (string) $entity->getKey() : null,
            'description' => $description,
            'old_values'  => $sanitizedOld,
            'new_values'  => $sanitizedNew,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /**
     * Sanitize array by scrubbing sensitive keys recursively.
     */
    protected function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
