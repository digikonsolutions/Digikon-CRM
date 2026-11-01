<?php

namespace App\Services;

use App\Models\Lead;

/**
 * leads.next_follow_up_at = earliest of
 *   - open manual follow-up tasks (lead_tasks)
 *   - pending automated WhatsApp follow-ups (lead_followups)
 */
class FollowUpClock
{
    public static function refresh(Lead $lead): void
    {
        $task = $lead->tasks()->where('status', 'open')->min('due_at');
        $auto = $lead->followups()->where('status', 'pending')->min('scheduled_at');

        $dates = array_filter([$task, $auto]);
        $next = $dates ? min($dates) : null;

        $lead->forceFill(['next_follow_up_at' => $next])->saveQuietly();
    }
}
