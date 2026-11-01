<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CrmAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place where lead ownership changes.
 * Used by controllers, bulk actions, auto distribution (round robin / workload) and ingestion.
 */
class LeadAssignmentService
{
    public const AUTO_METHODS = [
        'round_robin' => 'Round robin (one by one in turn)',
        'least_leads' => 'Workload based (agent with the fewest open leads)',
        'manual' => 'Manual (manager assigns each lead)',
    ];

    /**
     * Assign / reassign a lead.
     *
     * @throws ValidationException when the agent cannot own leads
     */
    public function assign(Lead $lead, User $agent, ?int $byUserId, ?string $reason = null, string $method = 'manual', bool $notify = true): Lead
    {
        $this->ensureEligible($agent);

        $result = DB::transaction(function () use ($lead, $agent, $byUserId, $reason, $method) {
            // Row lock prevents two managers (or a manager + auto distribution) assigning at the same moment.
            $locked = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->assigned_to === (int) $agent->id) {
                return [$locked, false, null];
            }

            $previousId = $locked->assigned_to;
            $now = now();

            LeadAssignment::where('lead_id', $locked->id)->whereNull('unassigned_at')
                ->update(['unassigned_at' => $now, 'unassigned_by' => $byUserId, 'updated_at' => $now]);

            LeadAssignment::create([
                'lead_id' => $locked->id,
                'user_id' => $agent->id,
                'assigned_by' => $byUserId,
                'method' => $method,
                'assigned_at' => $now,
                'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            ]);

            $locked->update(['assigned_to' => $agent->id, 'assigned_by' => $byUserId, 'assigned_at' => $now]);
            $agent->forceFill(['last_lead_assigned_at' => $now])->save();

            // Open follow-up tasks move with the lead to the new owner.
            $locked->tasks()->where('status', 'open')->update(['user_id' => $agent->id]);

            $previous = $previousId ? User::find($previousId) : null;
            $text = $previous
                ? "Lead reassigned from {$previous->name} to {$agent->name}"
                : "Lead assigned to {$agent->name}";
            $locked->log('assigned', $text.($reason ? " — reason: {$reason}" : '').($method !== 'manual' ? ' ('.(LeadAssignment::METHODS[$method] ?? $method).')' : ''), $byUserId);

            AuditLogger::log($previous ? 'lead.reassigned' : 'lead.assigned', $locked,
                ['assigned_to' => $previousId], ['assigned_to' => $agent->id, 'method' => $method], $reason, $byUserId);

            return [$locked, true, $previous];
        });

        [$fresh, $changed, $previous] = $result;

        if ($changed && $notify) {
            Notifier::send([$agent], 'lead_assigned', new CrmAlert(
                'lead_assigned',
                ($previous ? 'Lead reassigned to you: ' : 'New lead assigned: ').$fresh->display_name,
                trim(($fresh->service ? $fresh->service.' · ' : '').($fresh->phone ?? '')).($reason ? " — {$reason}" : ''),
                route('leads.show', $fresh),
            ), exceptUserId: $byUserId);
        }

        return $fresh->fresh(['agent']);
    }

    public function unassign(Lead $lead, ?int $byUserId, ?string $reason = null): Lead
    {
        return DB::transaction(function () use ($lead, $byUserId, $reason) {
            $locked = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if (! $locked->assigned_to) {
                return $locked;
            }
            $previous = User::find($locked->assigned_to);
            $now = now();

            LeadAssignment::where('lead_id', $locked->id)->whereNull('unassigned_at')
                ->update(['unassigned_at' => $now, 'unassigned_by' => $byUserId, 'updated_at' => $now]);

            $locked->update(['assigned_to' => null, 'assigned_by' => $byUserId, 'assigned_at' => null]);
            $locked->log('assigned', 'Lead unassigned'.($previous ? " from {$previous->name}" : '').($reason ? " — reason: {$reason}" : ''), $byUserId);
            AuditLogger::log('lead.unassigned', $locked, ['assigned_to' => $previous?->id], ['assigned_to' => null], $reason, $byUserId);

            return $locked->fresh();
        });
    }

    /**
     * Bulk assign. Caller must already have authorised every lead (controller checks policy per lead).
     *
     * @return int number of leads whose owner changed
     */
    public function bulkAssign(iterable $leads, User $agent, ?int $byUserId, ?string $reason = null): int
    {
        $this->ensureEligible($agent);
        $changed = 0;
        foreach ($leads as $lead) {
            $before = $lead->assigned_to;
            $after = $this->assign($lead, $agent, $byUserId, $reason, 'bulk', notify: false);
            if ((int) $before !== (int) $after->assigned_to) {
                $changed++;
            }
        }

        if ($changed) {
            Notifier::send([$agent], 'lead_assigned', new CrmAlert(
                'lead_assigned', "{$changed} lead(s) assigned to you", $reason ?: 'Bulk assignment', route('leads.index', ['scope' => 'mine']),
            ), exceptUserId: $byUserId);
        }

        return $changed;
    }

    /**
     * Automatic distribution using the method from Settings (round robin / workload).
     * Returns the chosen agent, or null when method is manual / nobody is eligible.
     */
    public function autoAssign(Lead $lead, ?string $method = null, ?int $byUserId = null): ?User
    {
        if ($lead->assigned_to) {
            return $lead->agent;
        }

        $method ??= Setting::get('distribution_method', 'round_robin');
        if (! in_array($method, ['round_robin', 'least_leads'], true)) {
            return null;
        }

        $agent = DB::transaction(function () use ($method) {
            $agents = User::assignable()->where('receives_leads', true)->lockForUpdate();

            if ($method === 'least_leads') {
                return $agents->withCount(['leads as open_leads_count' => fn ($q) => $q->whereNotIn('status', Lead::CLOSED_STATUSES)])
                    ->orderBy('open_leads_count')->orderBy('last_lead_assigned_at')->orderBy('id')->first();
            }

            $lastId = (int) Setting::get('rr_last_agent_id', 0);
            $agent = (clone $agents)->where('id', '>', $lastId)->orderBy('id')->first() ?? $agents->orderBy('id')->first();
            if ($agent) {
                Setting::set('rr_last_agent_id', $agent->id);
            }

            return $agent;
        });

        if (! $agent) {
            return null;
        }

        $this->assign($lead, $agent, $byUserId, null, $method);

        return $agent;
    }

    public function ensureEligible(User $agent): void
    {
        if (! $agent->canOwnLeads()) {
            throw ValidationException::withMessages([
                'assigned_to' => "{$agent->name} cannot receive leads (inactive user or role \"{$agent->role_label}\" is not a sales role).",
            ]);
        }
    }
}
