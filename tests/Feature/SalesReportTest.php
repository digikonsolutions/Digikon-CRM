<?php

namespace Tests\Feature;

use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Services\LeadAssignmentService;
use App\Services\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function call_(int $leadId, int $userId, string $at, string $outcome = 'answered'): void
    {
        LeadActivity::create(['lead_id' => $leadId, 'user_id' => $userId, 'type' => 'call', 'outcome' => $outcome, 'description' => 'c',
            'occurred_at' => Carbon::parse($at, 'Asia/Karachi'), 'is_manual' => true]);
    }

    public function test_daily_counts_use_pakistan_time(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);
        // 23:30 PKT on the 19th = 18:30 UTC → must count for the 19th, not the 20th
        $this->call_($lead->id, $agent->id, '2026-10-19 23:30:00');
        // 00:30 PKT on the 20th = 19:30 UTC on the 19th → must count for the 20th
        $this->call_($lead->id, $agent->id, '2026-10-20 00:30:00');

        $r = app(SalesReportService::class);
        $this->assertSame(1, $r->metrics(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'), [$agent->id])['calls_total']);
        $this->assertSame(1, $r->metrics(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'), [$agent->id])['calls_total']);
    }

    public function test_calls_and_unique_contacted_leads_are_counted_separately(): void
    {
        $agent = $this->user('agent');
        $l1 = $this->lead(['assigned_to' => $agent->id]);
        $l2 = $this->lead(['assigned_to' => $agent->id]);
        foreach (range(1, 4) as $i) {
            $this->call_($l1->id, $agent->id, "2026-10-20 1{$i}:00:00", $i === 1 ? 'no_answer' : 'answered');
        }
        $this->call_($l2->id, $agent->id, '2026-10-20 16:00:00', 'busy');

        $m = app(SalesReportService::class)->metrics(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'), [$agent->id]);
        $this->assertSame(5, $m['calls_total']);
        $this->assertSame(2, $m['unique_leads_contacted']);
        $this->assertSame(3, $m['calls_connected']);
        $this->assertSame(1, $m['calls_no_answer']);
        $this->assertSame(1, $m['calls_busy']);
    }

    public function test_booked_meeting_is_not_counted_as_completed_and_assignment_is_not_conversion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Karachi'));
        $manager = $this->user('manager');
        $agent = $this->user('agent');
        $lead = $this->lead();
        app(LeadAssignmentService::class)->assign($lead, $agent, $manager->id);
        Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $agent->id, 'assigned_agent_id' => $agent->id, 'title' => 'Demo',
            'meeting_type' => 'online', 'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(3), 'status' => 'scheduled']);

        $m = app(SalesReportService::class)->metrics(today(), today(), [$agent->id]);
        $this->assertSame(1, $m['meetings_booked']);
        $this->assertSame(0, $m['meetings_completed']);
        $this->assertSame(1, $m['leads_assigned']);
        $this->assertSame(0, $m['won']);
        $this->assertSame(0.0, $m['assigned_conversion']);
        $this->assertSame([], $m['revenue'], 'no revenue is invented');
    }

    public function test_agent_report_never_includes_other_agents(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 18:00', 'Asia/Karachi'));
        $a = $this->user('agent');
        $b = $this->user('agent');
        $la = $this->lead(['assigned_to' => $a->id]);
        $lb = $this->lead(['assigned_to' => $b->id]);
        $this->call_($la->id, $a->id, '2026-10-20 10:00:00');
        foreach (range(1, 3) as $i) {
            $this->call_($lb->id, $b->id, "2026-10-20 1{$i}:00:00");
        }

        // agent A tries to look at agent B with ?agent=
        $res = $this->actingAs($a)->get(route('reports.index', ['period' => 'daily', 'date' => '2026-10-20', 'agent' => $b->id]))->assertOk();
        $this->assertSame(1, $res->viewData('metrics')['calls_total']);
        $this->assertSame([], $res->viewData('agentRows'));

        $csv = $this->actingAs($a)->get(route('reports.export', ['period' => 'daily', 'date' => '2026-10-20', 'agent' => $b->id]))->streamedContent();
        $this->assertStringContainsString($a->name, $csv);
        $this->assertStringNotContainsString($b->name, $csv);

        // a manager sees the team
        $team = $this->actingAs($this->user('manager'))->get(route('reports.index', ['period' => 'daily', 'date' => '2026-10-20']))->assertOk();
        $this->assertSame(4, $team->viewData('metrics')['calls_total']);
    }

    public function test_snapshots_are_idempotent(): void
    {
        $this->user('agent');
        $automation = app(\App\Services\CrmAutomation::class);
        $automation->snapshotReports('2026-10-19');
        $count = \App\Models\ReportSnapshot::count();
        $automation->snapshotReports('2026-10-19');
        $this->assertSame($count, \App\Models\ReportSnapshot::count());
        $this->assertGreaterThan(0, $count);
    }
}
