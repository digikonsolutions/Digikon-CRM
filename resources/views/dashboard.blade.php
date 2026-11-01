@extends('layouts.app')
@section('title', 'Dashboard')

@php($u = auth()->user())

@section('content')
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <small>{{ now()->format('l, d M Y') }} · {{ $teamView ? 'Team overview' : 'Your day' }}</small>
    </div>
    <div class="btn-group">
        @if($stats['unassigned'] > 0)
            <a href="{{ route('leads.index', ['scope' => 'unassigned']) }}" class="btn btn-outline">{{ $stats['unassigned'] }} unassigned lead(s)</a>
        @endif
        @can('create', \App\Models\Lead::class)<a href="{{ route('leads.create') }}" class="btn">+ New Lead</a>@endcan
    </div>
</div>

@if($stats['failed_ingestion'] > 0)
    <div class="alert alert-error">{{ $stats['failed_ingestion'] }} lead(s) from Meta / landing pages failed to import in the last 7 days. <a href="{{ route('lead-sources.index', ['status' => 'failed']) }}">Check Lead Sources →</a></div>
@endif

<div class="grid grid-4">
    <div class="stat hero"><div class="num">{{ $stats['total'] }}</div><div class="label">{{ $u->hasPermission('leads.view-all') ? 'Total leads' : 'My leads' }}</div><div class="sub">{{ $stats['today'] }} new today</div></div>
    <div class="stat red"><div class="num">{{ $stats['hot'] }}</div><div class="label">Hot open leads</div><a class="cover" href="{{ route('leads.index', ['temperature' => 'hot', 'scope' => $u->hasPermission('leads.view-all') ? 'all' : 'mine']) }}" aria-label="Hot leads"></a></div>
    <div class="stat warn"><div class="num">{{ $stats['overdue_tasks'] }}</div><div class="label">Overdue follow-ups</div><a class="cover" href="{{ route('tasks.index') }}" aria-label="Overdue follow-ups"></a></div>
    <div class="stat green"><div class="num">{{ $stats['won'] }}</div><div class="label">Won deals</div><div class="sub">{{ $closedTotal ? round($stats['won'] / max($closedTotal, 1) * 100, 1) : 0 }}% win rate (won ÷ closed)</div></div>
</div>

@if($todayMetrics)
<div class="card mt">
    <div class="card-head"><h2>Today's activity</h2><a href="{{ route('reports.index') }}">Full report →</a></div>
    <div class="grid grid-6">
        <div class="stat compact"><div class="num">{{ $todayMetrics['calls_total'] }}</div><div class="label">Calls logged</div><div class="sub">{{ $todayMetrics['calls_connected'] }} connected</div></div>
        <div class="stat compact"><div class="num">{{ $todayMetrics['unique_leads_contacted'] }}</div><div class="label">Leads contacted</div></div>
        <div class="stat compact teal"><div class="num">{{ $todayMetrics['whatsapp_activities'] }}</div><div class="label">WhatsApps sent</div></div>
        <div class="stat compact"><div class="num">{{ $todayMetrics['follow_ups_completed'] }}/{{ $todayMetrics['follow_ups_due'] }}</div><div class="label">Follow-ups done / due</div></div>
        <div class="stat compact teal"><div class="num">{{ $todayMetrics['meetings_completed'] }}</div><div class="label">Meetings held</div><div class="sub">{{ $todayMetrics['meetings_booked'] }} booked today</div></div>
        <div class="stat compact green"><div class="num">{{ $todayMetrics['leads_assigned'] }}</div><div class="label">Leads assigned</div></div>
    </div>
