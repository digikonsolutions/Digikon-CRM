@extends('layouts.app')
@section('title', 'AI Reviews')

@section('content')
<div class="page-head">
    <div>
        <h1>AI Reviews</h1>
        <small>AI suggestions from WhatsApp chats. Approve, correct or reject — nothing the AI says is treated as final.</small>
    </div>
    <div class="seg">
        @foreach(['open' => 'Needs review', 'approved' => 'Approved', 'edited' => 'Corrected', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $v)
            <a href="{{ route('analyses.index', ['status' => $k]) }}" class="{{ request('status', 'open') === $k ? 'active' : '' }}">{{ $v }}</a>
        @endforeach
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Lead</th><th>AI summary</th><th>Suggested</th><th>Confidence</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($analyses as $a)
                <tr>
                    <td>@if($a->lead)<a href="{{ route('conversations.show', $a->lead) }}"><strong>{{ $a->lead->display_name }}</strong></a><br><small>{{ $a->lead->agent?->name ?? 'unassigned' }}</small>@endif</td>
                    <td style="max-width:380px"><small>{{ \Illuminate\Support\Str::limit($a->summary, 180) }}</small></td>
                    <td>
                        @if($a->temperature)<span class="badge badge-{{ $a->temperature }}">{{ ucfirst($a->temperature) }}</span>@endif
                        @if($a->qualification)<span class="badge badge-{{ $a->qualification }}">{{ config('crm.qualifications')[$a->qualification] ?? $a->qualification }}</span>@endif
                        <br><small>{{ collect([$a->service_interest, $a->budget, $a->timeline])->filter()->implode(' · ') ?: '—' }}</small>
                    </td>
                    <td>@if($a->confidence !== null)<span class="confidence"><span style="width: {{ round($a->confidence * 100) }}%"></span></span> {{ round($a->confidence * 100) }}%@else — @endif</td>
                    <td><span class="badge badge-{{ $a->review_status }}">{{ $a->review_label }}</span><br><small>{{ $a->created_at->diffForHumans() }}{{ $a->reviewer ? ' · '.$a->reviewer->name : '' }}</small></td>
                    <td class="text-right">@if($a->lead)<a href="{{ route('conversations.show', $a->lead) }}#ai-analysis" class="btn btn-outline btn-sm">Review</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Nothing to review.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $analyses->links() }}
</div>
@endsection
