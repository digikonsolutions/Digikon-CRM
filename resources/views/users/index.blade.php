@extends('layouts.app')
@section('title', 'Team')

@section('content')
<div class="page-head">
    <h1>Sales Agents &amp; Team</h1>
    <a href="{{ route('users.create') }}" class="btn">+ Add User</a>
</div>

<div class="alert alert-info">
    Leads are only distributed to users with both <strong>Active</strong> and <strong>Receives leads</strong> turned on.
    Distribution method: <strong>{{ \App\Services\LeadDistributor::METHODS[\App\Models\Setting::get('distribution_method', 'round_robin')] ?? '' }}</strong>
    (<a href="{{ route('settings.index') }}">change</a>)
</div>

<div class="card">
    <table class="table">
        <thead><tr><th>Name</th><th>Email / Phone</th><th>Role</th><th>Receives leads</th><th class="text-right">Open / Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $u)
            <tr>
                <td><strong>{{ $u->name }}</strong></td>
                <td>{{ $u->email }}<br><small>{{ $u->phone }}</small></td>
                <td><span class="badge">{{ $u->role_label }}</span></td>
                <td>{!! $u->receives_leads ? '<span class="badge badge-sent">Yes</span>' : '<span class="badge badge-cancelled">No</span>' !!}</td>
                <td class="text-right"><a href="{{ route('leads.index', ['agent' => $u->id]) }}">{{ $u->open_leads_count }}</a> / {{ $u->leads_count }}@if(! $u->is_active && $u->open_leads_count)<br><small class="overdue">reassign!</small>@endif</td>
                <td>{!! $u->is_active ? '<span class="badge badge-sent">Active</span>' : '<span class="badge badge-failed">Disabled</span>' !!}</td>
                <td class="text-right">
                    <a href="{{ route('users.edit', $u) }}" class="btn btn-outline btn-sm">Edit</a>
                    @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('users.destroy', $u) }}" class="inline-form" onsubmit="return confirm('Delete this user?')">
                            @csrf @method('DELETE') <button class="btn btn-red btn-sm">Delete</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