</div>
@endif

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>My follow-ups today</h2><a href="{{ route('tasks.index') }}">All →</a></div>
        <ul class="list-plain">
            @forelse($myTasks as $t)
                <li class="flex between">
                    <div><a href="{{ route('leads.show', $t->lead_id) }}">{{ $t->lead?->display_name }}</a><br><small class="{{ $t->is_overdue ? 'overdue' : '' }}">{{ $t->due_at->format('d M, h:i A') }} · {{ $t->title }}</small></div>
                    <form method="POST" action="{{ route('tasks.update', $t) }}" class="inline-form">@csrf<input type="hidden" name="action" value="complete"><button class="btn btn-sm btn-outline">Done</button></form>
                </li>
            @empty
                <li class="muted">Nothing due today. 🎉</li>
            @endforelse
        </ul>
    </div>
    <div class="card">
        <div class="card-head"><h2>Upcoming meetings</h2><a href="{{ route('meetings.index', ['view' => 'agenda']) }}">Calendar →</a></div>
        <ul class="list-plain">
            @forelse($myMeetings as $m)
                <li><a href="{{ route('meetings.show', $m) }}">{{ $m->starts_at->isToday() ? 'Today' : $m->starts_at->format('D d M') }}, {{ $m->starts_at->format('h:i A') }}</a> — {{ $m->lead?->display_name }}
                    <br><small>{{ $m->type_label }}{{ $teamView && $m->agent ? ' · '.$m->agent->name : '' }}</small></li>
            @empty
                <li class="muted">No meetings in the next 2 days.</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="grid grid-3">
    <div class="card">
        <h2>Leads by Status</h2>
        @php($max = max($byStatus->max() ?? 0, 1))
        @foreach(\App\Models\Lead::STATUSES as $key => $label)
            <div class="bar-row">
                <div class="bar-label"><span>{{ $label }}</span><strong>{{ $byStatus[$key] ?? 0 }}</strong></div>
                <div class="bar"><span style="width: {{ (($byStatus[$key] ?? 0) / $max) * 100 }}%"></span></div>
            </div>
        @endforeach
    </div>
    <div class="card">
        <h2>Open leads by Temperature</h2>
        @php($max = max($byTemperature->max() ?? 0, 1))
        @foreach(['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold', 'none' => 'Not classified'] as $key => $label)
            <div class="bar-row">
                <div class="bar-label"><span>{{ $label }}</span><strong>{{ $byTemperature[$key] ?? 0 }}</strong></div>
                <div class="bar"><span style="width: {{ (($byTemperature[$key] ?? 0) / $max) * 100 }}%"></span></div>
            </div>
        @endforeach
        @if($stats['pending_reviews'])<p class="help mt"><a href="{{ route('analyses.index') }}">{{ $stats['pending_reviews'] }} AI analysis waiting for review →</a></p>@endif
    </div>
    <div class="card">
        <h2>Leads by Source</h2>
        @php($max = max($bySource->max() ?? 0, 1))
        @forelse($bySource as $source => $count)
            <div class="bar-row">
                <div class="bar-label"><span>{{ \App\Models\Lead::SOURCES[$source] ?? $source }}</span><strong>{{ $count }}</strong></div>
                <div class="bar"><span style="width: {{ $count / $max * 100 }}%"></span></div>
            </div>
        @empty
            <p class="muted">No leads yet.</p>
        @endforelse
    </div>
</div>

<div class="grid grid-main">
    <div class="card">
        <div class="card-head"><h2>Recent Leads</h2><a href="{{ route('leads.index') }}">View all →</a></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Source</th><th>Owner</th><th>Status</th><th>Time</th></tr></thead>
                <tbody>
                @forelse($recent as $lead)
                    <tr>
                        <td><a href="{{ route('leads.show', $lead) }}">{{ $lead->display_name }}</a> @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@endif<br><small>{{ $lead->phone }}</small></td>
                        <td><span class="badge badge-{{ $lead->source }}">{{ $lead->source }}</span></td>
                        <td>{{ $lead->agent?->name ?? '—' }}</td>
                        <td><span class="badge badge-{{ $lead->status }}">{{ $lead->status_label }}</span></td>
                        <td><small>{{ $lead->created_at->diffForHumans() }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No leads received yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        @if($teamView)
        <div class="card">
            <div class="card-head"><h2>Sales Agents</h2><a href="{{ route('reports.index', ['period' => 'monthly']) }}">Performance →</a></div>
            <table class="table">
                <thead><tr><th>Agent</th><th class="text-right">Open</th><th class="text-right">Calls today</th><th class="text-right">Overdue</th><th class="text-right">Won</th></tr></thead>
                <tbody>
                @foreach($agents as $agent)
                    <tr>
                        <td>{{ $agent->name }} @unless($agent->receives_leads)<small>(no auto leads)</small>@endunless</td>
                        <td class="text-right"><a href="{{ route('leads.index', ['agent' => $agent->id]) }}">{{ $agent->open_leads_count }}</a></td>
                        <td class="text-right">{{ $agent->calls_today_count }}</td>
                        <td class="text-right {{ $agent->overdue_tasks_count ? 'overdue' : '' }}">{{ $agent->overdue_tasks_count }}</td>
                        <td class="text-right">{{ $agent->won_leads_count }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="card">
            <h2>Top Campaigns</h2>
            @php($max = max($byCampaign->max('total') ?? 0, 1))
            @forelse($byCampaign as $row)
                <div class="bar-row">
                    <div class="bar-label"><span>{{ \Illuminate\Support\Str::limit($row->campaign_name, 30) }}</span><strong>{{ $row->total }} <small>· {{ $row->won }} won</small></strong></div>
                    <div class="bar"><span style="width: {{ $row->total / $max * 100 }}%"></span></div>
                </div>
            @empty
                <p class="muted">Leads from Meta / UTM campaigns will appear here.</p>
            @endforelse
            <p class="help">{{ $stats['due_followups'] }} automated WhatsApp follow-ups pending · {{ $stats['proposals_sent'] }} proposals awaiting reply</p>
        </div>
    </div>
</div>
@endsection
