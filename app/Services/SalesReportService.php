<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignment;
use App\Models\LeadStatusChange;
use App\Models\LeadTask;
use App\Models\Meeting;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * All numbers are computed from CRM records with SQL aggregation.
 * Dates are in the application timezone (Asia/Karachi), which is also how Laravel stores timestamps.
 */
class SalesReportService
{
    /** Human-readable definition of every metric (shown on the reports page). */
    public const DEFINITIONS = [
        'leads_assigned' => 'Assignments made to the agent during the period (from assignment history; a reassignment counts for the new owner).',
        'owned_at_end' => 'Leads owned by the agent at the end of the period (from assignment history).',
        'unique_leads_contacted' => 'Distinct leads with at least one call / WhatsApp / email / held meeting logged by the agent. 5 calls to one lead = 1 lead.',
        'calls_total' => 'Every call logged (manual call logs — not verified by a phone system).',
        'calls_connected' => 'Calls with outcome Answered, Callback requested, Interested, Not interested or Meeting booked.',
        'calls_no_answer' => 'Calls with outcome "No answer". Busy is counted separately.',
        'talk_time' => 'Sum of the call durations agents typed in.',
        'whatsapp_activities' => 'WhatsApp messages sent by an agent from the CRM + WhatsApp activities logged by hand (automatic follow-ups excluded).',
        'email_activities' => 'Emails sent from the CRM (proposals) and emails logged by hand.',
        'follow_ups_due' => 'Manual follow-up tasks whose due date falls in the period (cancelled ones excluded).',
        'follow_ups_completed' => 'Follow-up tasks marked done in the period.',
        'follow_ups_overdue' => 'Follow-up tasks that were due before the end of the period and still not done at that moment.',
        'meetings_booked' => 'Meetings created during the period (any status). Booking ≠ completed.',
        'meetings_completed' => 'Meetings starting in the period that were marked Completed.',
        'meetings_cancelled' => 'Meetings starting in the period that were cancelled.',
        'meetings_no_show' => 'Meetings starting in the period marked No-show.',
        'newly_qualified' => 'Distinct leads moved to status "Qualified" in the period (owner at the time of change).',
        'won' => 'Distinct leads moved to "Won" in the period.',
        'lost' => 'Distinct leads moved to "Lost" in the period.',
        'revenue' => 'Total of proposals marked Accepted in the period, per currency (by proposal creator). Only real proposal data — nothing estimated.',
        'avg_response_minutes' => 'For leads assigned in the period: average time from assignment to the agent\'s first contact activity.',
        'win_rate' => 'Won ÷ (Won + Lost) closed in the period.',
        'assigned_conversion' => 'Of the leads assigned in the period, the share whose CURRENT status is Won. An assignment alone is never a conversion.',
    ];

