@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
<div class="page-head">
    <h1>Notifications</h1>
    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-outline">Mark all as read</button></form>
</div>

<div class="grid grid-main">
    <div class="card">
        <table class="table">
            <tbody>
            @forelse($notifications as $n)
                <tr class="{{ $n->read_at ? '' : 'row-unread' }}">
                    <td>
                        @unless($n->read_at)<span class="notice-dot" aria-label="unread"></span>@endunless
                        <a href="{{ route('notifications.open', $n->id) }}"><strong>{{ $n->data['title'] ?? 'Notification' }}</strong></a>
                        @if(! empty($n->data['body']))<br><small>{{ $n->data['body'] }}</small>@endif
                    </td>
                    <td class="text-right"><small>{{ $n->created_at->diffForHumans() }}</small></td>
                </tr>
            @empty
                <tr><td class="empty">No notifications yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $notifications->links() }}
    </div>
    <div class="card">
        <h2>Preferences</h2>
        <form method="POST" action="{{ route('notifications.preferences') }}">
            @csrf
            @foreach($types as $k => $label)
                <label class="check" style="margin-bottom:8px"><input type="checkbox" name="notify[{{ $k }}]" value="1" @checked(($prefs[$k] ?? true) !== false)> {{ $label }}</label>
            @endforeach
            <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
            <label class="check"><input type="checkbox" name="email" value="1" @checked(($prefs['email'] ?? false) === true)> Also send these by email</label>
            <p class="help">Email uses the SMTP settings of the CRM.</p>
            <button class="btn mt">Save preferences</button>
        </form>
    </div>
</div>
@endsection
