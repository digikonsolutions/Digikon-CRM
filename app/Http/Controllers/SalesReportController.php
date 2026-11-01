<?php

namespace App\Http\Controllers;

use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesReportService;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    public function index(Request $request, SalesReportService $reports)
    {
        [$from, $to, $userIds, $team, $period] = $this->scope($request);

        $metrics = $reports->metrics($from, $to, $userIds);
        [$pFrom, $pTo] = SalesReportService::previous($from, $to);
        $previous = $reports->metrics($pFrom, $pTo, $userIds);

        $rangeDays = $from->diffInDays($to) + 1;

        return view('reports.index', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'prevFrom' => $pFrom,
            'prevTo' => $pTo,
            'metrics' => $metrics,
            'previous' => $previous,
            'series' => $rangeDays > 1 && $rangeDays <= 92 ? $reports->dailySeries($from, $to, $userIds) : [],
            'agentRows' => $team && ! $request->agent ? $reports->perAgent($from, $to) : [],
            'team' => $team,
            'agents' => $team ? User::whereIn('role', config('crm.assignable_roles'))->orderBy('name')->get() : collect(),
            'definitions' => SalesReportService::DEFINITIONS,
            'snapshots' => ReportSnapshot::where('scope_key', $team ? 'team' : 'user:'.$request->user()->id)->latest('period_start')->limit(10)->get(),
        ]);
    }

    public function export(Request $request, SalesReportService $reports)
    {
        [$from, $to, $userIds, $team] = $this->scope($request);
        AuditLogger::log('reports.exported', null, [], ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'team' => $team]);

        $rows = $team && ! $request->agent
            ? $reports->perAgent($from, $to)
            : [['user' => $userIds ? User::find($userIds[0]) : null, 'metrics' => $reports->metrics($from, $to, $userIds)]];
        if ($team && ! $request->agent) {
            $rows[] = ['user' => null, 'metrics' => $reports->metrics($from, $to, null)];
        }

        $cols = ['leads_assigned', 'owned_at_end', 'unique_leads_contacted', 'calls_total', 'calls_connected', 'calls_no_answer', 'calls_busy',
            'talk_seconds', 'whatsapp_activities', 'email_activities', 'follow_ups_due', 'follow_ups_completed', 'follow_ups_overdue',
            'meetings_booked', 'meetings_completed', 'meetings_cancelled', 'meetings_no_show', 'newly_qualified', 'won', 'lost',
            'avg_response_minutes', 'median_response_minutes', 'win_rate', 'assigned_conversion'];

        return response()->streamDownload(function () use ($rows, $cols, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['Agent', 'From', 'To'], $cols, ['revenue']));
            foreach ($rows as $row) {
                $m = $row['metrics'];
                $revenue = collect($m['revenue'])->map(fn ($v, $c) => "{$c} {$v}")->implode('; ');
                fputcsv($out, array_merge(
                    [$row['user']?->name ?? 'TEAM TOTAL', $from->toDateString(), $to->toDateString()],
                    array_map(fn ($c) => $m[$c] ?? '', $cols),
                    [$revenue]
                ));
            }
            fclose($out);
        }, 'sales-report-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Agents only ever get their own numbers; the agent filter is ignored for them. */
    protected function scope(Request $request): array
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission('reports.view-own', 'reports.view-team'), 403);
        $request->validate([
            'period' => 'nullable|in:daily,weekly,monthly,custom',
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'agent' => 'nullable|integer',
        ]);

        $period = $request->query('period', 'daily');
        [$from, $to] = SalesReportService::range($period, $request->query('date'), $request->query('from'), $request->query('to'));
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        $team = $user->hasPermission('reports.view-team');
        $userIds = $team ? ($request->agent ? [(int) $request->agent] : null) : [$user->id];

        return [$from, $to, $userIds, $team, $period];
    }
}
