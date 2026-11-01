@extends('layouts.app')
@section('title', $scope === 'mine' ? 'My Leads' : ($scope === 'unassigned' ? 'Unassigned Leads' : 'Leads'))

@php($u = auth()->user())
@php($canAssign = $u->hasPermission('leads.assign'))

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $scope === 'mine' ? 'My Leads' : ($scope === 'unassigned' ? 'Unassigned Leads' : 'Leads') }} <small>({{ $leads->total() }})</small></h1>
        @if($u->hasPermission('leads.view-all') || $canAssign)
        <div class="seg mt" role="tablist">
            @if($u->hasPermission('leads.view-all'))<a href="{{ route('leads.index', array_merge(request()->except('scope', 'page'), ['scope' => 'all'])) }}" class="{{ $scope === 'all' ? 'active' : '' }}">All</a>@endif
            @if($u->canOwnLeads())<a href="{{ route('leads.index', array_merge(request()->except('scope', 'page'), ['scope' => 'mine'])) }}" class="{{ $scope === 'mine' ? 'active' : '' }}">My leads</a>@endif
            @if($canAssign)<a href="{{ route('leads.index', array_merge(request()->except('scope', 'page', 'agent'), ['scope' => 'unassigned'])) }}" class="{{ $scope === 'unassigned' ? 'active' : '' }}">Unassigned @if($unassignedCount)<span class="badge badge-high">{{ $unassignedCount }}</span>@endif</a>@endif
            <a href="{{ route('leads.index', array_merge(request()->except('page', 'overdue'), ['overdue' => 1])) }}" class="{{ request('overdue') ? 'active' : '' }}">Overdue follow-ups</a>
        </div>
        @endif
    </div>
    <div class="btn-group">
        @if($canAssign && $scope === 'unassigned' && $unassignedCount)
            <form method="POST" action="{{ route('leads.distribute') }}" class="inline-form" data-confirm="Distribute all {{ $unassignedCount }} unassigned open leads using the distribution rule from Settings?">
                @csrf <button class="btn btn-outline">Auto-distribute all</button>
            </form>
        @endif
        @if($u->hasPermission('leads.export'))
            <a href="{{ route('leads.export', request()->query()) }}" class="btn btn-outline">Export CSV</a>
        @endif
        <a href="{{ route('leads.pipeline', $scope === 'mine' ? ['scope' => 'mine'] : []) }}" class="btn btn-outline">Pipeline view</a>
        @can('create', \App\Models\Lead::class)<a href="{{ route('leads.create') }}" class="btn">+ New Lead</a>@endcan
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <div><label for="f-q">Search</label><input id="f-q" type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Name / phone / email"></div>
        <div>
            <label for="f-status">Status</label>
            <select id="f-status" name="status" class="form-control">
                <option value="">All</option>
                @foreach(\App\Models\Lead::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-temp">Temperature</label>
            <select id="f-temp" name="temperature" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.temperatures') as $k => $v)<option value="{{ $k }}" @selected(request('temperature') === $k)>{{ $v }}</option>@endforeach
                <option value="none" @selected(request('temperature') === 'none')>Not set</option>
            </select>
        </div>
        <div>
            <label for="f-qual">Qualification</label>
            <select id="f-qual" name="qualification" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.qualifications') as $k => $v)<option value="{{ $k }}" @selected(request('qualification') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-source">Source</label>
            <select id="f-source" name="source" class="form-control">
                <option value="">All</option>
                @foreach(\App\Models\Lead::SOURCES as $k => $v)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-priority">Priority</label>
            <select id="f-priority" name="priority" class="form-control">
                <option value="">All</option>
                @foreach(\App\Models\Lead::PRIORITIES as $k => $v)<option value="{{ $k }}" @selected(request('priority') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        @if($campaigns->count())
        <div>
            <label for="f-camp">Campaign</label>
            <select id="f-camp" name="campaign" class="form-control">
                <option value="">All</option>
                @foreach($campaigns as $c)<option value="{{ $c }}" @selected(request('campaign') === $c)>{{ \Illuminate\Support\Str::limit($c, 40) }}</option>@endforeach
            </select>
        </div>
        @endif
        @if($tags->count())
        <div>
            <label for="f-tag">Tag</label>
            <select id="f-tag" name="tag" class="form-control">
                <option value="">All</option>
                @foreach($tags as $t)<option value="{{ $t->id }}" @selected(request('tag') == $t->id)>{{ $t->name }}</option>@endforeach
            </select>
        </div>
        @endif
        @if($u->hasPermission('leads.view-all') && $scope === 'all')
        <div>
            <label for="f-agent">Owner</label>
            <select id="f-agent" name="agent" class="form-control">
                <option value="">All</option>
                <option value="none" @selected(request('agent') === 'none')>Unassigned</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        @endif
        <div><label for="f-svc">Service</label><input id="f-svc" type="text" name="service" class="form-control" value="{{ request('service') }}" placeholder="Website, SEO..."></div>
        <div><label for="f-from">Created from</label><input id="f-from" type="date" name="date_from" class="form-control" value="{{ request('date_from') }}"></div>
        <div><label for="f-to">to</label><input id="f-to" type="date" name="date_to" class="form-control" value="{{ request('date_to') }}"></div>
        <div>
            <label for="f-sort">Sort</label>
            <select id="f-sort" name="sort" class="form-control">
                <option value="">Newest first</option>
                <option value="score" @selected(request('sort') === 'score')>Lead score</option>
                <option value="follow_up" @selected(request('sort') === 'follow_up')>Next follow-up</option>
            </select>
        </div>
        <div>
            @if(request('overdue'))<input type="hidden" name="overdue" value="1">@endif
            <button class="btn">Filter</button>
            <a href="{{ route('leads.index', ['scope' => $scope]) }}" class="btn btn-outline">Reset</a>
        </div>
    </form>
</div>

@if($canAssign)
<form method="POST" action="{{ route('leads.bulk-assign') }}" id="bulk-form">
    @csrf
    <div class="bulk-bar" id="bulk-bar" role="region" aria-label="Bulk actions">
        <strong><span id="bulk-count">0</span> selected</strong>
        <select name="action" class="form-control" id="bulk-action" aria-label="Bulk action">
            <option value="assign">Assign to agent</option>
            <option value="round_robin">Distribute — round robin</option>
            <option value="least_leads">Distribute — workload based</option>
            <option value="unassign">Unassign</option>
        </select>
        <select name="assigned_to" class="form-control" id="bulk-agent" aria-label="Agent">
            <option value="">Choose agent…</option>
            @foreach($assignableAgents as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->role_label }})</option>@endforeach
        </select>
        <input type="text" name="reason" class="form-control" placeholder="Reason (optional)" maxlength="500" aria-label="Reason">
        <button class="btn btn-sm">Apply</button>
        <button type="button" class="btn btn-outline btn-sm" id="bulk-clear">Clear</button>
    </div>
