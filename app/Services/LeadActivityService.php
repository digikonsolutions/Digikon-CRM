<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Carbon;

class LeadActivityService
{
    public function __construct(
        protected FollowUpTaskService $tasks,
        protected LeadStatusService $statuses,
    ) {}

    /**
     * Log a manual sales activity. The acting user is ALWAYS the authenticated user
     * ($actor) — a user_id coming from the request is never used.
     *
     * $data: type, outcome?, notes?, duration_seconds?, occurred_at?, next_follow_up_at?, complete_due_tasks?
     */
    public function log(Lead $lead, User $actor, array $data): LeadActivity
    {
        $type = $data['type'];
        $occurredAt = ! empty($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        if ($occurredAt->isFuture()) {
            $occurredAt = now();
        }

        $description = trim((string) ($data['notes'] ?? ''));
        if ($description === '') {
            $description = config('crm.activity_types')[$type] ?? ucfirst($type);
            if (! empty($data['outcome'])) {
                $description .= ' — '.(config('crm.call_outcomes')[$data['outcome']] ?? $data['outcome']);
            }
        }

        $activity = $lead->activities()->create([
            'user_id' => $actor->id,
            'type' => $type,
            'outcome' => $type === 'call' ? ($data['outcome'] ?? null) : null,
            'description' => $description,
            'duration_seconds' => $type === 'call' && isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            'occurred_at' => $occurredAt,
            'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            'is_manual' => true,
            'is_verified' => false,
        ]);

        if (! empty($data['complete_due_tasks'])) {
            $this->tasks->completeDue($lead, $actor);
        }

        if (! empty($data['next_follow_up_at'])) {
            $this->tasks->schedule($lead, Carbon::parse($data['next_follow_up_at']), $actor,
                $type === 'call' && ($data['outcome'] ?? null) === 'callback_requested' ? 'Call back (customer requested)' : null,
                null, $activity->id);
        }

        $connected = $type !== 'call' || in_array($data['outcome'] ?? null, config('crm.connected_call_outcomes'), true);
        if (in_array($type, config('crm.contact_activity_types'), true)) {
            $connected
                ? $this->statuses->markContacted($lead->fresh(), $actor->id)
                : $lead->forceFill(['last_contacted_at' => now(), 'first_contacted_at' => $lead->first_contacted_at ?? now()])->saveQuietly();
        }

        AuditLogger::log('activity.created', $activity, [], [
            'lead_id' => $lead->id, 'type' => $type, 'outcome' => $activity->outcome, 'duration_seconds' => $activity->duration_seconds,
        ], null, $actor->id);

        return $activity;
    }
}
