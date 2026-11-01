@extends('layouts.app')
@section('title', 'Follow-ups')

@section('content')
<div class="page-head">
    <h1>Follow-ups</h1>
    <span class="muted">{{ $team ? 'Whole team' : 'Your follow-ups' }} · manual call-backs and reminders</span>
</div>

<div class="grid grid-3">
    <a class="stat red" href="{{ route('tasks.index', array_merge(request()->except('tab', 'page'), ['tab' => 'due'])) }}" style="text-decoration:none"><div class="num">{{ $counts['overdue'] }}</div><div class="label">Overdue</div></a>
    <a class="stat warn" href="{{ route('tasks.index', array_merge(request()->except('tab', 'page'), ['tab' => 'due'])) }}" style="text-decoration:none"><div class="num">{{ $counts['today'] }}</div><div class="label">Due later today</div></a>
    <a class="stat teal" href="{{ route('tasks.index', array_merge(request()->except('tab', 'page'), ['tab' => 'upcoming'])) }}" style="text-decoration:none"><div class="num">{{ $counts['upcoming'] }}</div><div class="label">Upcoming</div></a>
</div>

<div class="card mt">
    <div class="card-head">
        <div class="tabs mb-0" style="margin-bottom:0;border:0">
            @foreach(['due' => 'Overdue & today', 'upcoming' => 'Upcoming', 'done' => 'Completed'] as $k => $v)
                <a href="{{ route('tasks.index', array_merge(request()->except('tab', 'page'), ['tab' => $k])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}</a>
            @endforeach
        </div>
        @if($team)
        <form method="GET" class="flex">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <select name="agent" class="form-control w-auto" onchange="this.form.submit()" aria-label="Agent">
                <option value="">All agents</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </form>
        @endif
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Due</th><th>Lead</th><th>Follow-up</th>@if($team)<th>Owner</th>@endif<th></th></tr></thead>
            <tbody>
            @forelse($tasks as $t)
                <tr class="{{ $t->is_overdue ? 'is-overdue' : '' }}">
                    <td><strong class="{{ $t->is_overdue ? 'overdue' : '' }}">{{ $t->due_at->format('d M, h:i A') }}</strong><br><small>{{ $t->status === 'done' ? 'done '.$t->completed_at?->diffForHumans() : $t->due_at->diffForHumans() }}</small></td>
                    <td>
                        @if($t->lead)<a href="{{ route('leads.show', $t->lead) }}"><strong>{{ $t->lead->display_name }}</strong></a>
                            @if($t->lead->temperature)<span class="badge badge-{{ $t->lead->temperature }}">{{ $t->lead->temperature }}</span>@endif
                            <br><small>{{ $t->lead->phone }} · {{ \App\Models\Lead::STATUSES[$t->lead->status] ?? $t->lead->status }}</small>@endif
                    </td>
                    <td>{{ $t->title }} @if($t->notes)<br><small>{{ \Illuminate\Support\Str::limit($t->notes, 100) }}</small>@endif</td>
                    @if($team)<td>{{ $t->user?->name ?? '—' }}</td>@endif
                    <td class="text-right">
                        @if($t->status === 'open')
                            <div class="btn-group" style="justify-content:flex-end">
                                @if($t->lead?->phone)<a href="tel:+{{ $t->lead->phone }}" class="btn btn-outline btn-sm">Call</a>@endif
                                <a href="{{ route('leads.show', $t->lead_id) }}#activity" class="btn btn-outline btn-sm">Log call</a>
                                <form method="POST" action="{{ route('tasks.update', $t) }}" class="inline-form">@csrf<input type="hidden" name="action" value="complete"><button class="btn btn-sm">Done</button></form>
                                <form method="POST" action="{{ route('tasks.update', $t) }}" class="inline-form" data-confirm="Cancel this follow-up?">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-red btn-sm">Cancel</button></form>
                            </div>
                        @else
                            <span class="badge badge-{{ $t->status }}">{{ $t->status }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $team ? 5 : 4 }}" class="empty">{{ $tab === 'done' ? 'No completed follow-ups yet.' : 'No follow-ups here. 🎉' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $tasks->links() }}
</div>
@endsection