</form>
@endif

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                @if($canAssign)<th class="check-cell"><input type="checkbox" id="check-all" aria-label="Select all on this page"></th>@endif
                <th>Lead</th><th>Contact</th><th>Source</th><th>Owner</th><th>Status</th><th>Segment</th><th>Next follow-up</th><th>Last activity</th>
            </tr>
            </thead>
            <tbody>
            @forelse($leads as $lead)
                <tr class="{{ $lead->is_follow_up_overdue ? 'is-overdue' : '' }}">
                    @if($canAssign)<td class="check-cell"><input type="checkbox" name="lead_ids[]" value="{{ $lead->id }}" form="bulk-form" class="row-check" aria-label="Select {{ $lead->display_name }}"></td>@endif
                    <td>
                        <a href="{{ route('leads.show', $lead) }}"><strong>{{ $lead->display_name }}</strong></a>
                        @if($lead->priority !== 'normal')<span class="badge badge-{{ $lead->priority }}">{{ $lead->priority }}</span>@endif
                        @if($lead->service)<br><small>{{ $lead->service }}</small>@endif
                        @if($lead->tags->count())<br>@foreach($lead->tags->take(3) as $t)<span class="tag-chip"><span class="dot" style="background: {{ $t->color }}"></span>{{ $t->name }}</span>@endforeach @endif
                    </td>
                    <td>{{ $lead->phone }}<br><small>{{ $lead->email }}</small></td>
                    <td><span class="badge badge-{{ $lead->source }}">{{ $lead->source }}</span><br><small>{{ \Illuminate\Support\Str::limit($lead->campaign_name ?? $lead->utm_campaign, 28) }}</small></td>
                    <td>
                        {{ $lead->agent?->name ?? '— unassigned —' }}
                        @if($lead->assigned_at)<br><small>since {{ $lead->assigned_at->format('d M') }}</small>@endif
                    </td>
                    <td><span class="badge badge-{{ $lead->status }}">{{ $lead->status_label }}</span><br><small>{{ $lead->created_at->format('d M Y') }}</small></td>
                    <td>
                        @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@else<span class="badge badge-outline">—</span>@endif
                        <span class="score {{ $lead->score >= 60 ? 's-high' : ($lead->score >= 30 ? 's-mid' : '') }}" title="Lead score">{{ $lead->score }}</span>
                        @if($lead->qualification !== 'pending')<br><small>{{ $lead->qualification_label }}</small>@endif
                    </td>
                    <td>
                        @if($lead->next_follow_up_at)
                            <small class="{{ $lead->is_follow_up_overdue ? 'overdue' : '' }}">{{ $lead->next_follow_up_at->format('d M, h:i A') }}{{ $lead->is_follow_up_overdue ? ' · overdue' : '' }}</small>
                        @else <small>—</small> @endif
                    </td>
                    <td>
                        <small>{{ $lead->last_activity_at ? \Illuminate\Support\Carbon::parse($lead->last_activity_at)->diffForHumans() : '—' }}</small>
                        @if($lead->latestMessage)<br><small title="{{ $lead->latestMessage->body }}">{{ $lead->latestMessage->direction === 'in' ? '💬 ' : '↗ ' }}{{ \Illuminate\Support\Str::limit($lead->latestMessage->body, 28) }}</small>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canAssign ? 9 : 8 }}" class="empty">{{ $scope === 'unassigned' ? 'No unassigned leads — every lead has an owner.' : 'No leads found for these filters.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $leads->links() }}
