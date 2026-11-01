<div class="ev-card {{ $m->status }}">
    <div class="flex between">
        <span class="t">{{ $m->starts_at->format('h:i A') }}–{{ $m->ends_at?->format('h:i A') }}</span>
        <span class="badge badge-{{ $m->status }}">{{ $m->status_label }}</span>
    </div>
    <a href="{{ route('meetings.show', $m) }}">{{ $m->lead?->display_name ?? $m->title }}</a>
    <br><small>{{ $m->type_label }}{{ $m->agent ? ' · '.$m->agent->name : '' }}@if(! empty($wide) && $m->location) · {{ $m->location }}@endif</small>
    @if(! empty($wide) && $m->meeting_link && $m->isActive())<br><a href="{{ $m->meeting_link }}" target="_blank" rel="noopener" class="help">Join link</a>@endif
</div>
