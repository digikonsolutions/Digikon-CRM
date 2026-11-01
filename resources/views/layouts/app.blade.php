<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ \App\Models\Setting::get('company_name', config('app.name')) }} CRM</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
@php($u = auth()->user())
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="name">{{ \App\Models\Setting::get('company_name', config('app.name')) }}<span>.</span></div>
            <small>Lead &amp; Sales CRM</small>
        </div>
        <nav aria-label="Main">
            <div class="nav-title">CRM</div>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            @if($u->hasPermission('leads.view-all'))
                <a href="{{ route('leads.index') }}" class="{{ request()->routeIs('leads.index', 'leads.show', 'leads.create', 'leads.edit') && ! in_array(request('scope'), ['mine', 'unassigned']) ? 'active' : '' }}">All Leads</a>
            @endif
            @if($u->hasPermission('leads.view-own') && $u->canOwnLeads())
                <a href="{{ route('leads.index', ['scope' => 'mine']) }}" class="{{ request()->routeIs('leads.index') && request('scope') === 'mine' ? 'active' : '' }}">My Leads</a>
            @elseif(! $u->hasPermission('leads.view-all') && $u->hasPermission('leads.view-own'))
                <a href="{{ route('leads.index') }}" class="{{ request()->routeIs('leads.*') ? 'active' : '' }}">Leads</a>
            @endif
            @if($u->hasPermission('leads.assign'))
                <a href="{{ route('leads.index', ['scope' => 'unassigned']) }}" class="{{ request()->routeIs('leads.index') && request('scope') === 'unassigned' ? 'active' : '' }}">Unassigned Leads</a>
            @endif
            @if($u->hasAnyPermission('leads.view-own', 'leads.view-all'))
                <a href="{{ route('leads.pipeline') }}" class="{{ request()->routeIs('leads.pipeline') ? 'active' : '' }}">Pipeline</a>
            @endif
            @if($u->hasPermission('conversations.view'))
                <a href="{{ route('conversations.index') }}" class="{{ request()->routeIs('conversations.*') ? 'active' : '' }}">WhatsApp &amp; AI</a>
            @endif
            @if($u->hasPermission('lead-analyses.review'))
                <a href="{{ route('analyses.index') }}" class="{{ request()->routeIs('analyses.*') ? 'active' : '' }}">AI Reviews</a>
            @endif
            @if($u->hasAnyPermission('activities.view-own', 'activities.view-team'))
                <a href="{{ route('activities.index') }}" class="{{ request()->routeIs('activities.*') ? 'active' : '' }}">Activities</a>
                <a href="{{ route('tasks.index') }}" class="{{ request()->routeIs('tasks.*') ? 'active' : '' }}">Follow-ups</a>
            @endif
            @if($u->hasAnyPermission('meetings.view-own', 'meetings.view-team'))
                <a href="{{ route('meetings.index') }}" class="{{ request()->routeIs('meetings.*') ? 'active' : '' }}">Meetings Calendar</a>
            @endif
            @if($u->hasAnyPermission('reports.view-own', 'reports.view-team'))
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">Sales Reports</a>
            @endif
            @if($u->hasAnyPermission('leads.view-own', 'leads.view-all'))
                <a href="{{ route('proposals.index') }}" class="{{ request()->routeIs('proposals.*') ? 'active' : '' }}">Proposals</a>
                <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a>
            @endif

            @if($u->hasAnyPermission('users.manage', 'tags.manage', 'lead-sources.manage', 'audit.view', 'settings.manage', 'reports.view-team'))
                <div class="nav-title">Management</div>
                @if($u->hasPermission('users.manage'))
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">Sales Agents</a>
                @endif
                @if($u->hasPermission('reports.view-team'))
                    <a href="{{ route('reports.index', ['period' => 'monthly']) }}" class="{{ request()->routeIs('reports.*') && request('period') === 'monthly' ? 'active' : '' }}">Team Performance</a>
                @endif
                @if($u->hasPermission('tags.manage'))
                    <a href="{{ route('tags.index') }}" class="{{ request()->routeIs('tags.*') ? 'active' : '' }}">Tags</a>
                @endif
                @if($u->hasPermission('lead-sources.manage'))
                    <a href="{{ route('lead-sources.index') }}" class="{{ request()->routeIs('lead-sources.*') ? 'active' : '' }}">Lead Sources</a>
                @endif
                @if($u->hasPermission('settings.manage'))
 <a href="{{ route('settings.index', ['tab' => 'automation']) }}" class="{{ request()->routeIs('settings.*') && request('tab') === 'automation' ? 'active' : '' }}">Sales Automation</a>
                    <a href="{{ route('packages.index') }}" class="{{ request()->routeIs('packages.*') ? 'active' : '' }}">Service Packages</a>
                    <a href="{{ route('followups.index') }}" class="{{ request()->routeIs('followups.*') ? 'active' : '' }}">Follow-up Automation</a>
                    <a href="{{ route('settings.index', ['tab' => 'meta']) }}" class="{{ request()->routeIs('settings.*') && in_array(request('tab'), ['meta', 'whatsapp', 'ai']) ? 'active' : '' }}">Integrations</a>
                @endif
                @if($u->hasPermission('audit.view'))
                    <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}">Audit Logs</a>
                @endif
                @if($u->hasPermission('settings.manage'))
                    <a href="{{ route('roles.index') }}" class="{{ request()->routeIs('roles.*') ? 'active' : '' }}">Roles &amp; Permissions</a>
                    <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') && ! in_array(request('tab'), ['meta', 'whatsapp', 'ai', 'automation']) ? 'active' : '' }}">Settings</a>
                @endif
            @endif
        </nav>
    </aside>

    <div class="main">
        <div class="topbar">
            <form method="GET" action="{{ route('leads.index') }}" class="flex" role="search">
                <label for="top-search" class="sr-only" style="position:absolute;left:-9999px">Search leads</label>
                <input id="top-search" type="text" name="q" class="form-control" placeholder="Search name, phone, email..." value="{{ request('q') }}" style="width:280px">
            </form>
            <div class="flex">
                <a href="{{ route('notifications.index') }}" class="bell" title="Notifications" aria-label="Notifications ({{ $unreadNotifications ?? 0 }} unread)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    @if(($unreadNotifications ?? 0) > 0)<span class="dot">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>@endif
                </a>
                <span><strong>{{ $u->name }}</strong> <small>· {{ $u->role_label }}</small></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-outline btn-sm">Logout</button>
                </form>
            </div>
        </div>

        <div class="content">
            @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
            @if(session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif
            @if($errors->any())
                <div class="alert alert-error" role="alert">
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @yield('content')
        </div>
    </div>
</div>
<script>
    // Tiny helpers shared by all pages (no framework): open/close <dialog> modals, confirm forms.
    document.addEventListener('click', e => {
        const opener = e.target.closest('[data-open]');
        if (opener) {
            e.preventDefault();
            const dlg = document.getElementById(opener.dataset.open);
            if (dlg) {
                Object.entries(opener.dataset).forEach(([k, v]) => {
                    if (!k.startsWith('fill')) return;
                    const name = k.slice(4).replace(/^./, c => c.toLowerCase()).replace(/[A-Z]/g, c => '_' + c.toLowerCase());
                    const field = dlg.querySelector('[name="' + name + '"]');
                    if (field) field.value = v;
                });
                if (opener.dataset.action) dlg.querySelector('form').action = opener.dataset.action;
                dlg.showModal();
            }
        }
        if (e.target.closest('[data-close]')) e.target.closest('dialog').close();
    });
    document.addEventListener('submit', e => {
        const msg = e.target.dataset.confirm;
        if (msg && !confirm(msg)) e.preventDefault();
    });
</script>
@stack('scripts')
</body>
</html>
