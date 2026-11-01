<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** Keys never written to the audit log. */
    protected const HIDDEN = ['password', 'remember_token', 'access_token', 'token', 'api_key', 'secret'];

    public static function log(string $action, ?Model $model = null, array $old = [], array $new = [], ?string $description = null, ?int $userId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? class_basename($model) : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => static::clean($old) ?: null,
            'new_values' => static::clean($new) ?: null,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    protected static function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            foreach (self::HIDDEN as $hidden) {
                if (str_contains(strtolower((string) $key), $hidden)) {
                    $values[$key] = '[hidden]';
                }
            }
            if ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $values;
    }
}
