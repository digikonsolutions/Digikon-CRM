@extends('layouts.app')
@section('title', 'Sales Reports')

@php
    $fmtMin = fn ($m) => $m === null ? '—' : ($m >= 1440 ? round($m / 1440, 1).' d' : ($m >= 60 ? round($m / 60, 1).' h' : round($m).' min'));
    $delta = function ($key, $lowerIsBetter = false) use ($metrics, $previous) {
        $a = $metrics[$key] ?? null; $b = $previous[$key] ?? null;
        if (! is_numeric($a) || ! is_numeric($b)) return null;
        $diff = $a - $b;
        if ($diff == 0) return ['flat', '= same as previous'];
        $good = $lowerIsBetter ? $diff < 0 : $diff > 0;
        return [$good ? 'up' : 'down', ($diff > 0 ? '▲ +' : '▼ ').(is_float($diff) ? round($diff, 1) : $diff).' vs previous'];
    };
    $kpis = [
        ['leads_assigned', 'Leads assigned', null, false],
        ['unique_leads_contacted', 'Unique leads contacted', null, false],
        ['calls_total', 'Calls logged', fn ($m) => $m['calls_connected'].' connected · '.$m['calls_no_answer'].' no answer · '.$m['calls_busy'].' busy', false],
        ['whatsapp_activities', 'WhatsApp sent', fn ($m) => $m['email_activities'].' emails', false],
        ['follow_ups_completed', 'Follow-ups completed', fn ($m) => 'of '.$m['follow_ups_due'].' due', false],
        ['follow_ups_overdue', 'Overdue follow-ups', null, true],
        ['meetings_booked', 'Meetings booked', fn ($m) => $m['meetings_upcoming'].' still upcoming', false],
        ['meetings_completed', 'Meetings held', fn ($m) => $m['meetings_cancelled'].' cancelled · '.$m['meetings_no_show'].' no-show', false],
        ['newly_qualified', 'Newly qualified', null, false],
        ['won', 'Deals won', fn ($m) => $m['lost'].' lost', false],
    ];
    $agentName = request('agent') ? $agents->firstWhere('id', (int) request('agent'))?->name : null;
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Sales Reports</h1>
        <small>{{ $from->format('D d M Y') }}{{ $from->toDateString() !== $to->toDateString() ? ' – '.$to->format('D d M Y') : '' }}
            · {{ $team ? ($agentName ?? 'Whole team') : 'Your numbers' }} · compared with {{ $prevFrom->format('d M') }}{{ $prevFrom->toDateString() !== $prevTo->toDateString() ? '–'.$prevTo->format('d M') : '' }}</small>
    </div>
    <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-outline">Export CSV</a>
</div>

<div class="card">
    <form method="GET" class="filters" id="report-filters">
        <div class="seg" style="align-self:flex-end">
            @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $k => $v)
                <a href="{{ route('reports.index', array_merge(request()->only('agent', 'date'), ['period' => $k])) }}" class="{{ $period === $k ? 'active' : '' }}">{{ $v }}</a>
            @endforeach
        </div>
        <input type="hidden" name="period" value="{{ $period }}">
        @if($period === 'custom')
            <div><label for="r-from">From</label><input id="r-from" type="date" name="from" class="form-control" value="{{ $from->toDateString() }}"></div>
            <div><label for="r-to">To</label><input id="r-to" type="date" name="to" class="form-control" value="{{ $to->toDateString() }}"></div>
        @else
            <div><label for="r-date">{{ ['daily' => 'Day', 'weekly' => 'Any day in week', 'monthly' => 'Any day in month'][$period] }}</label><input id="r-date" type="date" name="date" class="form-control" value="{{ request('date', $from->toDateString()) }}"></div>
        @endif
        @if($team)
        <div>
            <label for="r-agent">Agent</label>
            <select id="r-agent" name="agent" class="form-control">
                <option value="">Whole team</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}{{ $a->is_active ? '' : ' (inactive)' }}</option>@endforeach
            </select>
        </div>
        @endif
        <div><button class="btn">Show</button></div>
    </form>
</div>

<div class="grid grid-5">
    @foreach($kpis as [$key, $label, $sub, $lowerBetter])
        @php($d = $delta($key, $lowerBetter))
        <div class="stat compact {{ $key === 'won' ? 'green' : ($key === 'follow_ups_overdue' ? 'red' : '') }}" title="{{ $definitions[$key] ?? '' }}">
            <div class="num">{{ $metrics[$key] }}</div>
            <div class="label">{{ $label }}</div>
            @if($sub)<div class="sub">{{ $sub($metrics) }}</div>@endif
            @if($d)<div class="delta {{ $d[0] }}">{{ $d[1] }}</div>@endif
        </div>
    @endforeach
</div>

