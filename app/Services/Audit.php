<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Audit
{
    private const SECRET_FIELDS = ['secret_encrypted', 'password', 'remember_token'];

    private ?string $requestId = null;

    public function log(
        string $action,
        Model|string $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $subscriberId = null,
        string $source = 'web',
    ): AuditLog {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'occurred_at' => now(),
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entity instanceof Model ? class_basename($entity) : $entity,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : null,
            'subscriber_id' => $subscriberId,
            'old_values' => $this->mask($old),
            'new_values' => $this->mask($new),
            'reason' => $reason,
            'source' => $source,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'request_id' => $this->requestId ??= (string) Str::uuid(),
        ]);
    }

    /**
     * Logs only the attributes that changed on a model that was just saved.
     */
    public function changes(string $action, Model $model, array $original, ?string $reason = null, ?int $subscriberId = null): ?AuditLog
    {
        $changed = array_diff_key($model->getChanges(), array_flip(['updated_at', 'updated_by']));
        if ($changed === []) {
            return null;
        }

        return $this->log($action, $model, array_intersect_key($original, $changed), $changed, $reason, $subscriberId);
    }

    private function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '***';
            }
        }

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v), $values);
    }
}
