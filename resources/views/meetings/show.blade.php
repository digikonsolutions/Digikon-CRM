@extends('layouts.app')
@section('title', $meeting->title)

@php($canEdit = auth()->user()->can('update', $meeting))

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $meeting->title }} <span class="badge badge-{{ $meeting->status }}">{{ $meeting->status_label }}</span>
            @if($meeting->source === 'ai')<span class="badge badge-ai">booked by AI from chat</span>@endif</h1>
        <small>{{ $meeting->starts_at->format('l, d M Y · h:i A') }} – {{ $meeting->ends_at?->format('h:i A') }} ({{ $meeting->timezone }})</small>
    </div>
    <div class="btn-group">
        <a href="{{ route('meetings.ics', $meeting) }}" class="btn btn-outline">Add to my calendar (.ics)</a>
        <a href="{{ route('meetings.index', ['view' => 'week', 'date' => $meeting->starts_at->toDateString()]) }}" class="btn btn-outline">← Calendar</a>
    </div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card">
            <h2>Details</h2>
            <dl class="dl">
                <dt>Lead</dt><dd>@if($meeting->lead)<a href="{{ route('leads.show', $meeting->lead) }}">{{ $meeting->lead->display_name }}</a> · {{ $meeting->lead->phone }}@endif</dd>
                <dt>Type</dt><dd>{{ $meeting->type_label }}</dd>
                <dt>Agent</dt><dd>{{ $meeting->agent?->name ?? '—' }}</dd>
                <dt>Booked by</dt><dd>{{ $meeting->organizer?->name ?? '—' }} · {{ $meeting->created_at->format('d M Y, h:i A') }}</dd>
                @if($meeting->meeting_link)<dt>Link</dt><dd><a href="{{ $meeting->meeting_link }}" target="_blank" rel="noopener">{{ $meeting->meeting_link }}</a></dd>@endif
                @if($meeting->location)<dt>Location</dt><dd>{{ $meeting->location }}</dd>@endif
                <dt>Confirmation</dt><dd>{{ $meeting->confirmation_sent_at ? 'sent '.$meeting->confirmation_sent_at->format('d M, h:i A') : 'not sent' }}</dd>
                @if($meeting->reschedule_count)<dt>Rescheduled</dt><dd>{{ $meeting->reschedule_count }} time(s)</dd>@endif
                @if($meeting->outcome)<dt>Outcome</dt><dd>{{ $meeting->outcome }}</dd>@endif
                @if($meeting->cancel_reason)<dt>Cancel reason</dt><dd>{{ $meeting->cancel_reason }}</dd>@endif
            </dl>
            @if($meeting->notes)<p class="mt"><strong>Notes</strong><br>{!! nl2br(e($meeting->notes)) !!}</p>@endif
        </div>

        <div class="card">
            <h2>History</h2>
            <ul class="tl">
                @forelse($history as $h)
                    <li>
                        <span class="badge">{{ str_replace('meeting.', '', $h->action) }}</span>
                        @if(isset($h->old_values['starts_at']) && isset($h->new_values['starts_at']))
                            <div class="desc">{{ \Illuminate\Support\Carbon::parse($h->old_values['starts_at'])->format('d M h:i A') }} → {{ \Illuminate\Support\Carbon::parse($h->new_values['starts_at'])->format('d M h:i A') }}</div>
                        @elseif(isset($h->new_values['status']))
                            <div class="desc">Status: {{ $h->old_values['status'] ?? '—' }} → {{ $h->new_values['status'] }}{{ isset($h->new_values['outcome']) && $h->new_values['outcome'] ? ' — '.$h->new_values['outcome'] : '' }}</div>
                        @endif
                        @if($h->description)<div class="desc">{{ $h->description }}</div>@endif
                        <div class="when">{{ $h->user?->name ?? 'System' }} · {{ $h->created_at->format('d M Y, h:i A') }}</div>
                    </li>
                @empty
                    <li class="muted">No changes recorded.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div>
        @if($canEdit && $meeting->isActive())
            <div class="card">
                <h2>Customer confirmation</h2>
                <p class="help">Sends the meeting time to the customer on WhatsApp and email.</p>
                <form method="POST" action="{{ route('meetings.confirm', $meeting) }}">@csrf<button class="btn btn-outline">{{ $meeting->confirmation_sent_at ? 'Send again' : 'Send confirmation' }}</button></form>
            </div>
            @if($meeting->starts_at->isPast())
            <div class="card">
                <h2>Record outcome</h2>
                @if(\App\Models\Setting::get('auto_proposal_after_meeting', '1') === '1')<p class="help">"Completed" moves the lead to <strong>Meeting Done</strong> and automatically emails the proposal PDF (from Service Packages).</p>@endif
                <form method="POST" action="{{ route('meetings.update', $meeting) }}">
                    @csrf <input type="hidden" name="action" value="outcome">
                    <div class="form-group">
                        <label class="check"><input type="radio" name="status" value="completed" checked> Completed (meeting held)</label>
                        <label class="check"><input type="radio" name="status" value="no_show"> Customer did not show up</label>
                    </div>
                    <div class="form-group"><label for="o-out">Outcome</label><input id="o-out" type="text" name="outcome" class="form-control" maxlength="500" placeholder="e.g. Wants proposal for 3-page website"></div>
                    <div class="form-group"><label for="o-notes">Notes</label><textarea id="o-notes" name="notes" class="form-control"></textarea></div>
                    <button class="btn">Save outcome</button>
                </form>
            </div>
            @endif

            <div class="card">
                <h2>Reschedule</h2>
                <form method="POST" action="{{ route('meetings.update', $meeting) }}">
                    @csrf <input type="hidden" name="action" value="reschedule">
                    <div class="form-group"><label for="r-start">New start</label><input id="r-start" type="datetime-local" name="starts_at" class="form-control" required value="{{ old('starts_at', $meeting->starts_at->format('Y-m-d\TH:i')) }}"></div>
                    <div class="form-group"><label for="r-end">New end</label><input id="r-end" type="datetime-local" name="ends_at" class="form-control" required value="{{ old('ends_at', $meeting->ends_at?->format('Y-m-d\TH:i')) }}"></div>
                    <div class="form-group"><label for="r-reason">Reason</label><input id="r-reason" type="text" name="reason" class="form-control" maxlength="500" placeholder="Customer asked to move it"></div>
                    @if(auth()->user()->hasPermission('meetings.view-team'))
                        <div class="form-group"><label class="check"><input type="checkbox" name="allow_conflict" value="1"> Allow double-booking</label></div>
                    @endif
                    <button class="btn btn-outline">Reschedule</button>
                </form>
            </div>

            <div class="card">
                <h2>Cancel meeting</h2>
                <form method="POST" action="{{ route('meetings.update', $meeting) }}" data-confirm="Cancel this meeting?">
                    @csrf <input type="hidden" name="action" value="cancel">
                    <div class="form-group"><input type="text" name="reason" class="form-control" required maxlength="500" placeholder="Cancellation reason" aria-label="Cancellation reason"></div>
                    <button class="btn btn-red">Cancel meeting</button>
                </form>
            </div>
        @elseif(! $meeting->isActive())
            <div class="card"><p class="muted">This meeting is {{ strtolower($meeting->status_label) }}. Book a new meeting from the lead page if needed.</p></div>
        @endif
    </div>
</div>
@endsection
