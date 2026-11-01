@extends('layouts.app')
@section('title', 'WhatsApp & AI')

@section('content')
<div class="page-head">
    <div>
        <h1>WhatsApp Conversations <small>({{ $leads->total() }})</small></h1>
        <small>Every lead's WhatsApp chat in one place. The AI reads the full chat and segregates the lead (hot / warm / cold, qualification, service, budget...).</small>
    </div>
    @unless($aiConfigured)
        @can('settings.manage')<a href="{{ route('settings.index', ['tab' => 'ai']) }}" class="btn">Set up AI analysis</a>@endcan
    @endunless
</div>

<div class="card">
    <form method="GET" class="filters">
        <div><label for="cv-q">Search</label><input id="cv-q" type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Name / phone"></div>
        <div>
            <label for="cv-f">Show</label>
            <select id="cv-f" name="filter" class="form-control">
                <option value="">All conversations</option>
                <option value="awaiting" @selected(request('filter') === 'awaiting')>Customer waiting for reply</option>
                <option value="not_analysed" @selected(request('filter') === 'not_analysed')>Not analysed by AI yet</option>
                <option value="review" @selected(request('filter') === 'review')>AI result needs review</option>
            </select>
        </div>
        <div>
            <label for="cv-t">Temperature</label>
            <select id="cv-t" name="temperature" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.temperatures') as $k => $v)<option value="{{ $k }}" @selected(request('temperature') === $k)>{{ $v }}</option>@endforeach
                <option value="none" @selected(request('temperature') === 'none')>Not classified</option>
            </select>
        </div>
        @if($agents->count())
        <div>
            <label for="cv-a">Owner</label>
            <select id="cv-a" name="agent" class="form-control">
                <option value="">All</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        @endif
        <div><button class="btn">Filter</button> <a href="{{ route('conversations.index') }}" class="btn btn-outline">Reset</a></div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Lead</th><th>Last message</th><th>Conversation</th><th>AI segregation</th><th>Owner</th><th></th></tr></thead>
            <tbody>
            @forelse($leads as $lead)
                @php($last = $lead->latestMessage)
                @php($an = $lead->latestAnalysis)
                <tr>
                    <td><a href="{{ route('conversations.show', $lead) }}"><strong>{{ $lead->display_name }}</strong></a><br><small>{{ $lead->phone }}</small>
                        @if($lead->tags->count())<br>@foreach($lead->tags->take(3) as $t)<span class="tag-chip">{{ $t->name }}</span>@endforeach @endif</td>
                    <td style="max-width:320px">
                        @if($last)
                            <small>{{ $last->direction === 'in' ? 'Customer' : ($last->user_id ? 'Agent' : 'Auto') }} · {{ $last->sent_at->diffForHumans() }}</small><br>
                            {{ \Illuminate\Support\Str::limit($last->body, 90) }}
                        @endif
                    </td>
                    <td>
                        @if($last && $last->direction === 'in')<span class="badge badge-pending">Awaiting reply</span>
                        @elseif($lead->followups_paused)<span class="badge badge-qualified">Human handling</span>
                        @else<span class="badge badge-outline">Replied</span>@endif
                        <br><small>{{ $lead->messages_count }} msgs · {{ $lead->inbound_count }} from customer</small>
                    </td>
                    <td>
                        @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@endif
                        @if($an && $an->status === 'completed')
                            <span class="badge badge-{{ $an->review_status }}">{{ $an->review_label }}</span>
                            <br><small>{{ \Illuminate\Support\Str::limit($an->summary, 80) }}</small>
                            @if((int) $lead->messages_max_id > (int) $an->last_message_id)<br><small class="overdue">new messages since analysis</small>@endif
                        @elseif($an && $an->status === 'failed')
                            <span class="badge badge-failed">analysis failed</span>
                        @else
                            <small class="muted">Not analysed</small>
                        @endif
                    </td>
                    <td>{{ $lead->agent?->name ?? '—' }}</td>
                    <td class="text-right"><a href="{{ route('conversations.show', $lead) }}" class="btn btn-outline btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No WhatsApp conversations yet. Messages appear here once the WhatsApp webhook is connected (Settings → WhatsApp API).</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $leads->links() }}
</div>
@endsection