    /**
     * @param  array<int>|null  $userIds  null = whole team
     */
    public function metrics(CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): array
    {
        $from = Carbon::instance($from)->startOfDay();
        $to = Carbon::instance($to)->endOfDay();
        $u = fn ($q, string $col) => $userIds === null ? $q->whereNotNull($col) : $q->whereIn($col, $userIds);

        // ---- Assignment ----
        $leadsAssigned = $u(LeadAssignment::query(), 'user_id')->whereBetween('assigned_at', [$from, $to])->count();
        $ownedAtEnd = $u(LeadAssignment::query(), 'user_id')->where('assigned_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('unassigned_at')->orWhere('unassigned_at', '>', $to))->count();

        // ---- Activities ----
        $contactTypes = config('crm.contact_activity_types');
        $activities = $u(LeadActivity::query(), 'user_id')->whereBetween('occurred_at', [$from, $to]);

        $uniqueContacted = (clone $activities)->whereIn('type', $contactTypes)->distinct()->count('lead_id');
        $byType = (clone $activities)->whereIn('type', ['call', 'whatsapp', 'email', 'meeting'])
            ->select('type', DB::raw('COUNT(*) as total'))->groupBy('type')->pluck('total', 'type');

        $calls = (clone $activities)->where('type', 'call')
            ->select('outcome', DB::raw('COUNT(*) as total'), DB::raw('COALESCE(SUM(duration_seconds),0) as secs'))
            ->groupBy('outcome')->toBase()->get()->keyBy('outcome');
        $connected = $calls->only(config('crm.connected_call_outcomes'))->sum('total');

        // ---- Follow-ups ----
        $tasks = $u(LeadTask::query(), 'user_id');
        $due = (clone $tasks)->where('status', '!=', 'cancelled')->whereBetween('due_at', [$from, $to])->count();
        $completed = (clone $tasks)->where('status', 'done')->whereBetween('completed_at', [$from, $to])->count();
        $overdueAt = $to->copy()->min(now());
        $overdue = (clone $tasks)->where('status', '!=', 'cancelled')->where('due_at', '<', $overdueAt)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', $overdueAt))->count();

        // ---- Meetings ----
        $meetings = $u(Meeting::query(), 'assigned_agent_id');
        $booked = (clone $meetings)->whereBetween('created_at', [$from, $to])->count();
        $meetingStatus = (clone $meetings)->whereBetween('starts_at', [$from, $to])
            ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

        // ---- Pipeline transitions (owner at time of change) ----
        $transitions = $u(LeadStatusChange::query(), 'owner_id')->where('field', 'status')
            ->whereIn('to_value', ['qualified', 'won', 'lost'])->whereBetween('changed_at', [$from, $to])
            ->select('to_value', DB::raw('COUNT(DISTINCT lead_id) as total'))->groupBy('to_value')->pluck('total', 'to_value');
        $won = (int) ($transitions['won'] ?? 0);
        $lost = (int) ($transitions['lost'] ?? 0);

        // ---- Revenue (only when proposals were accepted) ----
        $revenue = $u(Proposal::query(), 'user_id')->where('status', 'accepted')->whereBetween('accepted_at', [$from, $to])
            ->select('currency', DB::raw('SUM(total) as total'))->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($v) => round((float) $v, 2))->all();

        // ---- Response time ----
        $response = $this->responseTimes($from, $to, $userIds);

        // ---- Conversion of leads assigned in the period ----
        $assignedLeadIds = $u(LeadAssignment::query(), 'user_id')->whereBetween('assigned_at', [$from, $to])->distinct()->pluck('lead_id');
        $assignedWon = $assignedLeadIds->isEmpty() ? 0 : Lead::whereIn('id', $assignedLeadIds)->where('status', 'won')->count();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'new_leads' => $userIds === null ? Lead::whereBetween('created_at', [$from, $to])->count() : null,
            'leads_assigned' => $leadsAssigned,
            'owned_at_end' => $ownedAtEnd,
            'unique_leads_contacted' => $uniqueContacted,
            'calls_total' => (int) $calls->sum('total'),
            'calls_connected' => (int) $connected,
            'calls_no_answer' => (int) ($calls['no_answer']->total ?? 0),
            'calls_busy' => (int) ($calls['busy']->total ?? 0),
            'talk_seconds' => (int) $calls->sum('secs'),
            'whatsapp_activities' => (int) ($byType['whatsapp'] ?? 0),
            'email_activities' => (int) ($byType['email'] ?? 0),
            'follow_ups_due' => $due,
            'follow_ups_completed' => $completed,
            'follow_ups_overdue' => $overdue,
            'meetings_booked' => $booked,
            'meetings_completed' => (int) ($meetingStatus['completed'] ?? 0),
            'meetings_cancelled' => (int) ($meetingStatus['cancelled'] ?? 0),
            'meetings_no_show' => (int) ($meetingStatus['no_show'] ?? 0),
            'meetings_upcoming' => (int) (($meetingStatus['scheduled'] ?? 0) + ($meetingStatus['rescheduled'] ?? 0)),
            'newly_qualified' => (int) ($transitions['qualified'] ?? 0),
            'won' => $won,
            'lost' => $lost,
            'revenue' => $revenue,
            'avg_response_minutes' => $response['avg'],
            'median_response_minutes' => $response['median'],
            'responded' => $response['responded'],
            'not_responded' => $response['pending'],
            'win_rate' => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : null,
            'assigned_conversion' => $assignedLeadIds->count() > 0 ? round($assignedWon / $assignedLeadIds->count() * 100, 1) : null,
        ];
    }

    /** Minutes from assignment to the owner's first contact activity, for assignments made in the period. */
    public function responseTimes(CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): array
    {
        $contactTypes = config('crm.contact_activity_types');

        $rows = LeadAssignment::query()
            ->whereBetween('assigned_at', [$from, $to])
            ->when($userIds === null, fn ($q) => $q->whereNotNull('user_id'), fn ($q) => $q->whereIn('user_id', $userIds))
            ->where('method', '!=', 'import')
            ->select('lead_assignments.id', 'lead_assignments.assigned_at')
            ->selectSub(
                LeadActivity::query()->selectRaw('MIN(occurred_at)')
                    ->whereColumn('lead_activities.lead_id', 'lead_assignments.lead_id')
                    ->whereColumn('lead_activities.user_id', 'lead_assignments.user_id')
                    ->whereColumn('lead_activities.occurred_at', '>=', 'lead_assignments.assigned_at')
                    ->whereIn('lead_activities.type', $contactTypes),
                'first_contact_at'
            )
            ->get();

        $minutes = $rows->filter(fn ($r) => $r->first_contact_at)
            ->map(fn ($r) => Carbon::parse($r->assigned_at)->diffInMinutes(Carbon::parse($r->first_contact_at)))
            ->sort()->values();

        $median = null;
        if ($minutes->count()) {
            $mid = intdiv($minutes->count(), 2);
            $median = $minutes->count() % 2 ? $minutes[$mid] : ($minutes[$mid - 1] + $minutes[$mid]) / 2;
        }

        return [
            'avg' => $minutes->count() ? round($minutes->avg(), 1) : null,
            'median' => $median !== null ? round($median, 1) : null,
            'responded' => $minutes->count(),
            'pending' => $rows->count() - $minutes->count(),
        ];
    }

    /** Daily series for simple trend bars (max ~92 days). */
    public function dailySeries(CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): array
    {
        $from = Carbon::instance($from)->startOfDay();
        $to = Carbon::instance($to)->endOfDay();
        $u = fn ($q, string $col) => $userIds === null ? $q : $q->whereIn($col, $userIds);
        $day = fn (string $col) => DB::raw("DATE({$col}) as d");

        $assigned = $u(LeadAssignment::query(), 'user_id')->whereNotNull('user_id')->whereBetween('assigned_at', [$from, $to])
            ->select($day('assigned_at'), DB::raw('COUNT(*) as c'))->groupBy('d')->pluck('c', 'd');
        $calls = $u(LeadActivity::query(), 'user_id')->where('type', 'call')->whereBetween('occurred_at', [$from, $to])
            ->select($day('occurred_at'), DB::raw('COUNT(*) as c'))->groupBy('d')->pluck('c', 'd');
        $meetings = $u(Meeting::query(), 'assigned_agent_id')->where('status', 'completed')->whereBetween('starts_at', [$from, $to])
            ->select($day('starts_at'), DB::raw('COUNT(*) as c'))->groupBy('d')->pluck('c', 'd');
        $won = $u(LeadStatusChange::query(), 'owner_id')->where('field', 'status')->where('to_value', 'won')->whereBetween('changed_at', [$from, $to])
            ->select($day('changed_at'), DB::raw('COUNT(DISTINCT lead_id) as c'))->groupBy('d')->pluck('c', 'd');

        $series = [];
        for ($d = $from->copy(); $d->lte($to) && count($series) < 92; $d->addDay()) {
            $k = $d->toDateString();
            $series[$k] = [
                'assigned' => (int) ($assigned[$k] ?? 0),
                'calls' => (int) ($calls[$k] ?? 0),
                'meetings' => (int) ($meetings[$k] ?? 0),
                'won' => (int) ($won[$k] ?? 0),
            ];
        }

        return $series;
    }

    /** One row per sales user (for the team table and CSV export). */
    public function perAgent(CarbonInterface $from, CarbonInterface $to, ?array $onlyUserIds = null): array
    {
        $users = User::whereIn('role', config('crm.assignable_roles'))
            ->when($onlyUserIds, fn ($q) => $q->whereIn('id', $onlyUserIds))
            ->orderByDesc('is_active')->orderBy('name')->get();

        return $users->map(fn (User $user) => ['user' => $user, 'metrics' => $this->metrics($from, $to, [$user->id])])->all();
    }

    /** Resolve a period keyword to a date range. */
    public static function range(string $period, ?string $date = null, ?string $from = null, ?string $to = null): array
    {
        $tz = config('app.timezone');
        $ref = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);

        return match ($period) {
            'weekly' => [$ref->copy()->startOfWeek(), $ref->copy()->endOfWeek()],
            'monthly' => [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()],
            'custom' => [Carbon::parse($from ?: $ref->toDateString(), $tz)->startOfDay(), Carbon::parse($to ?: $ref->toDateString(), $tz)->endOfDay()],
            default => [$ref->copy()->startOfDay(), $ref->copy()->endOfDay()],
        };
    }

    /** Same-length period right before [from, to] — for trend comparison. */
    public static function previous(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = Carbon::instance($from)->startOfDay()->diffInDays(Carbon::instance($to)->endOfDay()) ;
        $days = (int) ceil($days);
        $prevTo = Carbon::instance($from)->copy()->subDay()->endOfDay();

        return [$prevTo->copy()->subDays(max($days - 1, 0))->startOfDay(), $prevTo];
    }
}
