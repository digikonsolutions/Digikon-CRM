<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CrmAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    /**
     * Send to users who have this notification type switched on.
     * Never throws: a notification problem must not break lead creation / assignment.
     */
    public static function send(iterable $users, string $type, CrmAlert $alert, ?int $exceptUserId = null): int
    {
        $recipients = collect($users)
            ->filter(fn ($u) => $u instanceof User && $u->is_active && $u->wantsNotification($type))
            ->reject(fn ($u) => $exceptUserId !== null && (int) $u->id === (int) $exceptUserId)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($recipients, $alert);
        } catch (\Throwable $e) {
            Log::warning('CRM notification failed: '.$e->getMessage(), ['type' => $type]);

            return 0;
        }

        return $recipients->count();
    }

    /** Users holding a permission (e.g. managers for "new lead" alerts). */
    public static function usersWithPermission(string $permission)
    {
        return User::where('is_active', true)->get()->filter(fn (User $u) => $u->hasPermission($permission))->values();
    }
}