<div class="grid grid-4 mt">
    <div class="stat compact teal" title="{{ $definitions['avg_response_minutes'] }}">
        <div class="num">{{ $fmtMin($metrics['avg_response_minutes']) }}</div><div class="label">Avg. response time</div>
        <div class="sub">median {{ $fmtMin($metrics['median_response_minutes']) }} · {{ $metrics['not_responded'] }} not contacted yet</div>
    </div>
    <div class="stat compact" title="{{ $definitions['win_rate'] }}"><div class="num">{{ $metrics['win_rate'] !== null ? $metrics['win_rate'].'%' : '—' }}</div><div class="label">Win rate</div><div class="sub">won ÷ (won + lost) in period</div></div>
    <div class="stat compact" title="{{ $definitions['assigned_conversion'] }}"><div class="num">{{ $metrics['assigned_conversion'] !== null ? $metrics['assigned_conversion'].'%' : '—' }}</div><div class="label">Conversion of assigned leads</div><div class="sub">assigned in period &amp; now won</div></div>
    <div class="stat compact green" title="{{ $definitions['revenue'] }}">
        <div class="num" style="font-size:22px">@forelse($metrics['revenue'] as $cur => $amt){{ $cur }} {{ number_format($amt) }}@if(! $loop->last)<br>@endif @empty — @endforelse</div>
        <div class="label">Revenue (accepted proposals)</div>
        <div class="sub">talk time {{ gmdate('G\h i\m', $metrics['talk_seconds']) }} · {{ $metrics['owned_at_end'] }} leads owned at period end</div>
    </div>
</div>

@if($series)
<div class="grid grid-2 mt">
    @foreach(['calls' => 'Calls per day', 'assigned' => 'Leads assigned per day'] as $key => $label)
        @php($max = max(1, collect($series)->max($key)))
        <div class="card">
            <h3>{{ $label }}</h3>
            <div class="trend" role="img" aria-label="{{ $label }}">
                @foreach($series as $day => $vals)
                    <div class="col" title="{{ \Illuminate\Support\Carbon::parse($day)->format('D d M') }}: {{ $vals[$key] }}"><span class="{{ $vals[$key] ? '' : 'zero' }}" style="height: {{ max(1, $vals[$key] / $max * 100) }}%"></span></div>
                @endforeach
            </div>
            <div class="trend-labels"><span>{{ \Illuminate\Support\Carbon::parse(array_key_first($series))->format('d M') }}</span><span>peak {{ $max }}</span><span>{{ \Illuminate\Support\Carbon::parse(array_key_last($series))->format('d M') }}</span></div>
        </div>
    @endforeach
</div>
@endif

@if($agentRows)
<div class="card mt">
    <div class="card-head"><h2>Team Performance</h2><span class="muted">click a name to see that agent only</span></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Agent</th><th class="text-right">Assigned</th><th class="text-right">Owned</th><th class="text-right">Contacted</th>
                <th class="text-right">Calls</th><th class="text-right">Connected</th><th class="text-right">WhatsApp</th>
                <th class="text-right">F/U done</th><th class="text-right">Overdue</th><th class="text-right">Mtg booked</th><th class="text-right">Mtg held</th>
                <th class="text-right">Qualified</th><th class="text-right">Won</th><th class="text-right">Resp. time</th>
            </tr>
            </thead>
            <tbody>
            @foreach($agentRows as $row)
                @php($m = $row['metrics'])
                <tr>
                    <td><a href="{{ route('reports.index', array_merge(request()->query(), ['agent' => $row['user']->id])) }}">{{ $row['user']->name }}</a>@unless($row['user']->is_active) <small>(inactive)</small>@endunless</td>
                    <td class="text-right">{{ $m['leads_assigned'] }}</td>
                    <td class="text-right">{{ $m['owned_at_end'] }}</td>
                    <td class="text-right">{{ $m['unique_leads_contacted'] }}</td>
                    <td class="text-right">{{ $m['calls_total'] }}</td>
                    <td class="text-right">{{ $m['calls_connected'] }}</td>
                    <td class="text-right">{{ $m['whatsapp_activities'] }}</td>
                    <td class="text-right">{{ $m['follow_ups_completed'] }}/{{ $m['follow_ups_due'] }}</td>
                    <td class="text-right {{ $m['follow_ups_overdue'] ? 'overdue' : '' }}">{{ $m['follow_ups_overdue'] }}</td>
                    <td class="text-right">{{ $m['meetings_booked'] }}</td>
                    <td class="text-right">{{ $m['meetings_completed'] }}</td>
                    <td class="text-right">{{ $m['newly_qualified'] }}</td>
                    <td class="text-right"><strong>{{ $m['won'] }}</strong></td>
                    <td class="text-right">{{ $fmtMin($m['avg_response_minutes']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="grid grid-2 mt">
    <div class="card">
        <h2>How each number is calculated</h2>
        <dl class="defs">
            @foreach($definitions as $k => $def)
                <dt>{{ ucfirst(str_replace('_', ' ', $k)) }}</dt><dd>{{ $def }}</dd>
            @endforeach
        </dl>
        <p class="help mt">Calls are manual logs entered by agents — they are not verified by a phone system. All dates use {{ config('app.timezone') }}.</p>
    </div>
    <div class="card">
        <h2>Saved snapshots</h2>
        <p class="help">Saved every night at 00:20 (daily; weekly on Sundays; monthly on the last day). Live numbers above always come straight from the CRM.</p>
        <ul class="list-plain">
            @forelse($snapshots as $s)
                <li class="flex between">
                    <span><span class="badge">{{ $s->period }}</span> {{ $s->period_start->format('d M Y') }}{{ $s->period !== 'daily' ? ' – '.$s->period_end->format('d M') : '' }}</span>
                    <small>{{ $s->metrics['calls_total'] ?? 0 }} calls · {{ $s->metrics['won'] ?? 0 }} won · <a href="{{ route('reports.index', ['period' => $s->period === 'daily' ? 'daily' : 'custom', 'date' => $s->period_start->toDateString(), 'from' => $s->period_start->toDateString(), 'to' => $s->period_end->toDateString()]) }}">open</a></small>
                </li>
            @empty
                <li class="muted">No snapshots yet — they appear after the first nightly run (php artisan crm:snapshot-reports).</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