</div>
@endsection

@if($canAssign)
@push('scripts')
<script>
    const checks = () => [...document.querySelectorAll('.row-check')];
    const bar = document.getElementById('bulk-bar');
    const sync = () => {
        const n = checks().filter(c => c.checked).length;
        document.getElementById('bulk-count').textContent = n;
        bar.classList.toggle('show', n > 0);
    };
    document.getElementById('check-all')?.addEventListener('change', e => { checks().forEach(c => c.checked = e.target.checked); sync(); });
    checks().forEach(c => c.addEventListener('change', sync));
    document.getElementById('bulk-clear').addEventListener('click', () => { checks().forEach(c => c.checked = false); document.getElementById('check-all').checked = false; sync(); });
    const action = document.getElementById('bulk-action'), agent = document.getElementById('bulk-agent');
    action.addEventListener('change', () => agent.style.display = action.value === 'assign' ? '' : 'none');
    document.getElementById('bulk-form').addEventListener('submit', e => {
        const n = checks().filter(c => c.checked).length;
        if (action.value === 'assign' && !agent.value) { e.preventDefault(); alert('Choose an agent first.'); return; }
        if (!confirm(`Apply "${action.options[action.selectedIndex].text}" to ${n} lead(s)?`)) e.preventDefault();
    });
</script>
@endpush
@endif
