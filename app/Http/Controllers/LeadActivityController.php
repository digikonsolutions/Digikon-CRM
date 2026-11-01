<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadActivityRequest;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\LeadActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeadActivityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission('activities.view-own', 'activities.view-team'), 403);
        $team = $user->hasPermission('activities.view-team');

        $activities = LeadActivity::with(['lead:id,name,phone,assigned_to', 'user:id,name'])
            ->whereIn('type', array_keys(config('crm.activity_types')))
            ->whereNotNull('user_id')
            ->when(! $team, fn ($q) => $q->where('user_id', $user->id))
            ->when($team && $request->agent, fn ($q, $v) => $q->where('user_id', $request->agent))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->outcome, fn ($q, $v) => $q->where('outcome', $v))
            ->when($request->date_from, fn ($q, $v) => $q->where('occurred_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->date_to, fn ($q, $v) => $q->where('occurred_at', '<=', Carbon::parse($v)->endOfDay()))
            ->latest('occurred_at')->latest('id')
            ->paginate(40)->withQueryString();

        return view('activities.index', [
            'activities' => $activities,
            'team' => $team,
            'agents' => $team ? User::assignable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function store(StoreLeadActivityRequest $request, Lead $lead, LeadActivityService $service)
    {
        $activity = $service->log($lead, $request->user(), $request->activityData());

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'activity_id' => $activity->id], 201);
        }

        return back()->with('success', $activity->type_label.' logged.'.($activity->next_follow_up_at ? ' Follow-up scheduled for '.$activity->next_follow_up_at->format('d M, h:i A').'.' : ''));
    }
}
