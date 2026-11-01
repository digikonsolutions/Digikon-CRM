<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignLeadRequest;
use App\Http\Requests\BulkAssignLeadsRequest;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowupService;
use App\Services\LeadActivityService;
use App\Services\LeadAssignmentService;
use App\Services\LeadScoreService;
use App\Services\LeadService;
use App\Services\LeadStatusService;
use App\Services\PhoneNumber;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $request->query('scope', 'all');
        if ($scope === 'unassigned' && ! $user->hasPermission('leads.assign')) {
            abort(403);
        }

        $leads = $this->filteredQuery($request)
            ->with(['agent', 'tags', 'latestMessage'])
            ->withMax(['activities as last_activity_at' => fn ($q) => $q->whereNotNull('user_id')], 'occurred_at')
            ->when($request->query('sort') === 'score', fn ($q) => $q->orderByDesc('score'))
            ->when($request->query('sort') === 'follow_up', fn ($q) => $q->orderByRaw('next_follow_up_at IS NULL')->orderBy('next_follow_up_at'))
            ->latest('leads.created_at')
            ->paginate(25)
            ->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'scope' => $scope,
            'agents' => User::where('is_active', true)->orderBy('name')->get(),
            'assignableAgents' => User::assignable()->orderBy('name')->get(),
            'campaigns' => Lead::visibleTo($user)->whereNotNull('campaign_name')->distinct()->orderBy('campaign_name')->pluck('campaign_name'),
            'tags' => Tag::orderBy('name')->get(),
            'unassignedCount' => $user->hasPermission('leads.assign') ? Lead::whereNull('assigned_to')->open()->count() : 0,
        ]);
    }

    /** Shared by index + CSV export. Always starts from the user's visible leads. */
    protected function filteredQuery(Request $request)
    {
        $user = $request->user();
        $scope = $request->query('scope', 'all');

        return Lead::visibleTo($user)
            ->when($scope === 'mine', fn ($q) => $q->where('leads.assigned_to', $user->id))
            ->when($scope === 'unassigned', fn ($q) => $q->whereNull('leads.assigned_to')->open())
            ->when($request->q, function ($q, $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->where(function ($w) use ($term, $digits) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('company', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                    if (strlen($digits) >= 4) {
                        $w->orWhere('phone', 'like', '%'.substr($digits, -10).'%');
                    }
                });
            })
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->source, fn ($q, $v) => $q->where('source', $v))
            ->when($request->campaign, fn ($q, $v) => $q->where('campaign_name', $v))
            ->when($request->temperature, fn ($q, $v) => $v === 'none' ? $q->whereNull('temperature') : $q->where('temperature', $v))
            ->when($request->qualification, fn ($q, $v) => $q->where('qualification', $v))
            ->when($request->priority, fn ($q, $v) => $q->where('priority', $v))
            ->when($request->service, fn ($q, $v) => $q->where('service', 'like', "%{$v}%"))
            ->when($request->industry, fn ($q, $v) => $q->where('industry', 'like', "%{$v}%"))
            ->when($request->tag, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $v)))
            ->when($request->date_from, fn ($q, $v) => $q->where('leads.created_at', '>=', \Illuminate\Support\Carbon::parse($v)->startOfDay()))
            ->when($request->date_to, fn ($q, $v) => $q->where('leads.created_at', '<=', \Illuminate\Support\Carbon::parse($v)->endOfDay()))
            ->when($request->boolean('overdue'), fn ($q) => $q->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now()))
            ->when($user->hasPermission('leads.view-all') && $request->agent === 'none', fn ($q) => $q->whereNull('leads.assigned_to'))
            ->when($user->hasPermission('leads.view-all') && $request->agent && $request->agent !== 'none', fn ($q) => $q->where('leads.assigned_to', $request->agent));
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->hasPermission('leads.export'), 403);
        $query = $this->filteredQuery($request)->with(['agent', 'tags'])->latest('leads.created_at');
        AuditLogger::log('leads.exported', null, [], ['filters' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Name', 'Phone', 'Email', 'Company', 'City', 'Service', 'Budget', 'Source', 'Campaign', 'Status', 'Temperature', 'Qualification', 'Score', 'Priority', 'Owner', 'Tags', 'Next follow-up', 'Created']);
            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $l) {
                    fputcsv($out, [$l->id, $l->name, $l->phone, $l->email, $l->company, $l->city, $l->service, $l->budget, $l->source, $l->campaign_name,
                        $l->status_label, $l->temperature_label, $l->qualification_label, $l->score, $l->priority, $l->agent?->name,
                        $l->tags->pluck('name')->implode('; '), $l->next_follow_up_at?->format('Y-m-d H:i'), $l->created_at->format('Y-m-d H:i')]);
                }
            });
            fclose($out);
        }, 'leads-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pipeline(Request $request)
    {
        $leads = Lead::visibleTo($request->user())
            ->when($request->query('scope') === 'mine', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->with('agent')->latest()->limit(1000)->get()->groupBy('status');

        return view('leads.pipeline', compact('leads'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Lead::class);

        return view('leads.form', ['lead' => new Lead(['source' => 'manual', 'priority' => 'normal']), 'agents' => User::assignable()->orderBy('name')->get()]);
    }

    public function store(Request $request, LeadService $service)
    {
        $this->authorize('create', Lead::class);
        $user = $request->user();
        $data = $this->validated($request);
        $autoAssign = empty($data['assigned_to']);
        $method = 'manual';

        if (! $user->hasPermission('leads.assign')) {
            // Agents creating a lead become its owner (otherwise they could not see it).
            $data['assigned_to'] = $user->canOwnLeads() ? $user->id : null;
            $autoAssign = ! $user->canOwnLeads();
            $method = 'creator';
        }

        $lead = $service->create($data, $user->id, $autoAssign, $method);

        if (! $lead->wasRecentlyCreated) {
            return $user->can('view', $lead)
                ? redirect()->route('leads.show', $lead)->with('error', 'A lead with this phone/email already exists — the enquiry was added to it.')
                : redirect()->route('leads.index')->with('error', 'A lead with this phone/email already exists and is owned by another agent. Ask your manager.');
        }

        return redirect()->route('leads.show', $lead)->with('success', 'Lead saved.');
    }

    public function show(Request $request, Lead $lead, LeadScoreService $scores)
    {
        $this->authorize('view', $lead);
        $lead->load([
            'agent', 'assigner', 'tags',
            'activities' => fn ($q) => $q->with('user')->latest('occurred_at')->latest('id')->limit(200),
            'followups' => fn ($q) => $q->with('step')->orderBy('scheduled_at'),
            'messages' => fn ($q) => $q->with('user')->orderBy('created_at')->orderBy('id'),
            'proposals' => fn ($q) => $q->latest(),
            'tasks' => fn ($q) => $q->with('user')->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('due_at')->limit(30),
            'meetings' => fn ($q) => $q->with('agent')->orderByDesc('starts_at')->limit(20),
            'assignments' => fn ($q) => $q->with(['user', 'assigner', 'unassigner'])->latest('assigned_at')->latest('id'),
            'analyses' => fn ($q) => $q->with(['reviewer', 'requester'])->latest()->limit(10),
        ]);

        return view('leads.show', [
            'lead' => $lead,
            'agents' => User::assignable()->orderBy('name')->get(),
            'scoreInfo' => $scores->calculate($lead),
            'analysis' => $lead->analyses->firstWhere('status', 'completed'),
            'allTags' => Tag::orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        return view('leads.form', ['lead' => $lead, 'agents' => User::assignable()->orderBy('name')->get()]);
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);
        $data = $this->validated($request, $lead);
        $data['phone'] = PhoneNumber::normalize($data['phone'] ?? null);
        unset($data['assigned_to']);

        $old = $lead->only(array_keys($data));
        $lead->update($data);
        $changed = array_keys($lead->getChanges());
        $lead->log('updated', 'Lead details updated'.($changed ? ' ('.implode(', ', array_diff($changed, ['updated_at'])).')' : ''));
        AuditLogger::log('lead.updated', $lead, array_intersect_key($old, array_flip($changed)), array_intersect_key($data, array_flip($changed)));
        app(LeadScoreService::class)->refresh($lead);

        return redirect()->route('leads.show', $lead)->with('success', 'Lead updated.');
    }

    public function destroy(Request $request, Lead $lead)
    {
        $this->authorize('delete', $lead);
        AuditLogger::log('lead.deleted', $lead, $lead->only(['name', 'phone', 'email', 'status', 'assigned_to']));
        $lead->delete();

        return redirect()->route('leads.index')->with('success', 'Lead deleted.');
    }

    public function status(Request $request, Lead $lead, LeadStatusService $statuses)
    {
        $this->authorize('update', $lead);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Lead::STATUSES))],
            'lost_reason' => 'nullable|required_if:status,lost|string|max:255',
        ], ['lost_reason.required_if' => 'Please give the reason the lead was lost.']);

        $statuses->change($lead, 'status', $data['status'], $request->user()->id, 'manual',
            $data['status'] === 'lost' ? $data['lost_reason'] : null,
            $data['status'] === 'lost' ? ['lost_reason' => $data['lost_reason']] : []);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'status' => $lead->fresh()->status]);
        }

        return back()->with('success', 'Status updated.');
    }

    /** Manual segmentation: temperature, qualification, intent, industry, timeline, tags. */
    public function segment(Request $request, Lead $lead, LeadStatusService $statuses)
    {
        $this->authorize('update', $lead);
        $data = $request->validate([
            'temperature' => ['nullable', Rule::in(array_keys(config('crm.temperatures')))],
            'qualification' => ['required', Rule::in(array_keys(config('crm.qualifications')))],
            'buying_intent' => ['nullable', Rule::in(array_keys(config('crm.buying_intents')))],
            'industry' => 'nullable|string|max:190',
            'timeline' => 'nullable|string|max:190',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'new_tags' => 'nullable|string|max:300',
        ]);
        $uid = $request->user()->id;

        DB::transaction(function () use ($lead, $data, $statuses, $uid, $request) {
            $statuses->change($lead, 'temperature', $data['temperature'] ?? null, $uid, 'manual');
            $statuses->change($lead, 'qualification', $data['qualification'], $uid, 'manual');
            // Even if unchanged, a human has confirmed these values → AI must not auto-overwrite them.
            $lead->forceFill(['temperature_source' => $lead->temperature ? 'manual' : null, 'qualification_source' => 'manual'])->saveQuietly();
            $lead->update([
                'buying_intent' => $data['buying_intent'] ?? null,
                'industry' => $data['industry'] ?? null,
                'timeline' => $data['timeline'] ?? null,
            ]);

            $ids = collect($data['tag_ids'] ?? []);
            if ($request->user()->hasPermission('tags.manage')) {   // creating new tags needs tags.manage
                foreach (array_filter(array_map('trim', explode(',', (string) ($data['new_tags'] ?? '')))) as $name) {
                    $ids->push(Tag::findOrCreateByName($name)->id);
                }
            }
            $sync = $ids->unique()->mapWithKeys(fn ($id) => [$id => ['source' => 'manual', 'added_by' => $uid]])->all();
            // keep the original pivot (source ai/manual) for tags that stay
            $current = $lead->tags()->pluck('tags.id')->all();
            $lead->tags()->detach(array_diff($current, array_keys($sync)));
            $lead->tags()->attach(array_diff_key($sync, array_flip($current)));
        });

        app(LeadScoreService::class)->refresh($lead);

        return back()->with('success', 'Segmentation saved.');
    }

    public function assign(AssignLeadRequest $request, Lead $lead, LeadAssignmentService $assignments)
    {
        $data = $request->validated();
        $by = $request->user()->id;

        if (empty($data['assigned_to'])) {
            $assignments->unassign($lead, $by, $data['reason'] ?? null);
            $msg = 'Lead unassigned.';
        } else {
            $agent = User::findOrFail($data['assigned_to']);
            $assignments->assign($lead, $agent, $by, $data['reason'] ?? null);
            $msg = "Lead assigned to {$agent->name}.";
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        // The assigning user may lose access after giving the lead away.
        return $request->user()->can('view', $lead->fresh())
            ? back()->with('success', $msg)
            : redirect()->route('leads.index')->with('success', $msg);
    }

    public function bulkAssign(BulkAssignLeadsRequest $request, LeadAssignmentService $assignments)
    {
        $data = $request->validated();
        $user = $request->user();
        $ids = array_map('intval', $data['lead_ids']);

        // Every selected lead must be visible + assignable by this user — otherwise the whole request is refused.
        $leads = Lead::visibleTo($user)->whereIn('id', $ids)->get();
        abort_if($leads->count() !== count($ids), 403, 'One or more selected leads are not accessible to you.');
        foreach ($leads as $lead) {
            $this->authorize('assign', $lead);
        }

        $reason = $data['reason'] ?? null;
        switch ($data['action']) {
            case 'assign':
                $agent = User::findOrFail($data['assigned_to']);
                $count = $assignments->bulkAssign($leads, $agent, $user->id, $reason);
                $msg = "{$count} lead(s) assigned to {$agent->name}.";
                break;
            case 'unassign':
                $count = 0;
                foreach ($leads as $lead) {
                    if ($lead->assigned_to) {
                        $assignments->unassign($lead, $user->id, $reason ?: 'Bulk unassign');
                        $count++;
                    }
                }
                $msg = "{$count} lead(s) unassigned.";
                break;
            default: // round_robin | least_leads — distribute selected leads that have no owner
                $count = 0;
                foreach ($leads->whereNull('assigned_to') as $lead) {
                    if ($assignments->autoAssign($lead, $data['action'], $user->id)) {
                        $count++;
                    }
                }
                $msg = "{$count} unassigned lead(s) distributed (".LeadAssignment::METHODS[$data['action']].'). Leads that already had an owner were skipped.';
        }

        AuditLogger::log('leads.bulk_'.$data['action'], null, [], ['lead_ids' => $ids, 'assigned_to' => $data['assigned_to'] ?? null, 'changed' => $count], $reason);

        return back()->with('success', $msg);
    }

    public function distributeUnassigned(Request $request, LeadAssignmentService $assignments)
    {
        abort_unless($request->user()->hasPermission('leads.assign'), 403);
        $count = 0;
        foreach (Lead::whereNull('assigned_to')->open()->oldest()->limit(500)->get() as $lead) {
            if ($assignments->autoAssign($lead, null, $request->user()->id)) {
                $count++;
            }
        }

        return back()->with('success', $count ? "{$count} unassigned lead(s) distributed." : 'No leads distributed (distribution is set to Manual, or no agent receives leads).');
    }

    /** Backward-compatible "add note" endpoint → logged as a manual Note activity. */
    public function note(Request $request, Lead $lead, LeadActivityService $activities)
    {
        $this->authorize('logActivity', $lead);
        $data = $request->validate([
            'note' => 'required|string|max:5000',
            'next_follow_up_at' => 'nullable|date|after:now',
        ]);

        $activities->log($lead, $request->user(), [
            'type' => 'note', 'notes' => $data['note'], 'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
        ]);

        return back()->with('success', 'Note added.');
    }

    public function whatsapp(Request $request, Lead $lead, WhatsAppService $whatsapp, LeadStatusService $statuses)
    {
        $this->authorize('logActivity', $lead);
        $data = $request->validate([
            'type' => 'required|in:text,template',
            'message' => 'nullable|required_if:type,text|string|max:4000',
            'template_name' => 'nullable|required_if:type,template|string|max:255',
            'template_language' => 'nullable|string|max:10',
            'variables' => 'nullable|string|max:1000',
        ]);

        if ($data['type'] === 'template') {
            $vars = array_values(array_filter(array_map('trim', explode('|', WhatsAppService::fill($data['variables'] ?? '', $lead))), fn ($v) => $v !== ''));
            $msg = $whatsapp->sendTemplate($lead, $data['template_name'], $data['template_language'] ?: 'en', $vars, $request->user()->id);
        } else {
            $msg = $whatsapp->sendText($lead, WhatsAppService::fill($data['message'], $lead), $request->user()->id);
        }

        if ($msg->status === 'failed') {
            return back()->with('error', 'WhatsApp message failed: '.$msg->error);
        }
        $statuses->markContacted($lead->fresh(), $request->user()->id);

        return back()->with('success', 'WhatsApp message sent.');
    }

    public function toggleFollowups(Request $request, Lead $lead, FollowupService $followups)
    {
        $this->authorize('update', $lead);

        if ($request->input('action') === 'restart') {
            $lead->update(['followups_paused' => false, 'last_reply_at' => null]);
            $lead->followups()->delete();
            $followups->scheduleFor($lead->fresh('agent'), now());
            $lead->log('followup', 'Follow-up sequence restarted');

            return back()->with('success', 'Follow-ups restarted.');
        }

        $lead->update(['followups_paused' => ! $lead->followups_paused]);
        $lead->log('followup', $lead->followups_paused ? 'Auto follow-ups paused (human handling the chat)' : 'Auto follow-ups resumed');

        return back()->with('success', $lead->followups_paused ? 'Follow-ups paused.' : 'Follow-ups resumed.');
    }

    protected function validated(Request $request, ?Lead $lead = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'company' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'service' => 'nullable|string|max:255',
            'budget' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:190',
            'timeline' => 'nullable|string|max:190',
            'source' => ['required', Rule::in(array_keys(Lead::SOURCES))],
            'campaign_name' => 'nullable|string|max:255',
            'priority' => ['required', Rule::in(array_keys(Lead::PRIORITIES))],
            'notes' => 'nullable|string|max:5000',
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
    }
}
