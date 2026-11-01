<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\LeadScoreService;
use Illuminate\Http\Request;

/** WhatsApp conversations per lead + AI segregation. */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('conversations.view'), 403);

        $leads = Lead::visibleTo($user)
            ->whereHas('messages')
            ->with(['agent:id,name', 'latestMessage', 'latestAnalysis', 'tags'])
            ->withCount(['messages', 'messages as inbound_count' => fn ($q) => $q->where('direction', 'in')])
            ->withMax('messages', 'id')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->when($request->temperature, fn ($q, $v) => $v === 'none' ? $q->whereNull('temperature') : $q->where('temperature', $v))
            ->when($request->agent && $user->hasPermission('leads.view-all'), fn ($q) => $q->where('assigned_to', $request->agent))
            ->when($request->filter === 'awaiting', fn ($q) => $q->whereHas('latestMessage', fn ($m) => $m->where('direction', 'in')))
            ->when($request->filter === 'not_analysed', fn ($q) => $q->whereDoesntHave('analyses', fn ($a) => $a->where('status', 'completed')))
            ->when($request->filter === 'review', fn ($q) => $q->whereHas('latestAnalysis', fn ($a) => $a->whereIn('review_status', ['pending', 'auto_applied'])))
            ->orderByDesc('messages_max_id')
            ->paginate(30)->withQueryString();

        return view('conversations.index', [
            'leads' => $leads,
            'agents' => $user->hasPermission('leads.view-all') ? User::assignable()->orderBy('name')->get() : collect(),
            'aiConfigured' => AiClientFactory::isConfigured(),
        ]);
    }

    public function show(Request $request, Lead $lead, LeadScoreService $scores)
    {
        $this->authorize('viewConversation', $lead);
        $lead->load([
            'agent', 'tags',
            'messages' => fn ($q) => $q->with('user:id,name')->orderBy('created_at')->orderBy('id'),
            'analyses' => fn ($q) => $q->with(['reviewer:id,name', 'requester:id,name'])->latest()->limit(15),
        ]);

        return view('conversations.show', [
            'lead' => $lead,
            'analysis' => $lead->analyses->firstWhere('status', 'completed'),
            'aiConfigured' => AiClientFactory::isConfigured(),
            'scoreInfo' => $scores->calculate($lead),
        ]);
    }
}
