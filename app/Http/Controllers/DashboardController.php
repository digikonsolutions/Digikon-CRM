<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadFollowup;
use App\Models\LeadSourceEvent;
use App\Models\LeadTask;
use App\Models\Meeting;
use App\Models\Proposal;
use App\Models\User;
use App\Services\SalesReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesReportService $reports)
    {
        $user = $request->user();
        $leads = fn () => Lead::visibleTo($user);
        $teamView = $user->hasPermission('reports.view-team');
        $mine = $teamView ? null : [$user->id];

        $stats = [
            'total' => $leads()->count(),
            'today' => $leads()->whereDate('created_at', today())->count(),
            'new' => $leads()->where('status', 'new')->count(),
            'qualified' => $leads()->where('status', 'qualified')->count(),
            'won' => $leads()->where('status', 'won')->count(),
            'hot' => $leads()->open()->where('temperature', 'hot')->count(),
            'unassigned' => $user->hasPermission('leads.assign') ? Lead::whereNull('assigned_to')->open()->count() : 0,
            'due_followups' => LeadFollowup::where('status', 'pending')->whereHas('lead', fn ($q) => $q->visibleTo($user))->count(),
            'overdue_tasks' => LeadTask::open()->where('due_at', '<', now())->whereHas('lead', fn ($q) => $q->visibleTo($user))
                ->when(! $teamView, fn ($q) => $q->where('user_id', $user->id))->count(),
            'proposals_sent' => Proposal::where('status', 'sent')
                ->when(! $user->hasPermission('leads.view-all'), fn ($q) => $q->where('user_id', $user->id))->count(),
            'pending_reviews' => $user->hasPermission('lead-analyses.review')
                ? LeadAnalysis::where('status', 'completed')->whereIn('review_status', ['pending', 'auto_applied'])->whereHas('lead', fn ($q) => $q->visibleTo($user))->count() : 0,
            'failed_ingestion' => $user->hasPermission('lead-sources.manage') ? LeadSourceEvent::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count() : 0,
        ];

        $todayMetrics = $user->hasAnyPermission('reports.view-own', 'reports.view-team') ? $reports->metrics(today(), today(), $mine) : null;
        $closedTotal = $leads()->whereIn('status', Lead::CLOSED_STATUSES)->count();

        $byStatus = $leads()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $bySource = $leads()->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source');
        $byTemperature = $leads()->open()->selectRaw("COALESCE(temperature, 'none') as t, count(*) as total")->groupBy('t')->pluck('total', 't');
        $byCampaign = $leads()->whereNotNull('campaign_name')->selectRaw('campaign_name, count(*) as total, SUM(CASE WHEN status = \'won\' THEN 1 ELSE 0 END) as won')
            ->groupBy('campaign_name')->orderByDesc('total')->limit(6)->get();

        $agents = $teamView
            ? User::assignable()->withCount([
                'leads',
                'leads as open_leads_count' => fn ($q) => $q->whereNotIn('status', Lead::CLOSED_STATUSES),
                'leads as won_leads_count' => fn ($q) => $q->where('status', 'won'),
                'activities as calls_today_count' => fn ($q) => $q->where('type', 'call')->whereBetween('occurred_at', [today(), today()->endOfDay()]),
                'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', 'open')->where('due_at', '<', now()),
            ])->orderBy('name')->get()
            : collect();

        $myTasks = LeadTask::with('lead:id,name,phone')->open()->where('user_id', $user->id)
            ->where('due_at', '<=', now()->endOfDay())->orderBy('due_at')->limit(8)->get();
        $myMeetings = Meeting::visibleTo($user)->with('lead:id,name')->active()
            ->when($teamView, fn ($q) => $q->with('agent:id,name'))
            ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(2)->endOfDay()])->orderBy('starts_at')->limit(8)->get();

        $recent = $leads()->with('agent')->latest()->limit(8)->get();

        return view('dashboard', compact('stats', 'todayMetrics', 'closedTotal', 'byStatus', 'bySource', 'byTemperature', 'byCampaign', 'agents', 'recent', 'myTasks', 'myMeetings', 'teamView'));
    }
}
