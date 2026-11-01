<?php

namespace App\Services;

use App\Models\LeadTask;
use App\Models\Meeting;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CrmAlert;
use Illuminate\Support\Carbon;

/**
 * Scheduled automation. Every method is idempotent: a "*_notified_at"/"reminder_sent_at"
 * flag is claimed atomically (UPDATE ... WHERE flag IS NULL) before sending, so re-running
 * or overlapping runs never send duplicates.
 */
class CrmAutomation
{
    public function __construct(protected SalesReportService $reports) {}

    public function meetingReminders(): int
    {
        $minutes = (int) Setting::get('meeting_reminder_minutes', 30);
        $sent = 0;

        $due = Meeting::with(['lead:id,name,phone', 'agent'])->active()->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes($minutes)])->get();

        foreach ($due as $meeting) {
            if (! Meeting::whereKey($meeting->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()])) {
                continue; // another run claimed it
            }
            if ($meeting->agent) {
                $sent += Notifier::send([$meeting->agent], 'meeting_reminder', new CrmAlert('meeting_reminder',
                    'Meeting at '.$meeting->starts_at->format('h:i A').': '.$meeting->lead?->display_name,
                    $meeting->type_label.($meeting->meeting_link ? ' · '.$meeting->meeting_link : ($meeting->location ? ' · '.$meeting->location : '')),
                    route('meetings.show', $meeting)));
            }
        }

        return $sent;
    }

    /** Meetings that ended 30+ minutes ago and still have no outcome. */
    public function missedMeetings(): int
    {
        $count = 0;
        $missed = Meeting::with(['lead:id,name', 'agent'])->active()->whereNull('missed_notified_at')
            ->where('ends_at', '<', now()->subMinutes(30))->where('ends_at', '>', now()->subDays(7))->get();

        foreach ($missed as $meeting) {
            if (! Meeting::whereKey($meeting->id)->whereNull('missed_notified_at')->update(['missed_notified_at' => now()])) {
                continue;
            }
            if ($meeting->agent) {
                $count += Notifier::send([$meeting->agent], 'meeting_missed', new CrmAlert('meeting_missed',
                    'Update meeting outcome: '.$meeting->lead?->display_name,
                    'Meeting on '.$meeting->starts_at->format('d M, h:i A').' has no outcome yet. Mark it Completed or No-show.',
                    route('meetings.show', $meeting)));
            }
        }

        return $count;
    }

    /** One alert per agent with the number of newly overdue follow-ups. */
    public function overdueFollowUps(): int
    {
        $tasks = LeadTask::open()->whereNull('overdue_notified_at')->where('due_at', '<', now())->whereNotNull('user_id')->get(['id', 'user_id']);
        $sent = 0;

        foreach ($tasks->groupBy('user_id') as $userId => $group) {
            $claimed = LeadTask::whereIn('id', $group->pluck('id'))->whereNull('overdue_notified_at')->update(['overdue_notified_at' => now()]);
            if (! $claimed || ! ($user = User::find($userId))) {
                continue;
            }
            $sent += Notifier::send([$user], 'follow_up_overdue', new CrmAlert('follow_up_overdue',
                "{$claimed} follow-up(s) overdue", 'Open your follow-up list and call these leads.', route('tasks.index')));
        }

        return $sent;
    }

    /**
     * Saves snapshots for the given day (default yesterday): daily always, weekly on Sundays,
     * monthly on the last day of the month. updateOrCreate on a unique key → safe to re-run.
     */
    public function snapshotReports(?string $date = null): array
    {
        $day = $date ? Carbon::parse($date, config('app.timezone')) : Carbon::yesterday(config('app.timezone'));
        $periods = ['daily' => [$day->copy()->startOfDay(), $day->copy()->endOfDay()]];
        if ($day->isSunday()) {
            $periods['weekly'] = [$day->copy()->startOfWeek(), $day->copy()->endOfWeek()];
        }
        if ($day->isLastOfMonth()) {
            $periods['monthly'] = [$day->copy()->startOfMonth(), $day->copy()->endOfMonth()];
        }

        $users = User::whereIn('role', config('crm.assignable_roles'))->where('is_active', true)->get();
        $saved = 0;
        $notified = 0;

        foreach ($periods as $period => [$from, $to]) {
            $scopes = [['team', null, null]];
            foreach ($users as $u) {
                $scopes[] = ['user:'.$u->id, $u->id, [$u->id]];
            }
            foreach ($scopes as [$key, $userId, $ids]) {
                $values = ['period_end' => $to->toDateString(), 'user_id' => $userId, 'metrics' => $this->reports->metrics($from, $to, $ids), 'generated_at' => now()];
                $existing = ReportSnapshot::where('period', $period)->whereDate('period_start', $from->toDateString())->where('scope_key', $key)->first();
                $existing
                    ? $existing->update($values)
                    : ReportSnapshot::create($values + ['period' => $period, 'period_start' => $from->toDateString(), 'scope_key' => $key]);
                $saved++;
            }
        }

        // "Daily report available" — once per day (claimed via notified_at).
        $team = ReportSnapshot::where('period', 'daily')->whereDate('period_start', $day->toDateString())->where('scope_key', 'team')->first();
        if ($team && ReportSnapshot::whereKey($team->id)->whereNull('notified_at')->update(['notified_at' => now()])) {
            $m = $team->metrics;
            $notified = Notifier::send(Notifier::usersWithPermission('reports.view-team'), 'daily_report', new CrmAlert('daily_report',
                'Daily sales report — '.$day->format('D d M'),
                "Calls: {$m['calls_total']} · Leads contacted: {$m['unique_leads_contacted']} · Meetings held: {$m['meetings_completed']} · Won: {$m['won']}",
                route('reports.index', ['period' => 'daily', 'date' => $day->toDateString()])));
        }

        return compact('saved', 'notified');
    }
}
