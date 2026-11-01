@extends('layouts.app')
@section('title', $lead->display_name)

@php($u = auth()->user())
@php($canLog = $u->can('logActivity', $lead))
@php($canMeet = $u->can('scheduleMeeting', $lead))
@php($canUpdate = $u->can('update', $lead))
@php($dueTask = $lead->tasks->first(fn ($t) => $t->status === 'open' && $t->due_at->lte(now()->endOfDay())))

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $lead->display_name }}
            <span class="badge badge-{{ $lead->status }}">{{ $lead->status_label }}</span>
            @if($lead->temperature)<span class="badge badge-{{ $lead->temperature }}">{{ $lead->temperature_label }}</span>@endif
            @if($lead->priority !== 'normal')<span class="badge badge-{{ $lead->priority }}">{{ $lead->priority }} priority</span>@endif
        </h1>
        <small>Lead #{{ $lead->id }} · {{ \App\Models\Lead::SOURCES[$lead->source] ?? $lead->source }} · {{ $lead->created_at->format('d M Y, h:i A') }}
            · Owner: <strong>{{ $lead->agent?->name ?? 'Unassigned' }}</strong>
            @if($lead->is_follow_up_overdue) · <span class="overdue">Follow-up overdue</span>@endif</small>
    </div>
    <div class="btn-group">
        @if($canLog)
            <button type="button" class="btn" data-open="dlg-activity" data-fill-type="call">Log call</button>
            <button type="button" class="btn btn-outline" data-open="dlg-activity" data-fill-type="note">Log activity</button>
            <button type="button" class="btn btn-outline" data-open="dlg-task">+ Follow-up</button>
        @endif
        @if($canMeet)<button type="button" class="btn btn-outline" data-open="dlg-meeting">+ Meeting</button>@endif
        @if($lead->phone)<a href="https://wa.me/{{ $lead->phone }}" target="_blank" rel="noopener" class="btn btn-whatsapp">WhatsApp</a>@endif
        <a href="{{ route('proposals.create', ['lead' => $lead->id]) }}" class="btn btn-outline">+ Proposal</a>
        @if($canUpdate)<a href="{{ route('leads.edit', $lead) }}" class="btn btn-outline">Edit</a>@endif
        @can('delete', $lead)
            <form method="POST" action="{{ route('leads.destroy', $lead) }}" class="inline-form" data-confirm="Delete this lead and all its history?">
                @csrf @method('DELETE')
                <button class="btn btn-red">Delete</button>
            </form>
        @endcan
    </div>
</div>

