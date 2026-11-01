@extends('layouts.app')
@section('title', 'Meetings Calendar')

@php
    $q = fn (array $extra) => route('meetings.index', array_merge(request()->only('agent', 'status', 'type'), ['view' => $view, 'date' => $date->toDateString()], $extra));
    [$prev, $next] = match ($view) {
        'day' => [$date->copy()->subDay(), $date->copy()->addDay()],
        'week' => [$date->copy()->subWeek(), $date->copy()->addWeek()],
        'agenda' => [$date->copy()->subDays(30), $date->copy()->addDays(30)],
        default => [$date->copy()->startOfMonth()->subMonthNoOverflow(), $date->copy()->startOfMonth()->addMonthNoOverflow()],
    };
    $prev = $prev->toDateString();
    $next = $next->toDateString();
    $title = match ($view) {
        'day' => $date->format('l, d M Y'),
        'week' => $from->format('d M').' – '.$to->format('d M Y'),
        'agenda' => 'Next 30 days from '.$date->format('d M'),
        default => $date->format('F Y'),
    };
@endphp

@section('content')
<div class="page-head">
    <h1>Meetings Calendar</h1>
    <span class="muted">{{ $team ? 'All team meetings' : 'Your meetings' }} · times in {{ config('app.timezone') }}</span>
</div>

@if($needsOutcome->count())
<div class="alert alert-info">
    <strong>{{ $needsOutcome->count() }} past meeting(s) need an outcome:</strong>
    @foreach($needsOutcome->take(6) as $m)<a href="{{ route('meetings.show', $m) }}">{{ $m->lead?->display_name }} ({{ $m->starts_at->format('d M') }})</a>@if(! $loop->last), @endif @endforeach
</div>
@endif

<div class="card">
    <div class="cal-toolbar">
        <div class="flex">
            <a href="{{ $q(['date' => $prev]) }}" class="btn btn-outline btn-sm" aria-label="Previous">&larr;</a>
            <a href="{{ $q(['date' => now()->toDateString()]) }}" class="btn btn-outline btn-sm">Today</a>
            <a href="{{ $q(['date' => $next]) }}" class="btn btn-outline btn-sm" aria-label="Next">&rarr;</a>
            <h2 style="margin-left:8px">{{ $title }}</h2>
        </div>
        <div class="seg">
            @foreach(['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $k => $v)
                <a href="{{ $q(['view' => $k]) }}" class="{{ $view === $k ? 'active' : '' }}">{{ $v }}</a>
            @endforeach
        </div>
    </div>
    <form method="GET" class="filters" style="margin-bottom:16px">
        <input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}">
        @if($team)
        <div>
            <label for="c-agent">Agent</label>
            <select id="c-agent" name="agent" class="form-control">
                <option value="">All agents</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        @endif
        <div>
            <label for="c-status">Status</label>
            <select id="c-status" name="status" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.meeting_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="c-type">Type</label>
            <select id="c-type" name="type" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.meeting_types') as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div><button class="btn">Filter</button></div>
    </form>

    @if($view === 'month')
        <div class="cal-grid">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="dow">{{ $d }}</div>@endforeach
            @for($d = $from->copy(); $d->lte($to); $d->addDay())
                @php($items = $byDay[$d->toDateString()] ?? collect())
                <div class="day {{ $d->month !== $date->month ? 'other' : '' }} {{ $d->isToday() ? 'today' : '' }}">
                    <div class="dnum"><a href="{{ $q(['view' => 'day', 'date' => $d->toDateString()]) }}">{{ $d->day }}</a>@if($items->count() > 3)<small>{{ $items->count() }}</small>@endif</div>
                    @foreach($items->take(3) as $m)
                        <a class="ev {{ $m->status }}" href="{{ route('meetings.show', $m) }}" title="{{ $m->starts_at->format('h:i A') }} {{ $m->title }} — {{ $m->agent?->name }}">{{ $m->starts_at->format('g:i') }} {{ $m->lead?->display_name }}</a>
                    @endforeach
                    @if($items->count() > 3)<a class="ev" href="{{ $q(['view' => 'day', 'date' => $d->toDateString()]) }}">+{{ $items->count() - 3 }} more</a>@endif
                </div>
            @endfor
        </div>
    @elseif($view === 'week')
        <div class="week-grid">
            @for($d = $from->copy(); $d->lte($to); $d->addDay())
                <div class="wcol {{ $d->isToday() ? 'today' : '' }}">
                    <h3><a href="{{ $q(['view' => 'day', 'date' => $d->toDateString()]) }}">{{ $d->format('D d M') }}</a></h3>
                    @forelse($byDay[$d->toDateString()] ?? [] as $m)
                        @include('meetings._card', ['m' => $m])
                    @empty
                        <small class="muted">—</small>
                    @endforelse
                </div>
            @endfor
        </div>
    @else
        @forelse($byDay as $day => $items)
            <h3 class="mt">{{ \Illuminate\Support\Carbon::parse($day)->format('l, d M Y') }}</h3>
            @foreach($items as $m)
                @include('meetings._card', ['m' => $m, 'wide' => true])
            @endforeach
        @empty
            <p class="empty">No meetings {{ $view === 'day' ? 'on this day' : 'in the next 30 days' }}. Book meetings from a lead's page.</p>
        @endforelse
    @endif
</div>
@endsection
