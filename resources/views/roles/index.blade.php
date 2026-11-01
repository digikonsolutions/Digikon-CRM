@extends('layouts.app')
@section('title', 'Roles & Permissions')

@section('content')
<div class="page-head">
    <div><h1>Roles &amp; Permissions</h1><small>Built into the CRM (no extra package). Assign a role to each user on the Sales Agents page.</small></div>
    <form method="POST" action="{{ route('roles.update') }}" data-confirm="Reset all roles to the default permissions?">@csrf<input type="hidden" name="reset" value="1"><button class="btn btn-outline">Reset to defaults</button></form>
</div>

<form method="POST" action="{{ route('roles.update') }}" class="card">
    @csrf
    <div class="table-wrap">
        <table class="table perm-table">
            <thead><tr><th>Permission</th>@foreach($roles as $k => $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($permissions as $perm => $label)
                <tr>
                    <td><strong>{{ $label }}</strong><br><small><code>{{ $perm }}</code></small></td>
                    @foreach($roles as $role => $rl)
                        @php($locked = $role === 'admin' && in_array($perm, ['settings.manage', 'users.manage']))
                        <td>
                            <input type="checkbox" name="perm[{{ $role }}][]" value="{{ $perm }}" @checked(in_array($perm, $matrix[$role] ?? [])) @disabled($locked) aria-label="{{ $rl }}: {{ $label }}"
                                   title="{{ in_array($perm, $defaults[$role] ?? []) ? 'On by default' : 'Off by default' }}">
                            @if($locked)<input type="hidden" name="perm[{{ $role }}][]" value="{{ $perm }}">@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="help">"View own leads" = only leads assigned to that user. Agents can never see or change other agents' leads unless you give them "View all leads". Admin always keeps Settings and User management (lock-out protection).</p>
    <button class="btn mt">Save permissions</button>
</form>
@endsection
