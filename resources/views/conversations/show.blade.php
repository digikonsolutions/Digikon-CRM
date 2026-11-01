@extends('layouts.app')
@section('title', 'Chat — '.$lead->display_name)

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $lead->display_name }}
            <span class="badge badge-{{ $lead->status }}">{{ $lead->status_label }}</span>
            @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@endif
        </h1>
        <small>{{ $lead->phone }} · {{ $lead->messages->count() }} WhatsApp messages · owner {{ $lead->agent?->name ?? 'unassigned' }} · score {{ $lead->score }}</small>
    </div>
    <div class="btn-group">
        <a href="{{ route('leads.show', $lead) }}" class="btn btn-outline">Open lead</a>
        <a href="{{ route('conversations.index') }}" class="btn btn-outline">← All conversations</a>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Conversation</h2>
            @if($lead->whatsapp_opt_out)<span class="badge badge-failed">Opted out (STOP)</span>@endif</div>
        <div class="chat" id="chat" style="max-height:70vh">
            @forelse($lead->messages as $m)
                <div class="bubble {{ $m->direction }}" id="msg-{{ $m->id }}">
                    <div class="text">{{ $m->body }}</div>
                    <div class="meta">#{{ $m->id }} · {{ $m->sent_at->format('d M Y, h:i A') }} ·
                        {{ $m->direction === 'in' ? 'Customer' : ($m->user?->name ?? 'Automated') }}
                        @if($m->direction === 'out') · <span class="badge badge-{{ $m->status }}">{{ $m->status }}</span>@endif
                        @if($m->message_type && $m->message_type !== 'text') · {{ $m->message_type }}@endif
                    </div>
                </div>
            @empty
                <p class="muted">No messages.</p>
            @endforelse
        </div>
        <p class="help mt">Messages are stored exactly as received. AI analysis is saved separately and never changes the chat history.</p>
    </div>

    <div>
        @include('leads._analysis', ['lead' => $lead, 'analysis' => $analysis])
        <div class="card">
            <h2>Current lead segmentation</h2>
            <div class="kv"><span>Temperature</span><span>{{ $lead->temperature_label ?? '—' }} {{ $lead->temperature_source ? '('.$lead->temperature_source.')' : '' }}</span></div>
            <div class="kv"><span>Qualification</span><span>{{ $lead->qualification_label }} {{ $lead->qualification_source ? '('.$lead->qualification_source.')' : '' }}</span></div>
            <div class="kv"><span>Buying intent</span><span>{{ $lead->buying_intent ? ucfirst($lead->buying_intent) : '—' }}</span></div>
            <div class="kv"><span>Service</span><span>{{ $lead->service ?: '—' }}</span></div>
            <div class="kv"><span>Budget</span><span>{{ $lead->budget ?: '—' }}</span></div>
            <div class="kv"><span>Timeline</span><span>{{ $lead->timeline ?: '—' }}</span></div>
            <div class="kv"><span>Industry</span><span>{{ $lead->industry ?: '—' }}</span></div>
            <div class="kv"><span>Score</span><span>{{ $lead->score }} — {{ implode(', ', $scoreInfo['reasons']) ?: 'no signals' }}</span></div>
            <p class="mt">@foreach($lead->tags as $t)<span class="tag-chip"><span class="dot" style="background:{{ $t->color }}"></span>{{ $t->name }} @if($t->pivot->source === 'ai')<span class="src">ai</span>@endif</span>@endforeach</p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const chat = document.getElementById('chat');
    const target = location.hash && document.querySelector(location.hash);
    if (target && chat.contains(target)) { target.classList.add('highlight'); target.scrollIntoView({ block: 'center' }); }
    else chat.scrollTop = chat.scrollHeight;
</script>
@endpush
