<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\CrmAlert;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingService
{
    public function __construct(protected LeadStatusService $statuses) {}

    /** Active meetings of the agent that overlap the given time range. */
    public function conflicts(?int $agentId, CarbonInterface $start, CarbonInterface $end, ?int $exceptId = null): Collection
    {
        if (! $agentId) {
            return collect();
        }

        return Meeting::with('lead')
            ->where('assigned_agent_id', $agentId)
            ->active()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Conflict policy: double-booking an agent is rejected.
     * Users with "meetings.view-team" (managers/admins) may override it explicitly with allow_conflict.
     */
    protected function guardConflicts(User $actor, ?int $agentId, CarbonInterface $start, CarbonInterface $end, bool $allowConflict, ?int $exceptId = null): void
    {
        $conflicts = $this->conflicts($agentId, $start, $end, $exceptId);
        if ($conflicts->isEmpty()) {
            return;
        }
        if ($allowConflict && $actor->hasPermission('meetings.view-team')) {
            return;
        }

        $list = $conflicts->map(fn ($m) => $m->starts_at->format('d M h:i A').'–'.$m->ends_at->format('h:i A').' ('.$m->lead?->display_name.')')->implode(', ');
        throw ValidationException::withMessages([
            'starts_at' => "This agent already has a meeting at that time: {$list}.",
        ]);
    }

    public function create(Lead $lead, User $actor, array $data): Meeting
    {
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        $agentId = (int) ($data['assigned_agent_id'] ?? 0) ?: ($lead->assigned_to ?: $actor->id);

        // Agents can only book meetings for themselves; managers may pick any sales user.
        if (! $actor->hasPermission('meetings.view-team')) {
            $agentId = $actor->id;
        } elseif ($agent = User::find($agentId)) {
            if (! $agent->canOwnLeads() && $agent->id !== $actor->id) {
                throw ValidationException::withMessages(['assigned_agent_id' => 'Selected user cannot take meetings.']);
            }
        }

        $this->guardConflicts($actor, $agentId, $start, $end, (bool) ($data['allow_conflict'] ?? false));

        $meeting = DB::transaction(function () use ($lead, $actor, $data, $start, $end, $agentId) {
            $meeting = Meeting::create([
                'lead_id' => $lead->id,
                'organizer_id' => $actor->id,
                'assigned_agent_id' => $agentId,
                'title' => $data['title'] ?: 'Meeting with '.$lead->display_name,
                'meeting_type' => $data['meeting_type'],
                'starts_at' => $start,
                'ends_at' => $end,
                'timezone' => config('app.timezone'),
                'location' => $data['location'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'scheduled',
            ]);

            $lead->log('meeting_scheduled', "Meeting booked: {$meeting->title} — ".$start->format('d M Y, h:i A')." ({$meeting->type_label})", $actor->id);
            AuditLogger::log('meeting.created', $meeting, [], $meeting->only(['lead_id', 'assigned_agent_id', 'starts_at', 'ends_at', 'meeting_type']), null, $actor->id);

            return $meeting;
        });

        if ($agentId !== $actor->id && $agent = User::find($agentId)) {
            Notifier::send([$agent], 'meeting_reminder', new CrmAlert('meeting_reminder',
                'Meeting booked for you: '.$lead->display_name,
                $start->format('D d M, h:i A').' · '.$meeting->type_label,
                route('meetings.index', ['date' => $start->toDateString(), 'view' => 'day'])));
        }

        return $meeting;
    }

    public function reschedule(Meeting $meeting, User $actor, array $data): Meeting
    {
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        $this->guardConflicts($actor, $meeting->assigned_agent_id, $start, $end, (bool) ($data['allow_conflict'] ?? false), $meeting->id);

        $old = ['starts_at' => $meeting->starts_at, 'ends_at' => $meeting->ends_at, 'status' => $meeting->status];
        $optional = array_filter([
            'meeting_type' => $data['meeting_type'] ?? null,
            'location' => $data['location'] ?? null,
            'meeting_link' => $data['meeting_link'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $meeting->update($optional + [
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => 'rescheduled',
            'reschedule_count' => $meeting->reschedule_count + 1,
            'reminder_sent_at' => null,     // a new reminder will be sent for the new time
            'missed_notified_at' => null,
        ]);

        $reason = $data['reason'] ?? null;
        $meeting->lead->log('meeting_scheduled', 'Meeting rescheduled: '.$old['starts_at']->format('d M h:i A').' → '.$start->format('d M Y, h:i A').($reason ? " — {$reason}" : ''), $actor->id);
        AuditLogger::log('meeting.rescheduled', $meeting, $old, ['starts_at' => $start, 'ends_at' => $end], $reason, $actor->id);

        return $meeting->fresh();
    }

    public function cancel(Meeting $meeting, User $actor, ?string $reason): Meeting
    {
        $old = $meeting->status;
        $meeting->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        $meeting->lead->log('meeting_scheduled', "Meeting cancelled: {$meeting->title}".($reason ? " — {$reason}" : ''), $actor->id);
        AuditLogger::log('meeting.cancelled', $meeting, ['status' => $old], ['status' => 'cancelled'], $reason, $actor->id);

        return $meeting;
    }

    /** Record what happened: completed (with outcome) or no_show. Updates reports immediately (they read meeting status). */
    public function recordOutcome(Meeting $meeting, User $actor, string $status, ?string $outcome, ?string $notes): Meeting
    {
        if (! in_array($status, ['completed', 'no_show'], true)) {
            throw ValidationException::withMessages(['status' => 'Outcome must be completed or no-show.']);
        }
        if ($meeting->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'A meeting that has not started yet cannot be marked '.str_replace('_', '-', $status).'.']);
        }

        $old = $meeting->only(['status', 'outcome']);
        $meeting->update([
            'status' => $status,
            'outcome' => $outcome,
            'notes' => trim(($meeting->notes ? $meeting->notes."\n" : '').($notes ?? '')) ?: null,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        $lead = $meeting->lead;
        if ($status === 'completed') {
            // A held meeting is a real sales activity (manually reported).
            $lead->activities()->create([
                'user_id' => $actor->id,
                'type' => 'meeting',
                'description' => "Meeting held: {$meeting->title}".($outcome ? " — {$outcome}" : ''),
                'occurred_at' => $meeting->starts_at,
                'duration_seconds' => $meeting->ends_at ? $meeting->starts_at->diffInSeconds($meeting->ends_at) : null,
                'is_manual' => true,
                'meta' => ['meeting_id' => $meeting->id],
            ]);
            $this->statuses->markContacted($lead, $actor->id);

            // "Meeting success": move the lead to Meeting Done (this also triggers the automatic proposal).
            $fresh = $lead->fresh();
            if (in_array($fresh->status, ['new', 'contacted', 'qualified'], true)) {
                $this->statuses->change($fresh, 'status', 'meeting_done', $actor->id, 'manual', 'Meeting held: '.$meeting->title);
            } else {
                app(SalesAutomation::class)->onMeetingSuccess($fresh, $meeting);
            }
        } else {
            $lead->log('meeting_scheduled', "Customer did not show up for: {$meeting->title}", $actor->id);
        }

        AuditLogger::log('meeting.outcome', $meeting, $old, ['status' => $status, 'outcome' => $outcome], null, $actor->id);

        return $meeting;
    }

    /** iCalendar file so the agent can add the meeting to Google / Outlook / Apple calendar. */
    public function ics(Meeting $meeting): string
    {
        $fmt = fn (CarbonInterface $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $esc = fn (?string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
        $desc = trim('Lead: '.$meeting->lead?->display_name.' '.($meeting->lead?->phone ?? '')."\n".($meeting->meeting_link ?? '')."\n".($meeting->notes ?? ''));

        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Digikon CRM//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:meeting-'.$meeting->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($meeting->starts_at),
            'DTEND:'.$fmt($meeting->ends_at ?? $meeting->starts_at->copy()->addMinutes(30)),
            'SUMMARY:'.$esc($meeting->title),
            'DESCRIPTION:'.$esc($desc),
            'LOCATION:'.$esc($meeting->location ?: $meeting->meeting_link),
            'STATUS:'.($meeting->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }
}