<div class="grid grid-main">
    {{-- ================= LEFT ================= --}}
    <div>
        @if($u->can('viewConversation', $lead))
            @include('leads._analysis', ['lead' => $lead, 'analysis' => $analysis])
        @endif

        <div class="card" id="whatsapp">
            <div class="card-head"><h2>WhatsApp</h2>
                <div class="btn-group">
                    @if($lead->whatsapp_opt_out)<span class="badge badge-failed">Opted out (STOP)</span>@endif
                    @if($lead->messages->count())<a href="{{ route('conversations.show', $lead) }}" class="btn btn-outline btn-sm">Full conversation</a>@endif
                </div>
            </div>
            <div class="chat" id="chat">
                @forelse($lead->messages as $m)
                    <div class="bubble {{ $m->direction }}" id="msg-{{ $m->id }}">
                        <div class="text">{{ $m->body }}</div>
                        <div class="meta">
                            {{ $m->sent_at->format('d M, h:i A') }}
                            @if($m->direction === 'out') · {{ $m->user?->name ?? 'Auto' }} · <span class="badge badge-{{ $m->status }}">{{ $m->status }}</span>@endif
                            @if($m->error)<br><span class="text-danger">{{ $m->error }}</span>@endif
                        </div>
                    </div>
                @empty
                    <p class="muted">No WhatsApp messages yet.</p>
                @endforelse
            </div>

            @if($canLog)
            <form method="POST" action="{{ route('leads.whatsapp', $lead) }}" class="mt" id="wa-form">
                @csrf
                <div class="flex mb-0" style="margin-bottom:10px">
                    <label class="check"><input type="radio" name="type" value="text" checked> Text message</label>
                    <label class="check"><input type="radio" name="type" value="template"> Approved template</label>
                </div>
                <div data-type="text">
                    <div class="form-group">
                        <textarea name="message" class="form-control" placeholder="Type a message... you can use {name}, {agent}, {company}" aria-label="WhatsApp message"></textarea>
                        <div class="help">Free-text messages are only delivered if the customer messaged you in the last 24 hours. Otherwise use an approved template.</div>
                    </div>
                </div>
                <div data-type="template" style="display:none">
                    <div class="form-row-3">
                        <div class="form-group"><label>Template name</label><input type="text" name="template_name" class="form-control" placeholder="lead_welcome"></div>
                        <div class="form-group"><label>Language</label><input type="text" name="template_language" class="form-control" value="en"></div>
                        <div class="form-group"><label>Variables (separated by | )</label><input type="text" name="variables" class="form-control" placeholder="{name}|{company}"></div>
                    </div>
                </div>
                <button class="btn btn-whatsapp">Send WhatsApp</button>
            </form>
            @endif
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Activity Timeline</h2>
                @if($canLog)<button type="button" class="btn btn-sm" data-open="dlg-activity" data-fill-type="call">+ Log activity</button>@endif
            </div>
            <ul class="tl">
                @forelse($lead->activities as $a)
                    @php($sales = $a->isSalesActivity() && $a->user_id)
                    <li class="{{ $sales ? 'sales' : '' }} {{ $a->type === 'ai_analysis' ? 'ai' : '' }}">
                        <span class="badge {{ $sales ? 'badge-qualified' : '' }}">{{ $a->type_label }}</span>
                        @if($a->outcome)<span class="badge badge-{{ $a->outcome }}">{{ $a->outcome_label }}</span>@endif
                        @if($a->duration_label)<span class="badge badge-outline">{{ $a->duration_label }} min</span>@endif
                        @if($a->is_manual)<span class="badge badge-outline" title="Entered by the agent — not verified by a phone system">manual log</span>@endif
                        @if($a->is_verified)<span class="badge badge-sent">verified</span>@endif
                        <div class="desc">{{ $a->description }}</div>
                        <div class="when">{{ $a->user?->name ?? 'System' }} · {{ ($a->occurred_at ?? $a->created_at)->format('d M Y, h:i A') }}
                            @if($a->next_follow_up_at) · next follow-up {{ $a->next_follow_up_at->format('d M, h:i A') }}@endif</div>
                    </li>
                @empty
                    <li class="muted">No activity yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Automated Follow-ups <small>(WhatsApp &amp; Email)</small></h2>
                @if($canUpdate)
                <div class="btn-group">
                    <form method="POST" action="{{ route('leads.followups', $lead) }}" class="inline-form">
                        @csrf
                        <button class="btn btn-outline btn-sm">{{ $lead->followups_paused ? 'Resume' : 'Pause' }}</button>
                    </form>
                    <form method="POST" action="{{ route('leads.followups', $lead) }}" class="inline-form" data-confirm="Restart the follow-up sequence from the beginning?">
                        @csrf <input type="hidden" name="action" value="restart">
                        <button class="btn btn-outline btn-sm">Restart</button>
                    </form>
                </div>
                @endif
            </div>
            @if($lead->followups_paused)<div class="alert alert-info">Automated follow-ups are paused — a human is handling this chat.</div>@endif
            <table class="table">
                <thead><tr><th>Step</th><th>Channel</th><th>Scheduled</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($lead->followups as $f)
                    <tr>
                        <td>{{ $f->step?->name ?? '—' }}</td><td><span class="badge">{{ $f->step?->channel ?? "whatsapp" }}</span></td>
                        <td>{{ $f->scheduled_at->format('d M, h:i A') }}<br><small>{{ $f->sent_at ? 'Sent '.$f->sent_at->format('d M, h:i A') : $f->scheduled_at->diffForHumans() }}</small></td>
                        <td><span class="badge badge-{{ $f->status }}">{{ $f->status }}</span>@if($f->error)<br><small>{{ $f->error }}</small>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No automated follow-ups (phone missing or auto follow-ups off).</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ================= RIGHT ================= --}}
    <div>
        @if($canUpdate)
        <div class="card">
            <h2>Sales Stage</h2>
            <form method="POST" action="{{ route('leads.status', $lead) }}">
                @csrf
                <div class="form-group">
                    <label for="status-select">Status</label>
                    <select name="status" class="form-control" id="status-select">
                        @foreach(\App\Models\Lead::STATUSES as $k => $v)
                            <option value="{{ $k }}" @selected($lead->status === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" id="lost-reason" style="{{ $lead->status === 'lost' ? '' : 'display:none' }}">
                    <input type="text" name="lost_reason" class="form-control" placeholder="Lost reason (price, timing...)" value="{{ $lead->lost_reason }}" aria-label="Lost reason">
                </div>
                <button class="btn btn-sm">Update Status</button>
            </form>
        </div>
        @endif

        <div class="card">
            <div class="card-head">
                <h2>Segmentation</h2>
                <span class="score {{ $lead->score >= 60 ? 's-high' : ($lead->score >= 30 ? 's-mid' : '') }}" title="Lead score (rule based)">{{ $lead->score }}</span>
            </div>
            @if($canUpdate)
            <form method="POST" action="{{ route('leads.segment', $lead) }}">
                @csrf
                <div class="form-row">
                    <div class="form-group">
                        <label for="seg-temp">Temperature @if($lead->temperature_source)<small>({{ $lead->temperature_source }})</small>@endif</label>
                        <select id="seg-temp" name="temperature" class="form-control">
                            <option value="">Not set</option>
                            @foreach(config('crm.temperatures') as $k => $v)<option value="{{ $k }}" @selected($lead->temperature === $k)>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="seg-qual">Qualification @if($lead->qualification_source)<small>({{ $lead->qualification_source }})</small>@endif</label>
                        <select id="seg-qual" name="qualification" class="form-control">
                            @foreach(config('crm.qualifications') as $k => $v)<option value="{{ $k }}" @selected($lead->qualification === $k)>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="seg-intent">Buying intent</label>
                        <select id="seg-intent" name="buying_intent" class="form-control">
                            <option value="">—</option>
                            @foreach(config('crm.buying_intents') as $k => $v)<option value="{{ $k }}" @selected($lead->buying_intent === $k)>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group"><label for="seg-tl">Timeline</label><input id="seg-tl" type="text" name="timeline" class="form-control" value="{{ $lead->timeline }}" placeholder="e.g. next month"></div>
                </div>
                <div class="form-group"><label for="seg-ind">Industry</label><input id="seg-ind" type="text" name="industry" class="form-control" value="{{ $lead->industry }}" placeholder="e.g. Real estate"></div>
                <div class="form-group">
                    <label>Tags</label>
                    <div>
                        @foreach($allTags as $t)
                            @php($pivot = $lead->tags->firstWhere('id', $t->id))
                            <label class="check" style="display:inline-flex;margin:0 10px 6px 0;font-size:13px"><input type="checkbox" name="tag_ids[]" value="{{ $t->id }}" @checked($pivot)> {{ $t->name }}@if($pivot && $pivot->pivot->source === 'ai') <span class="badge badge-ai" title="Added by AI">AI</span>@endif</label>
                        @endforeach
                    </div>
                    @can('tags.manage')<input type="text" name="new_tags" class="form-control mt" placeholder="New tags, comma separated" aria-label="New tags">@endcan
                </div>
                <button class="btn btn-sm">Save segmentation</button>
            </form>
            @else
                <div class="kv"><span>Temperature</span><span>{{ $lead->temperature_label ?? '—' }}</span></div>
                <div class="kv"><span>Qualification</span><span>{{ $lead->qualification_label }}</span></div>
                <p class="mt">@foreach($lead->tags as $t)<span class="tag-chip"><span class="dot" style="background:{{ $t->color }}"></span>{{ $t->name }}</span>@endforeach</p>
            @endif
            <details class="mt">
                <summary class="help" style="cursor:pointer">Why score {{ $lead->score }}?</summary>
                <ul class="help">@forelse($scoreInfo['reasons'] as $r)<li>{{ $r }}</li>@empty<li>No scoring signals yet.</li>@endforelse</ul>
            </details>
        </div>

        <div class="card">
            <div class="card-head"><h2>Owner</h2><button type="button" class="btn btn-outline btn-sm" data-open="dlg-history">History ({{ $lead->assignments->count() }})</button></div>
            <p><strong>{{ $lead->agent?->name ?? 'Unassigned' }}</strong>
                @if($lead->assigned_at)<br><small>since {{ $lead->assigned_at->format('d M Y, h:i A') }}{{ $lead->assigner ? ' · by '.$lead->assigner->name : ' · automatic' }}</small>@endif</p>
            @can('assign', $lead)
                <form method="POST" action="{{ route('leads.assign', $lead) }}">
                    @csrf
                    <div class="form-group">
                        <label for="assign-to">{{ $lead->assigned_to ? 'Reassign to' : 'Assign to' }}</label>
                        <select id="assign-to" name="assigned_to" class="form-control">
                            @if($lead->assigned_to)<option value="">— Unassign —</option>@endif
                            @foreach($agents as $a)
                                <option value="{{ $a->id }}" @selected($lead->assigned_to === $a->id)>{{ $a->name }} ({{ $a->role_label }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" name="reason" class="form-control" maxlength="500" placeholder="{{ $lead->assigned_to ? 'Reason for reassignment (required)' : 'Note (optional)' }}" @if($lead->assigned_to) required @endif aria-label="Reason">
                    </div>
                    <button class="btn btn-sm">{{ $lead->assigned_to ? 'Reassign' : 'Assign' }}</button>
                </form>
            @endcan
        </div>

        <div class="card">
            <div class="card-head"><h2>Follow-ups</h2>@if($canLog)<button type="button" class="btn btn-outline btn-sm" data-open="dlg-task">+ Add</button>@endif</div>
            <ul class="list-plain">
                @forelse($lead->tasks as $t)
                    <li>
                        <div class="flex between">
                            <div>
                                <strong class="{{ $t->is_overdue ? 'overdue' : '' }}">{{ $t->due_at->format('d M, h:i A') }}</strong>
                                <span class="badge badge-{{ $t->status }}">{{ $t->is_overdue ? 'overdue' : $t->status }}</span><br>
                                <small>{{ $t->title }} · {{ $t->user?->name ?? '—' }}</small>
                            </div>
                            @if($t->status === 'open' && $canLog)
                                <form method="POST" action="{{ route('tasks.update', $t) }}" class="inline-form">
                                    @csrf <input type="hidden" name="action" value="complete">
                                    <button class="btn btn-sm" title="Mark done">Done</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="muted">No follow-ups scheduled.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-head"><h2>Meetings</h2>@if($canMeet)<button type="button" class="btn btn-outline btn-sm" data-open="dlg-meeting">+ Book</button>@endif</div>
            <ul class="list-plain">
                @forelse($lead->meetings as $m)
                    <li>
                        <a href="{{ route('meetings.show', $m) }}">{{ $m->starts_at->format('D d M, h:i A') }}</a>
                        <span class="badge badge-{{ $m->status }}">{{ $m->status_label }}</span><br>
                        <small>{{ $m->type_label }} · {{ $m->agent?->name ?? '—' }}{{ $m->outcome ? ' · '.$m->outcome : '' }}</small>
                    </li>
                @empty
                    <li class="muted">No meetings yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <h2>Details</h2>
            <dl class="dl">
                <dt>Phone</dt><dd>{{ $lead->phone ?: '—' }}</dd>
                <dt>Email</dt><dd>{{ $lead->email ?: '—' }}</dd>
                <dt>Company</dt><dd>{{ $lead->company ?: '—' }}</dd>
                <dt>City</dt><dd>{{ $lead->city ?: '—' }}</dd>
                <dt>Service</dt><dd>{{ $lead->service ?: '—' }}</dd>
                <dt>Budget</dt><dd>{{ $lead->budget ?: '—' }}</dd>
                <dt>Next follow-up</dt><dd class="{{ $lead->is_follow_up_overdue ? 'overdue' : '' }}">{{ $lead->next_follow_up_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                <dt>First contact</dt><dd>{{ $lead->first_contacted_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                <dt>Last contacted</dt><dd>{{ $lead->last_contacted_at?->diffForHumans() ?? '—' }}</dd>
                <dt>Last reply</dt><dd>{{ $lead->last_reply_at?->diffForHumans() ?? '—' }}</dd>
            </dl>
            @if($lead->notes)<p class="mt"><strong>Notes:</strong><br>{!! nl2br(e($lead->notes)) !!}</p>@endif
        </div>

        @if($lead->source === 'meta' || $lead->campaign_name || $lead->utm_source || $lead->landing_page_url)
        <div class="card">
            <h2>Source Attribution</h2>
            <dl class="dl">
                @foreach([
                    'Campaign' => $lead->campaign_name, 'Ad set' => $lead->adset_name, 'Ad' => $lead->ad_name,
                    'Form' => $lead->form_name ?: $lead->meta_form_id, 'Platform' => $lead->platform,
                    'Campaign ID' => $lead->meta_campaign_id, 'Ad ID' => $lead->meta_ad_id, 'Meta lead ID' => $lead->meta_lead_id,
                    'UTM source' => $lead->utm_source, 'UTM medium' => $lead->utm_medium, 'UTM campaign' => $lead->utm_campaign, 'UTM content' => $lead->utm_content,
                    'Landing page' => $lead->landing_page_url, 'Referrer' => $lead->referrer,
                    'Submitted' => $lead->submitted_at?->format('d M Y, h:i A'),
                ] as $k => $v)
                    @if($v)<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endif
                @endforeach
            </dl>
            @if(! empty($lead->raw_data['field_data']))
                <h3 class="mt">Form answers</h3>
                <dl class="dl">
                    @foreach($lead->raw_data['field_data'] as $field)
                        <dt>{{ str_replace('_', ' ', $field['name'] ?? '') }}</dt><dd>{{ implode(', ', $field['values'] ?? []) }}</dd>
                    @endforeach
                </dl>
            @endif
        </div>
        @endif

        @php($invoices = $lead->invoices()->latest()->get())
        @if($invoices->count())
        <div class="card">
            <h2>Invoices</h2>
            @foreach($invoices as $inv)
                <p><a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a> — due {{ $inv->money($inv->amount_due) }} <span class="badge badge-{{ $inv->status }}">{{ $inv->status }}</span></p>
            @endforeach
        </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>Proposals</h2></div>
            @forelse($lead->proposals as $p)
                <p><a href="{{ route('proposals.show', $p) }}">{{ $p->number }}</a> — {{ $p->money($p->total) }}
                    <span class="badge badge-{{ $p->status }}">{{ $p->status }}</span>@if($p->source === 'auto') <span class="badge badge-auto">auto</span>@endif</p>
            @empty
                <p class="muted">No proposals yet.</p>
            @endforelse
        </div>
    </div>
</div>

{{-- ================= DIALOGS ================= --}}
@if($canLog)
<dialog class="modal" id="dlg-activity">
    <div class="modal-head"><h2>Log activity</h2><button type="button" class="close" data-close aria-label="Close">&times;</button></div>
    <div class="modal-body">
        <form method="POST" action="{{ route('leads.activities.store', $lead) }}" id="activity-form">
            @csrf
            <div class="form-row">
                <div class="form-group">
                    <label for="act-type">Type</label>
                    <select id="act-type" name="type" class="form-control">
                        @foreach(config('crm.loggable_activity_types') as $t)<option value="{{ $t }}" @selected(old('type') === $t)>{{ config('crm.activity_types')[$t] }}</option>@endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="act-when">When</label>
                    <input id="act-when" type="datetime-local" name="occurred_at" class="form-control" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}">
                </div>
            </div>
            <div data-for="call">
                <div class="form-group">
                    <label>Call outcome *</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px 14px">
                        @foreach(config('crm.call_outcomes') as $k => $v)
                            <label class="check" style="font-size:13.5px"><input type="radio" name="outcome" value="{{ $k }}" @checked(old('outcome') === $k)> {{ $v }}</label>
                        @endforeach
                    </div>
                </div>
                <div class="form-group">
                    <label>Call duration (as reported)</label>
                    <div class="flex">
                        <input type="number" name="duration_minutes" class="form-control" min="0" max="600" placeholder="min" style="width:90px" aria-label="Minutes" value="{{ old('duration_minutes') }}">
                        <span>:</span>
                        <input type="number" name="duration_seconds_part" class="form-control" min="0" max="59" placeholder="sec" style="width:90px" aria-label="Seconds" value="{{ old('duration_seconds_part') }}">
                    </div>
                    <div class="help">Manual call log — it is recorded as reported by you, not verified by a phone system.</div>
                </div>
            </div>
            <div class="form-group">
                <label for="act-notes">Notes</label>
                <textarea id="act-notes" name="notes" class="form-control" placeholder="What did the customer say? Requirements, objections, next step...">{{ old('notes') }}</textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="act-next">Next follow-up</label>
                    <input id="act-next" type="datetime-local" name="next_follow_up_at" class="form-control" value="{{ old('next_follow_up_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}">
                    <div class="help">Quick: <a href="#" data-quick="1">tomorrow</a> · <a href="#" data-quick="3">3 days</a> · <a href="#" data-quick="7">1 week</a></div>
                </div>
                @if($dueTask)
                <div class="form-group" style="align-self:center">
                    <label class="check"><input type="checkbox" name="complete_due_tasks" value="1" checked> Mark today's follow-up as done</label>
                </div>
                @endif
            </div>
            <button class="btn">Save activity</button>
        </form>
    </div>
</dialog>

<dialog class="modal" id="dlg-task">
    <div class="modal-head"><h2>Schedule follow-up</h2><button type="button" class="close" data-close aria-label="Close">&times;</button></div>
    <div class="modal-body">
        <form method="POST" action="{{ route('leads.tasks.store', $lead) }}">
            @csrf
            <div class="form-group"><label for="task-due">Due *</label><input id="task-due" type="datetime-local" name="due_at" class="form-control" required min="{{ now()->format('Y-m-d\TH:i') }}"></div>
            <div class="form-group"><label for="task-title">What to do</label><input id="task-title" type="text" name="title" class="form-control" maxlength="255" placeholder="Call back about pricing"></div>
            <div class="form-group"><label for="task-notes">Notes</label><textarea id="task-notes" name="notes" class="form-control"></textarea></div>
            <button class="btn">Schedule</button>
        </form>
    </div>
</dialog>
@endif

@if($canMeet)
<dialog class="modal" id="dlg-meeting">
    <div class="modal-head"><h2>Book a meeting</h2><button type="button" class="close" data-close aria-label="Close">&times;</button></div>
    <div class="modal-body">
        <form method="POST" action="{{ route('leads.meetings.store', $lead) }}">
            @csrf
            <div class="form-group"><label for="m-title">Title</label><input id="m-title" type="text" name="title" class="form-control" value="{{ old('title', 'Meeting with '.$lead->display_name) }}" maxlength="255"></div>
            <div class="form-row">
                <div class="form-group"><label for="m-start">Starts *</label><input id="m-start" type="datetime-local" name="starts_at" class="form-control" required value="{{ old('starts_at') }}"></div>
                <div class="form-group"><label for="m-end">Ends *</label><input id="m-end" type="datetime-local" name="ends_at" class="form-control" required value="{{ old('ends_at') }}"></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="m-type">Type</label>
                    <select id="m-type" name="meeting_type" class="form-control">
                        @foreach(config('crm.meeting_types') as $k => $v)<option value="{{ $k }}" @selected(old('meeting_type') === $k)>{{ $v }}</option>@endforeach
                    </select>
                </div>
                @if($u->hasPermission('meetings.view-team'))
                <div class="form-group">
                    <label for="m-agent">Agent</label>
                    <select id="m-agent" name="assigned_agent_id" class="form-control">
                        @foreach($agents as $a)<option value="{{ $a->id }}" @selected(($lead->assigned_to ?? $u->id) === $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
                @endif
            </div>
            <div class="form-group"><label for="m-link">Meeting link (online)</label><input id="m-link" type="url" name="meeting_link" class="form-control" placeholder="https://meet.google.com/..." value="{{ old('meeting_link') }}"></div>
            <div class="form-group"><label for="m-loc">Location (in-person)</label><input id="m-loc" type="text" name="location" class="form-control" value="{{ old('location') }}"></div>
            <div class="form-group"><label for="m-notes">Agenda / notes</label><textarea id="m-notes" name="notes" class="form-control">{{ old('notes') }}</textarea></div>
            @if($u->hasPermission('meetings.view-team'))
                <div class="form-group"><label class="check"><input type="checkbox" name="allow_conflict" value="1"> Allow double-booking if the agent is busy</label></div>
            @endif
            <p class="help">Times are in {{ config('app.timezone') }}. The agent gets a reminder {{ \App\Models\Setting::get('meeting_reminder_minutes', 30) }} minutes before.</p>
            <button class="btn">Book meeting</button>
        </form>
    </div>
</dialog>
@endif

<dialog class="modal wide" id="dlg-history">
    <div class="modal-head"><h2>Assignment history</h2><button type="button" class="close" data-close aria-label="Close">&times;</button></div>
    <div class="modal-body">
        <table class="table">
            <thead><tr><th>Owner</th><th>From</th><th>Until</th><th>By</th><th>Method / reason</th></tr></thead>
            <tbody>
            @forelse($lead->assignments as $as)
                <tr>
                    <td><strong>{{ $as->user?->name ?? 'Deleted user' }}</strong> @if(! $as->unassigned_at)<span class="badge badge-qualified">current</span>@endif</td>
                    <td><small>{{ $as->assigned_at->format('d M Y, h:i A') }}</small></td>
                    <td><small>{{ $as->unassigned_at?->format('d M Y, h:i A') ?? '—' }}{{ $as->unassigner ? ' by '.$as->unassigner->name : '' }}</small></td>
                    <td><small>{{ $as->assigner?->name ?? 'System' }}</small></td>
                    <td><small>{{ \App\Models\LeadAssignment::METHODS[$as->method] ?? $as->method }}{{ $as->reason ? ' — '.$as->reason : '' }}</small></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Never assigned.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
    const chat = document.getElementById('chat');
    if (chat) chat.scrollTop = chat.scrollHeight;

    document.querySelectorAll('#wa-form input[name="type"]').forEach(r => r.addEventListener('change', () => {
        document.querySelectorAll('#wa-form [data-type]').forEach(el => el.style.display = el.dataset.type === r.value ? '' : 'none');
    }));

    document.getElementById('status-select')?.addEventListener('change', e => {
        document.getElementById('lost-reason').style.display = e.target.value === 'lost' ? '' : 'none';
    });

    // Activity dialog: show call fields only for calls
    const actType = document.getElementById('act-type');
    const syncType = () => document.querySelectorAll('#activity-form [data-for="call"]').forEach(el => el.style.display = actType.value === 'call' ? '' : 'none');
    actType?.addEventListener('change', syncType);
    document.querySelectorAll('[data-open="dlg-activity"]').forEach(b => b.addEventListener('click', () => setTimeout(syncType)));
    syncType && actType && syncType();
    document.querySelectorAll('[data-quick]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        const d = new Date(); d.setDate(d.getDate() + parseInt(a.dataset.quick)); d.setHours(11, 0, 0, 0);
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('act-next').value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }));

    // Meeting: default end = start + 30 min
    document.getElementById('m-start')?.addEventListener('change', e => {
        const end = document.getElementById('m-end');
        if (!e.target.value) return;
        const d = new Date(e.target.value); d.setMinutes(d.getMinutes() + 30);
        const pad = n => String(n).padStart(2, '0');
        end.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    });

    // Re-open the dialog that had validation errors
    @if($errors->any() && old('_dialog'))
        document.getElementById(@json(old('_dialog')))?.showModal();
    @elseif($errors->has('outcome') || $errors->has('notes') || $errors->has('occurred_at'))
        document.getElementById('dlg-activity')?.showModal();
    @elseif($errors->has('starts_at') || $errors->has('ends_at') || $errors->has('meeting_link'))
        document.getElementById('dlg-meeting')?.showModal();
    @endif
</script>
@endpush
