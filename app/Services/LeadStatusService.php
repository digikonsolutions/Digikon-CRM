<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadStatusChange;

/**
 * Every change of status / temperature / qualification goes through here so
 * history (for reports) and the timeline stay correct.
 */
class LeadStatusService
{
    public const FIELDS = ['status', 'temperature', 'qualification'];

    public function __construct(protected FollowupService $followups) {}

    /**
     * @param  string  $source  manual | ai | system
     * @return bool  true if the value changed
     */
    public function change(Lead $lead, string $field, ?string $value, ?int $userId, string $source = 'manual', ?string $reason = null, array $extra = []): bool
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException("Unsupported field {$field}");
        }

        $old = $lead->{$field};
        if ($old === $value) {
            if ($extra) {
                $lead->update($extra);
            }

            return false;
        }

        $updates = array_merge($extra, [$field => $value]);
        if ($field === 'temperature') {
            $updates['temperature_source'] = $value ? $source : null;
        }
        if ($field === 'qualification') {
            $updates['qualification_source'] = $source;
        }
        $lead->update($updates);

        LeadStatusChange::create([
            'lead_id' => $lead->id,
            'user_id' => $source === 'manual' ? $userId : null,
            'owner_id' => $lead->assigned_to,
            'field' => $field,
            'from_value' => $old,
            'to_value' => $value,
            'source' => $source,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'changed_at' => now(),
        ]);

        $label = fn ($v) => match ($field) {
            'status' => Lead::STATUSES[$v] ?? ($v ?? '—'),
            'temperature' => config('crm.temperatures')[$v] ?? ($v ?? '—'),
            default => config('crm.qualifications')[$v] ?? ($v ?? '—'),
        };
        $by = $source === 'ai' ? ' (AI suggestion applied)' : ($source === 'system' ? ' (automatic)' : '');
        $activity = $lead->log(
            $field === 'status' ? 'status_change' : $field,
            ucfirst($field).': '.$label($old).' → '.$label($value).$by.($reason ? " — {$reason}" : ''),
            $source === 'manual' ? $userId : null,
        );
        $activity->update(['meta' => ['field' => $field, 'from' => $old, 'to' => $value, 'source' => $source]]);

        if ($field === 'status' && in_array($value, ['won', 'lost', 'qualified', 'meeting_done'], true)) {
            $this->followups->cancelFor($lead, 'Status changed to '.$label($value));
        }

        app(LeadScoreService::class)->refresh($lead);

        // Sales automation hooks
        if ($field === 'status' && $value === 'meeting_done') {
            app(SalesAutomation::class)->onMeetingSuccess($lead->fresh());
        }
        if ($field === 'status' && $value === 'won') {
            app(SalesAutomation::class)->onLeadWon($lead->fresh());
        }

        return true;
    }

    /** Move a "new" lead to "contacted" after the first real contact (never moves a lead backwards). */
    public function markContacted(Lead $lead, ?int $userId): void
    {
        $updates = ['last_contacted_at' => now()];
        if (! $lead->first_contacted_at) {
            $updates['first_contacted_at'] = now();
        }
        if ($lead->status === 'new') {
            $this->change($lead, 'status', 'contacted', $userId, $userId ? 'manual' : 'system', 'First contact logged', $updates);

            return;
        }
        $lead->update($updates);
    }
}
