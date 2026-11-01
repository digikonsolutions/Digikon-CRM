@extends('layouts.app')
@section('title', 'Lead Sources')

@section('content')
<div class="page-head"><h1>Lead Sources</h1><span class="muted">Meta Lead Ads + landing-page forms — every inbound event is logged here.</span></div>

<div class="grid grid-3">
    <div class="card">
        <h2>Last 30 days by source</h2>
        @php($max = max($bySource->max() ?? 0, 1))
        @forelse($bySource as $src => $n)
            <div class="bar-row"><div class="bar-label"><span>{{ \App\Models\Lead::SOURCES[$src] ?? $src }}</span><strong>{{ $n }}</strong></div><div class="bar"><span style="width: {{ $n / $max * 100 }}%"></span></div></div>
        @empty <p class="muted">No leads in the last 30 days.</p> @endforelse
    </div>
    <div class="card">
        <h2>Ingestion results (30 days)</h2>
        @forelse($stats as $s)
            <div class="kv"><span>{{ $s->source }} · <span class="badge badge-{{ $s->status }}">{{ $s->status }}</span></span><strong>{{ $s->total }}</strong></div>
        @empty <p class="muted">No Meta or landing-page events yet.</p> @endforelse
        <p class="help mt">duplicate = matched an existing open lead (same phone / email), no new lead created.</p>
    </div>
    <div class="card">
        <h2>Top UTM campaigns</h2>
        @forelse($byUtm as $c => $n)
            <div class="kv"><span>{{ \Illuminate\Support\Str::limit($c, 34) }}</span><strong>{{ $n }}</strong></div>
        @empty <p class="muted">Landing-page leads with utm_campaign appear here.</p> @endforelse
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Ingestion log</h2>
        <form method="GET" class="flex">
            <select name="source" class="form-control w-auto" aria-label="Source"><option value="">All sources</option><option value="meta" @selected(request('source') === 'meta')>Meta</option><option value="landing_page" @selected(request('source') === 'landing_page')>Landing page</option></select>
            <select name="status" class="form-control w-auto" aria-label="Status"><option value="">All statuses</option>@foreach(['pending', 'processing', 'processed', 'duplicate', 'failed', 'spam'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
            <button class="btn btn-sm">Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Received</th><th>Source</th><th>External ID</th><th>Status</th><th>Lead</th><th>Attribution</th><th>Error</th><th></th></tr></thead>
            <tbody>
            @forelse($events as $e)
                <tr>
                    <td><small>{{ $e->created_at->format('d M Y, h:i A') }}</small></td>
                    <td><span class="badge">{{ $e->source }}</span></td>
                    <td><small>{{ \Illuminate\Support\Str::limit($e->external_id, 24) }}</small></td>
                    <td><span class="badge badge-{{ $e->status }}">{{ $e->status }}</span>@if($e->attempts > 1)<br><small>{{ $e->attempts }} attempts</small>@endif</td>
                    <td>@if($e->lead)<a href="{{ route('leads.show', $e->lead) }}">{{ $e->lead->display_name }}</a>@else — @endif</td>
                    <td><small>{{ collect($e->attribution ?? [])->only(['campaign_name', 'utm_source', 'utm_campaign', 'platform', 'form_id'])->map(fn ($v, $k) => "$k: $v")->implode(' · ') ?: '—' }}</small></td>
                    <td><small class="text-danger">{{ \Illuminate\Support\Str::limit($e->error, 120) }}</small></td>
                    <td>
                        @if($e->source === 'meta' && in_array($e->status, ['failed', 'pending']))
                            <form method="POST" action="{{ route('lead-sources.retry', $e) }}" class="inline-form">@csrf<button class="btn btn-outline btn-sm">Retry</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No events yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>Landing-page form setup</h2>
        <form method="POST" action="{{ route('lead-sources.settings') }}">
            @csrf
            <div class="form-group">
                <label for="ls-origins">Allowed website origins (CORS)</label>
                <textarea id="ls-origins" name="public_form_allowed_origins" class="form-control" rows="3" placeholder="https://www.yoursite.com&#10;https://landing.yoursite.com">{{ $origins }}</textarea>
                <div class="help">One per line. Browsers on other sites cannot post to your form endpoint. (CORS is a browser rule, not a password — the rate limit and spam trap still apply to everyone.)</div>
            </div>
            <div class="form-group">
                <label>Form key {!! $formKey ? '<span class="badge badge-sent">active</span>' : '<span class="badge badge-outline">off</span>' !!}</label>
                @if($formKey)<span class="code">{{ $formKey }}</span>@endif
                <label class="check mt"><input type="checkbox" name="regenerate_key" value="1"> {{ $formKey ? 'Generate a new key' : 'Require a form key' }}</label>
                @if($formKey)<label class="check"><input type="checkbox" name="remove_key" value="1"> Turn off form key</label>@endif
                <div class="help">Optional. Identifies your own forms (send as header <code>X-Form-Key</code> or field <code>form_key</code>).</div>
            </div>
            <button class="btn">Save</button>
        </form>
    </div>
    <div class="card">
        <h2>Connect your landing page</h2>
        <p>Endpoint: <span class="code">POST {{ $endpoint }}</span></p>
        <p class="help">Fields: name, phone and/or email (one is required), company, city, interested_service, budget, message, consent, utm_source, utm_medium, utm_campaign, utm_content, landing_page_url, referrer, submission_id (optional, prevents double submits). Keep a hidden empty field named <code>hp_field</code> as spam trap. Also accepted: work_email, business / website, automate, whatsapp.</p>
<pre class="json">&lt;form id="lead-form"&gt;
  &lt;input name="name" required&gt; &lt;input name="phone" required&gt;
  &lt;input name="email" type="email"&gt; &lt;textarea name="message"&gt;&lt;/textarea&gt;
  &lt;input name="hp_field" style="display:none" tabindex="-1" autocomplete="off"&gt;
  &lt;button&gt;Send&lt;/button&gt;
&lt;/form&gt;
&lt;script&gt;
document.getElementById('lead-form').addEventListener('submit', async e =&gt; {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target));
  const p = new URLSearchParams(location.search);
  ['utm_source','utm_medium','utm_campaign','utm_content'].forEach(k =&gt; data[k] = p.get(k) || '');
  data.landing_page_url = location.href; data.referrer = document.referrer;
  data.submission_id = crypto.randomUUID();
  const res = await fetch('{{ $endpoint }}', { method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json'{{ $formKey ? ", 'X-Form-Key': '".$formKey."'" : '' }} },
    body: JSON.stringify(data) });
  const out = await res.json(); alert(out.message);
});
&lt;/script&gt;</pre>
    </div>
</div>
@endsection
