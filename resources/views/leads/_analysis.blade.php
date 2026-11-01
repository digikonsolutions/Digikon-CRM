{{-- AI segregation of the lead's WhatsApp conversation. Needs: $lead, $analysis (latest completed or null) --}}
@php($canRun = auth()->user()->can('runAnalysis', $lead))
@php($canReview = auth()->user()->can('reviewAnalysis', $lead))
@php($aiOn = \App\Services\Ai\AiClientFactory::isConfigured())
@php($msgCount = $lead->relationLoaded('messages') ? $lead->messages->count() : $lead->messages()->count())
@php($lastFailed = $lead->relationLoaded('analyses') ? $lead->analyses->first() : null)

<div class="card ai-card" id="ai-analysis">
    <div class="card-head">
        <h2>AI Lead Segregation <span class="badge badge-ai">WhatsApp chat</span></h2>
        @if($canRun)
            <form method="POST" action="{{ route('leads.analyses.store', $lead) }}" class="inline-form">
                @csrf
                <button class="btn btn-sm {{ $analysis ? 'btn-outline' : '' }}" @disabled(! $aiOn || ! $msgCount) title="{{ ! $aiOn ? 'Add an AI API key in Settings → AI Analysis' : (! $msgCount ? 'No WhatsApp messages yet' : 'Read the full WhatsApp chat and classify this lead') }}">
                    {{ $analysis ? 'Re-analyse chat' : 'Analyse WhatsApp chat' }}
                </button>
            </form>
        @endif
    </div>

    @if(! $aiOn)
        <p class="muted">AI is not configured yet. {!! auth()->user()->hasPermission('settings.manage') ? '<a href="'.route('settings.index', ['tab' => 'ai']).'">Add an API key in Settings → AI Analysis</a>.' : 'Ask an admin to add an AI API key.' !!}</p>
    @endif

    @if($lastFailed && $lastFailed->status === 'failed' && (! $analysis || $lastFailed->id > $analysis->id))
        <div class="alert alert-error">Last analysis failed: {{ \Illuminate\Support\Str::limit($lastFailed->error, 200) }}</div>
    @endif

    @if(! $analysis)
        <p class="muted">{{ $msgCount ? "The AI will read all {$msgCount} WhatsApp message(s) of this lead and suggest temperature (hot / warm / cold), qualification, service, budget, timeline, industry, tags and next steps — every suggestion with a quote from the chat as proof." : 'No WhatsApp conversation with this lead yet. Once messages arrive, the AI can classify the lead from the chat.' }}</p>
    @else
        <div class="flex between" style="flex-wrap:wrap;margin-bottom:8px">
            <small>
                {{ $analysis->messages_analyzed }} messages · {{ $analysis->created_at->format('d M, h:i A') }} · {{ $analysis->trigger === 'auto' ? 'automatic' : ($analysis->requester?->name ?? $analysis->trigger) }}
                @if($analysis->confidence !== null) · confidence <span class="confidence" title="{{ round($analysis->confidence * 100) }}%"><span style="width: {{ round($analysis->confidence * 100) }}%"></span></span> {{ round($analysis->confidence * 100) }}% @endif
            </small>
            <span class="badge badge-{{ $analysis->review_status }}">{{ $analysis->review_label }}</span>
        </div>
        @if($lead->messages()->where('direction', 'in')->where('id', '>', (int) $analysis->last_message_id)->exists())
            <div class="alert alert-info">New customer messages arrived after this analysis. Re-analyse to include them.</div>
        @endif

        <p class="ai-summary">{{ $analysis->summary }}</p>

        <div class="grid grid-2" style="gap:0 24px">
            @foreach([
                'temperature' => ['Temperature', $analysis->temperature ? (config('crm.temperatures')[$analysis->temperature] ?? $analysis->temperature) : null],
                'qualification' => ['Qualification', $analysis->qualification ? (config('crm.qualifications')[$analysis->qualification] ?? $analysis->qualification) : null],
                'buying_intent' => ['Buying intent', $analysis->buying_intent && $analysis->buying_intent !== 'unknown' ? ucfirst($analysis->buying_intent) : null],
                'service_interest' => ['Service interest', $analysis->service_interest],
                'budget' => ['Budget', $analysis->budget],
                'timeline' => ['Timeline', $analysis->timeline],
                'industry' => ['Industry', $analysis->industry],
                'recommended_status' => ['Suggested stage', $analysis->recommended_status ? (\App\Models\Lead::STATUSES[$analysis->recommended_status] ?? null) : null],
            ] as $field => [$label, $value])
                <div class="ai-field">
                    <div class="k">{{ $label }}</div>
                    <div class="v">
                        @if($value)
                            @if($field === 'temperature')<span class="badge badge-{{ $analysis->temperature }}">{{ $value }}</span>@else{{ $value }}@endif
                        @else <span class="muted" style="font-weight:500">Not mentioned in chat</span> @endif
                    </div>
                    @foreach($analysis->evidenceFor($field) as $ev)
                        <span class="quote">“{{ $ev['quote'] }}” <a href="{{ route('conversations.show', $lead) }}#msg-{{ $ev['message_id'] }}">msg #{{ $ev['message_id'] }}</a></span>
                    @endforeach
                </div>
            @endforeach
        </div>

        @if($analysis->meeting_at)
            <div class="alert alert-success mt">
                <strong>Customer agreed to a {{ str_replace('_', '-', $analysis->meeting_type) }} meeting:</strong> {{ $analysis->meeting_at->format('D d M Y, h:i A') }}
                @foreach($analysis->evidenceFor('meeting_request') as $ev)<span class="quote">“{{ $ev['quote'] }}”</span>@endforeach
                @if($analysis->meeting_id)<br><a href="{{ route('meetings.show', $analysis->meeting_id) }}">Booked automatically → open meeting</a>@else<br><small>Not booked automatically (see timeline for the reason) — book it with “+ Meeting”.</small>@endif
            </div>
        @endif

        @if($analysis->tags)
            <p class="mt"><strong>Suggested tags:</strong> @foreach($analysis->tags as $t)<span class="tag-chip">{{ $t }}</span>@endforeach</p>
        @endif

        @if($analysis->recommended_actions)
            <h3 class="mt">Recommended next steps</h3>
            <ul>@foreach($analysis->recommended_actions as $a)<li>{{ $a }}</li>@endforeach</ul>
            @if($analysis->follow_up_in_days !== null)<p class="help">Suggested follow-up: in {{ $analysis->follow_up_in_days }} day(s).</p>@endif
        @endif

        @if($analysis->rejected_fields)
            <p class="help">Ignored (AI gave no proof from the chat): {{ implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), array_keys($analysis->rejected_fields))) }}.</p>
        @endif

        @if($analysis->applied_fields)
            <p class="help">Written to lead: @foreach($analysis->applied_fields as $f => $c){{ str_replace('_', ' ', $f) }} → <strong>{{ is_array($c['to']) ? implode(', ', $c['to']) : ($c['to'] ?? '—') }}</strong>@if(! $loop->last), @endif @endforeach @if($analysis->reviewer) · reviewed by {{ $analysis->reviewer->name }}@endif</p>
        @endif

        @if($canReview && in_array($analysis->review_status, ['pending', 'auto_applied'], true))
            <div class="btn-group mt">
                <button type="button" class="btn btn-sm" data-open="review-{{ $analysis->id }}">Review &amp; apply</button>
                <form method="POST" action="{{ route('analyses.reject', $analysis) }}" class="inline-form" data-confirm="Reject this AI analysis?{{ $analysis->review_status === 'auto_applied' ? ' Auto-filled values that nobody changed since will be reverted.' : '' }}">
                    @csrf <button class="btn btn-red btn-sm">Reject</button>
                </form>
            </div>

            <dialog class="modal wide" id="review-{{ $analysis->id }}">
                <div class="modal-head"><h2>Review AI analysis</h2><button type="button" class="close" data-close aria-label="Close">&times;</button></div>
                <div class="modal-body">
                    <p class="help">Tick the fields to write to the lead. You can correct any value — corrected values are saved as <strong>manual</strong> and future AI runs will never overwrite them.</p>
                    <form method="POST" action="{{ route('analyses.approve', $analysis) }}">
                        @csrf
                        @foreach(['service_interest' => ['Service', $lead->service], 'budget' => ['Budget', $lead->budget], 'timeline' => ['Timeline', $lead->timeline], 'industry' => ['Industry', $lead->industry]] as $f => [$label, $current])
                            <div class="review-row">
                                <input type="checkbox" name="apply[]" value="{{ $f }}" id="ap-{{ $analysis->id }}-{{ $f }}" @checked(filled($analysis->{$f}) && $analysis->{$f} !== $current)>
                                <label for="ap-{{ $analysis->id }}-{{ $f }}" class="mb-0">{{ $label }}<br><small>now: {{ $current ?: '—' }}</small></label>
                                <input type="text" name="{{ $f }}" class="form-control" value="{{ $analysis->{$f} }}" maxlength="190" aria-label="{{ $label }}">
                            </div>
                        @endforeach
                        <div class="review-row">
                            <input type="checkbox" name="apply[]" value="buying_intent" id="ap-{{ $analysis->id }}-bi" @checked($analysis->buying_intent && $analysis->buying_intent !== 'unknown' && $analysis->buying_intent !== $lead->buying_intent)>
                            <label for="ap-{{ $analysis->id }}-bi" class="mb-0">Buying intent<br><small>now: {{ $lead->buying_intent ?: '—' }}</small></label>
                            <select name="buying_intent" class="form-control" aria-label="Buying intent">
                                @foreach(config('crm.buying_intents') as $k => $v)<option value="{{ $k }}" @selected($analysis->buying_intent === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="review-row">
                            <input type="checkbox" name="apply[]" value="temperature" id="ap-{{ $analysis->id }}-t" @checked($analysis->temperature && $analysis->temperature !== $lead->temperature)>
                            <label for="ap-{{ $analysis->id }}-t" class="mb-0">Temperature<br><small>now: {{ $lead->temperature_label ?? '—' }}{{ $lead->temperature_source ? ' ('.$lead->temperature_source.')' : '' }}</small></label>
                            <select name="temperature" class="form-control" aria-label="Temperature">
                                <option value="">— not set —</option>
                                @foreach(config('crm.temperatures') as $k => $v)<option value="{{ $k }}" @selected($analysis->temperature === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="review-row">
                            <input type="checkbox" name="apply[]" value="qualification" id="ap-{{ $analysis->id }}-q" @checked($analysis->qualification && $analysis->qualification !== $lead->qualification)>
                            <label for="ap-{{ $analysis->id }}-q" class="mb-0">Qualification<br><small>now: {{ $lead->qualification_label }}</small></label>
                            <select name="qualification" class="form-control" aria-label="Qualification">
                                @foreach(config('crm.qualifications') as $k => $v)<option value="{{ $k }}" @selected(($analysis->qualification ?? 'pending') === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="review-row">
                            <input type="checkbox" name="apply[]" value="tags" id="ap-{{ $analysis->id }}-tags" @checked(! empty($analysis->tags))>
                            <label for="ap-{{ $analysis->id }}-tags" class="mb-0">Add tags</label>
                            <input type="text" name="tags" class="form-control" value="{{ implode(', ', $analysis->tags ?? []) }}" placeholder="comma separated" aria-label="Tags">
                        </div>
                        <p class="help">The sales stage is never changed by AI — use the Status box if you agree with “{{ \App\Models\Lead::STATUSES[$analysis->recommended_status] ?? 'no change' }}”.</p>
                        <button class="btn">Apply selected to lead</button>
                    </form>
                </div>
            </dialog>
        @endif
    @endif

    @if($lead->relationLoaded('analyses') && $lead->analyses->count() > 1)
        <details class="mt">
            <summary class="help" style="cursor:pointer">Analysis history ({{ $lead->analyses->count() }})</summary>
            <ul class="list-plain">
                @foreach($lead->analyses as $a)
                    <li><small>#{{ $a->id }} · {{ $a->created_at->format('d M Y, h:i A') }} · {{ $a->status === 'failed' ? 'failed: '.\Illuminate\Support\Str::limit($a->error, 80) : ($a->temperature ?? 'no temperature').' · '.$a->review_label }}{{ $a->reviewer ? ' by '.$a->reviewer->name : '' }}</small></li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
