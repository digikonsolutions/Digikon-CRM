@extends('layouts.app')
@section('title', 'Service Packages')

@section('content')
<div class="page-head">
    <div><h1>Service Packages</h1><small>Price list for automatic proposals. After a successful meeting the CRM picks the package whose keywords match the lead's service.</small></div>
    <a href="{{ route('packages.create') }}" class="btn">+ New package</a>
</div>

<div class="alert alert-info">Matching: the lead's <strong>service</strong> (form / AI) is compared with each package's keywords — the longest matching keyword wins. If nothing matches, the <strong>default</strong> package is used. No default and no match → no automatic proposal (the agent is asked to create one).</div>

<div class="card">
    <table class="table">
        <thead><tr><th>Package</th><th>Keywords</th><th>Items</th><th class="text-right">Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($packages as $p)
            <tr>
                <td><strong>{{ $p->name }}</strong> @if($p->is_default)<span class="badge badge-qualified">default</span>@endif<br><small>{{ $p->title }}</small></td>
                <td><small>{{ $p->keywords }}</small></td>
                <td><small>{{ collect($p->items)->pluck('description')->map(fn ($d) => \Illuminate\Support\Str::limit($d, 40))->implode(' · ') }}</small></td>
                <td class="text-right">{{ \App\Models\Setting::get('currency', 'PKR') }} {{ number_format($p->subtotal() - $p->discount) }}</td>
                <td>{!! $p->is_active ? '<span class="badge badge-sent">Active</span>' : '<span class="badge badge-cancelled">Off</span>' !!}</td>
                <td class="text-right">
                    <a href="{{ route('packages.edit', $p) }}" class="btn btn-outline btn-sm">Edit</a>
                    <form method="POST" action="{{ route('packages.destroy', $p) }}" class="inline-form" data-confirm="Delete package {{ $p->name }}?">@csrf @method('DELETE')<button class="btn btn-red btn-sm">Delete</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No packages — automatic proposals are off until you add one.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
