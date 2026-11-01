<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    protected function book($user, $lead, string $start, string $end, array $extra = [])
    {
        return $this->actingAs($user)->post(route('leads.meetings.store', $lead), array_merge([
            'title' => 'Discovery call', 'meeting_type' => 'online', 'starts_at' => $start, 'ends_at' => $end,
        ], $extra));
    }

    public function test_agent_cannot_view_another_agents_meeting(): void
    {
        $a = $this->user('agent');
        $b = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $b->id]);
        $m = Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $b->id, 'assigned_agent_id' => $b->id, 'title' => 'Private', 'meeting_type' => 'phone',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled']);

        $this->actingAs($a)->get(route('meetings.show', $m))->assertForbidden();
        $this->actingAs($a)->get(route('meetings.ics', $m))->assertForbidden();
        $this->actingAs($a)->post(route('meetings.update', $m), ['action' => 'cancel', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($a)->get(route('meetings.index', ['view' => 'agenda']))->assertOk()->assertDontSee('Private');
        $this->actingAs($b)->get(route('meetings.show', $m))->assertOk();
    }

    public function test_invalid_time_range_is_rejected(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);
        $start = now()->addDay()->setTime(15, 0);

        $this->book($agent, $lead, $start->format('Y-m-d\TH:i'), $start->copy()->subHour()->format('Y-m-d\TH:i'))->assertSessionHasErrors('ends_at');
        $this->assertSame(0, Meeting::count());
    }

    public function test_conflicting_meetings_are_rejected_unless_manager_overrides(): void
    {
        $agent = $this->user('agent');
        $manager = $this->user('manager');
        $l1 = $this->lead(['assigned_to' => $agent->id]);
        $l2 = $this->lead(['assigned_to' => $agent->id]);
        $s = now()->addDays(2)->setTime(11, 0);

        $this->book($agent, $l1, $s->format('Y-m-d\TH:i'), $s->copy()->addHour()->format('Y-m-d\TH:i'))->assertSessionHasNoErrors();
        // overlaps 11:30–12:30
        $this->book($agent, $l2, $s->copy()->addMinutes(30)->format('Y-m-d\TH:i'), $s->copy()->addMinutes(90)->format('Y-m-d\TH:i'))->assertSessionHasErrors('starts_at');
        // agents cannot override
        $this->book($agent, $l2, $s->copy()->addMinutes(30)->format('Y-m-d\TH:i'), $s->copy()->addMinutes(90)->format('Y-m-d\TH:i'), ['allow_conflict' => 1])->assertSessionHasErrors('starts_at');
        // back-to-back is fine
        $this->book($agent, $l2, $s->copy()->addHour()->format('Y-m-d\TH:i'), $s->copy()->addHours(2)->format('Y-m-d\TH:i'))->assertSessionHasNoErrors();
        // manager can double-book explicitly
        $this->book($manager, $l2, $s->copy()->addMinutes(15)->format('Y-m-d\TH:i'), $s->copy()->addMinutes(45)->format('Y-m-d\TH:i'), ['allow_conflict' => 1, 'assigned_agent_id' => $agent->id])->assertSessionHasNoErrors();

        $this->assertSame(3, Meeting::count());
    }

    public function test_outcome_updates_reports_and_reschedule_is_tracked(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Karachi'));
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);
        $this->book($agent, $lead, '2026-10-20T10:00', '2026-10-20T10:30')->assertSessionHasNoErrors();
        $meeting = Meeting::firstOrFail();

        $this->actingAs($agent)->post(route('meetings.update', $meeting), ['action' => 'reschedule', 'starts_at' => '2026-10-20T12:00', 'ends_at' => '2026-10-20T12:45', 'reason' => 'Client busy'])
            ->assertSessionHasNoErrors();
        $meeting->refresh();
        $this->assertSame('rescheduled', $meeting->status);
        $this->assertSame(1, $meeting->reschedule_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'meeting.rescheduled', 'auditable_id' => $meeting->id]);

        // cannot complete a meeting that has not started
        $this->actingAs($agent)->post(route('meetings.update', $meeting), ['action' => 'outcome', 'status' => 'completed'])->assertSessionHasErrors('status');

        $this->travelTo(Carbon::parse('2026-10-20 13:00', 'Asia/Karachi'));
        $report = app(SalesReportService::class);
        $this->assertSame(0, $report->metrics(today(), today(), [$agent->id])['meetings_completed']);

        $this->actingAs($agent)->post(route('meetings.update', $meeting), ['action' => 'outcome', 'status' => 'completed', 'outcome' => 'Wants proposal'])->assertSessionHasNoErrors();
        $m = $report->metrics(today(), today(), [$agent->id]);
        $this->assertSame(1, $m['meetings_completed']);
        $this->assertSame(1, $m['meetings_booked']);
        $this->assertSame(1, $m['unique_leads_contacted']);
    }

    public function test_reminders_are_sent_once(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);
        Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $agent->id, 'assigned_agent_id' => $agent->id, 'title' => 'Soon', 'meeting_type' => 'phone',
            'starts_at' => now()->addMinutes(20), 'ends_at' => now()->addMinutes(50), 'status' => 'scheduled']);

        $auto = app(\App\Services\CrmAutomation::class);
        $this->assertSame(1, $auto->meetingReminders());
        $this->assertSame(0, $auto->meetingReminders());
        \Illuminate\Support\Facades\Notification::assertSentToTimes($agent, \App\Notifications\CrmAlert::class, 1);
    }
}
