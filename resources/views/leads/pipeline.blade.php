@extends('layouts.app')
@section('title', 'Pipeline')

@section('content')
<div class="page-head">
    <h1>Sales Pipeline</h1>
    <div class="btn-group">
        <span class="muted">Drag a card to another column to update its status.</span>
        @if(auth()->user()->hasPermission('leads.view-all') && auth()->user()->canOwnLeads())
            <div class="seg"><a href="{{ route('leads.pipeline') }}" class="{{ request('scope') !== 'mine' ? 'active' : '' }}">All</a><a href="{{ route('leads.pipeline', ['scope' => 'mine']) }}" class="{{ request('scope') === 'mine' ? 'active' : '' }}">Mine</a></div>
        @endif
    </div>
</div>

<div class="pipeline">
    @foreach(\App\Models\Lead::STATUSES as $status => $label)
        <div class="pipe-col" data-status="{{ $status }}">
            <h3><span>{{ $label }}</span><span class="badge badge-{{ $status }}" data-count>{{ ($leads[$status] ?? collect())->count() }}</span></h3>
            @foreach($leads[$status] ?? [] as $lead)
                <div class="pipe-card" draggable="true" data-url="{{ route('leads.status', $lead) }}">
                    <div class="name"><a href="{{ route('leads.show', $lead) }}">{{ $lead->display_name }}</a>
                        @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@endif</div>
                    <small>{{ $lead->service ?: $lead->campaign_name }}</small><br>
                    <small>{{ $lead->agent?->name ?? 'Unassigned' }} · {{ $lead->created_at->diffForHumans() }}</small>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
@endsection

@push('scripts')
<script>
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let dragged = null;

    document.querySelectorAll('.pipe-card').forEach(card => {
        card.addEventListener('dragstart', () => { dragged = card; card.classList.add('dragging'); });
        card.addEventListener('dragend', () => { card.classList.remove('dragging'); });
    });

    function recount() {
        document.querySelectorAll('.pipe-col').forEach(col => {
            col.querySelector('[data-count]').textContent = col.querySelectorAll('.pipe-card').length;
        });
    }

    document.querySelectorAll('.pipe-col').forEach(col => {
        col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('over'); });
        col.addEventListener('dragleave', () => col.classList.remove('over'));
        col.addEventListener('drop', e => {
            e.preventDefault();
            col.classList.remove('over');
            if (!dragged || dragged.parentElement === col) return;

            let lostReason = null;
            if (col.dataset.status === 'lost') {
                lostReason = prompt('Why was this lead lost? (price, timing, competitor ...)');
                if (lostReason === null) return;
            }

            const from = dragged.parentElement;
            col.appendChild(dragged);
            recount();

            fetch(dragged.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ status: col.dataset.status, lost_reason: lostReason })
            }).then(r => {
                if (!r.ok) throw new Error();
            }).catch(() => {
                from.appendChild(dragged);
                recount();
                alert('Status could not be updated. Please try again.');
            });
        });
    });
</script>
@endpush
