<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MeetingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission('meetings.view-own', 'meetings.view-team'), 403);

        $view = in_array($request->query('view'), ['month', 'week', 'day', 'agenda'], true) ? $request->query('view') : 'month';
        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();

        [$from, $to] = match ($view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'agenda' => [$date->copy()->startOfDay(), $date->copy()->addDays(30)->endOfDay()],
            default => [$date->copy()->startOfMonth()->startOfWeek(), $date->copy()->endOfMonth()->endOfWeek()],
        };

        $team = $user->hasPermission('meetings.view-team');
        $meetings = Meeting::visibleTo($user)
            ->with(['lead:id,name,phone', 'agent:id,name'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($team && $request->agent, fn ($q, $v) => $q->where('assigned_agent_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->where('meeting_type', $v))
            ->orderBy('starts_at')
            ->get();

        return view('meetings.index', [
            'view' => $view,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'meetings' => $meetings,
            'byDay' => $meetings->groupBy(fn ($m) => $m->starts_at->toDateString()),
            'team' => $team,
            'agents' => $team ? User::assignable()->orderBy('name')->get() : collect(),
            'needsOutcome' => Meeting::visibleTo($user)->with('lead:id,name')->active()->where('ends_at', '<', now())->orderByDesc('starts_at')->limit(20)->get(),
        ]);
    }

    public function show(Request $request, Meeting $meeting)
    {
        $this->authorize('view', $meeting);
        $meeting->load(['lead', 'agent', 'organizer']);
        $history = \App\Models\AuditLog::with('user')->where('auditable_type', 'Meeting')->where('auditable_id', $meeting->id)->latest('created_at')->get();

        return view('meetings.show', compact('meeting', 'history'));
    }

    public function store(StoreMeetingRequest $request, Lead $lead, MeetingService $service)
    {
        $meeting = $service->create($lead, $request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'meeting_id' => $meeting->id], 201);
        }

        return back()->with('success', 'Meeting booked for '.$meeting->starts_at->format('D d M, h:i A').'.');
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting, MeetingService $service)
    {
        $data = $request->validated();
        $user = $request->user();

        match ($data['action']) {
            'reschedule' => $service->reschedule($meeting, $user, $data),
            'cancel' => $service->cancel($meeting, $user, $data['reason'] ?? null),
            'outcome' => $service->recordOutcome($meeting, $user, $data['status'], $data['outcome'] ?? null, $data['notes'] ?? null),
        };

        $msg = ['reschedule' => 'Meeting rescheduled.', 'cancel' => 'Meeting cancelled.', 'outcome' => 'Meeting outcome saved.'][$data['action']];

        return $request->wantsJson() ? response()->json(['ok' => true, 'message' => $msg]) : back()->with('success', $msg);
    }

    /** (Re)send the WhatsApp + email confirmation to the customer. */
    public function confirm(Request $request, Meeting $meeting, \App\Services\SalesAutomation $automation)
    {
        $this->authorize('update', $meeting);
        $meeting->update(['confirmation_sent_at' => null]);
        $r = $automation->sendMeetingConfirmation($meeting->fresh(), $request->user()->id);

        return back()->with('success', 'Confirmation — WhatsApp: '.($r['whatsapp'] ?? '-').' · Email: '.($r['email'] ?? '-'));
    }

    public function ics(Request $request, Meeting $meeting, MeetingService $service)
    {
        $this->authorize('view', $meeting);

        return response($service->ics($meeting->load('lead')), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="meeting-'.$meeting->id.'.ics"',
        ]);
    }
}
