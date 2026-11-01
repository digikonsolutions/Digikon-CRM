<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use App\Services\FollowUpTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Manual follow-ups ("call back on Tuesday"). */
class LeadTaskController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $team = $user->hasPermission('activities.view-team');
        $tab = $request->query('tab', 'due');

        $base = LeadTask::with(['lead:id,name,phone,status,assigned_to,temperature', 'user:id,name'])
            ->whereHas('lead', fn ($q) => $q->visibleTo($user))
            ->when(! $team, fn ($q) => $q->where('user_id', $user->id))
            ->when($team && $request->agent, fn ($q, $v) => $q->where('user_id', $v));

        $counts = [
            'overdue' => (clone $base)->open()->where('due_at', '<', now())->count(),
            'today' => (clone $base)->open()->whereBetween('due_at', [now(), now()->endOfDay()])->count(),
            'upcoming' => (clone $base)->open()->where('due_at', '>', now()->endOfDay())->count(),
        ];

        $tasks = match ($tab) {
            'upcoming' => (clone $base)->open()->where('due_at', '>', now()->endOfDay())->orderBy('due_at'),
            'done' => (clone $base)->where('status', 'done')->latest('completed_at'),
            default => (clone $base)->open()->where('due_at', '<=', now()->endOfDay())->orderBy('due_at'),
        };

        return view('tasks.index', [
            'tasks' => $tasks->paginate(40)->withQueryString(),
            'tab' => $tab,
            'counts' => $counts,
            'team' => $team,
            'agents' => $team ? User::assignable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function store(Request $request, Lead $lead, FollowUpTaskService $tasks)
    {
        $this->authorize('logActivity', $lead);
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'due_at' => 'required|date|after:now',
            'notes' => 'nullable|string|max:2000',
        ], ['due_at.after' => 'The follow-up date must be in the future.']);

        $tasks->schedule($lead, Carbon::parse($data['due_at']), $request->user(), $data['title'] ?? null, $data['notes'] ?? null);

        return back()->with('success', 'Follow-up scheduled.');
    }

    public function update(Request $request, LeadTask $task, FollowUpTaskService $tasks)
    {
        $user = $request->user();
        $this->authorize('logActivity', $task->lead);
        abort_unless((int) $task->user_id === (int) $user->id || $user->hasPermission('activities.view-team'), 403);

        $data = $request->validate(['action' => 'required|in:complete,cancel', 'note' => 'nullable|string|max:500']);
        $data['action'] === 'complete'
            ? $tasks->complete($task, $user, $data['note'] ?? null)
            : $tasks->cancel($task, $user, $data['note'] ?? null);

        return back()->with('success', $data['action'] === 'complete' ? 'Follow-up marked done.' : 'Follow-up cancelled.');
    }
}
