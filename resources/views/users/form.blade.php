@extends('layouts.app')
@section('title', 'User')

@section('content')
<div class="page-head">
    <h1>{{ $user->exists ? 'Edit User' : 'New User' }}</h1>
    <a href="{{ route('users.index') }}" class="btn btn-outline">← Back</a>
</div>

<form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card">
    @csrf
    @if($user->exists) @method('PUT') @endif
    <div class="form-row">
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required></div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}"></div>
        <div class="form-group">
            <label>Role</label>
            <select name="role" class="form-control" @disabled($user->exists && $user->id === auth()->id())>
                @foreach(config('crm.roles') as $k => $v)
                    <option value="{{ $k }}" @selected(old('role', $user->role) === $k)>{{ $v }}</option>
                @endforeach
            </select>
            @if($user->exists && $user->id === auth()->id())<input type="hidden" name="role" value="{{ $user->role }}"><div class="help">You cannot change your own role.</div>@endif
            <div class="help">Sales Agent: only own leads · Sales Manager: all leads, assigns, team reports · Marketing: sources &amp; reports · Admin: everything. <a href="{{ route('roles.index') }}">Edit permissions</a></div>
        </div>
        <div class="form-group">
            <label>Password {{ $user->exists ? '(leave blank to keep current)' : '*' }}</label>
            <input type="password" name="password" class="form-control" {{ $user->exists ? '' : 'required' }}>
        </div>
    </div>
    <div class="form-group"><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Active (can log in)</label></div>
    <div class="form-group"><label class="check"><input type="checkbox" name="receives_leads" value="1" @checked(old('receives_leads', $user->receives_leads))> Receives leads (included in auto distribution — Sales Agents / Managers only)</label></div>
    <button class="btn">Save</button>
</form>
@endsection
