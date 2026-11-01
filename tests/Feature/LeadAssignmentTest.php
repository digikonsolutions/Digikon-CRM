<?php

namespace Tests\Feature;

use App\Models\LeadAssignment;
use App\Notifications\CrmAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_assign_a_lead_and_agent_is_notified(): void
    {
        Notification::fake();
        $manager = $this->user('manager');
        $agent = $this->user('agent');
        $lead = $this->lead();

        $this->actingAs($manager)->post(route('leads.assign', $lead), ['assigned_to' => $agent->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($agent->id, $lead->assigned_to);
        $this->assertSame($manager->id, $lead->assigned_by);
        $this->assertNotNull($lead->assigned_at);
        $this->assertDatabaseHas('lead_assignments', ['lead_id' => $lead->id, 'user_id' => $agent->id, 'assigned_by' => $manager->id, 'unassigned_at' => null]);
        Notification::assertSentTo($agent, CrmAlert::class, fn ($n) => $n->type === 'lead_assigned');
    }

    public function test_agent_cannot_assign_leads(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.assign', $lead), ['assigned_to' => $other->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($agent)->post(route('leads.bulk-assign'), ['lead_ids' => [$lead->id], 'action' => 'assign', 'assigned_to' => $other->id])->assertForbidden();
        $this->assertSame($agent->id, $lead->fresh()->assigned_to);
    }

    public function test_reassignment_preserves_history_and_requires_a_reason(): void
    {
        $manager = $this->user('manager');
        [$a, $b] = [$this->user('agent'), $this->user('agent')];
        $lead = $this->lead();

        $this->actingAs($manager)->post(route('leads.assign', $lead), ['assigned_to' => $a->id]);
        $this->actingAs($manager)->post(route('leads.assign', $lead->fresh()), ['assigned_to' => $b->id])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post(route('leads.assign', $lead->fresh()), ['assigned_to' => $b->id, 'reason' => 'Ali on leave']);

        $rows = LeadAssignment::where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($a->id, $rows[0]->user_id);
        $this->assertNotNull($rows[0]->unassigned_at);
        $this->assertSame($b->id, $rows[1]->user_id);
        $this->assertNull($rows[1]->unassigned_at);
        $this->assertSame('Ali on leave', $rows[1]->reason);
        $this->assertSame(1, LeadAssignment::where('lead_id', $lead->id)->whereNull('unassigned_at')->count(), 'only one active owner');
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.reassigned', 'auditable_id' => $lead->id]);
    }

    public function test_inactive_or_non_sales_users_are_rejected(): void
    {
        $manager = $this->user('manager');
        $inactive = $this->user('agent', ['is_active' => false]);
        $marketing = $this->user('marketing');
        $lead = $this->lead();

        $this->actingAs($manager)->post(route('leads.assign', $lead), ['assigned_to' => $inactive->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($manager)->post(route('leads.assign', $lead), ['assigned_to' => $marketing->id])->assertSessionHasErrors('assigned_to');
        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertSame(0, LeadAssignment::count());
    }

    public function test_bulk_assignment_validates_every_lead(): void
    {
        $manager = $this->user('manager');
        $agent = $this->user('agent');
        $l1 = $this->lead();
        $l2 = $this->lead();

        // one id does not exist → whole request refused, nothing changes
        $this->actingAs($manager)->post(route('leads.bulk-assign'), ['lead_ids' => [$l1->id, 999999], 'action' => 'assign', 'assigned_to' => $agent->id])->assertForbidden();
        $this->assertNull($l1->fresh()->assigned_to);

        $this->actingAs($manager)->post(route('leads.bulk-assign'), ['lead_ids' => [$l1->id, $l2->id], 'action' => 'assign', 'assigned_to' => $agent->id, 'reason' => 'New campaign'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($agent->id, $l1->fresh()->assigned_to);
        $this->assertSame($agent->id, $l2->fresh()->assigned_to);
        $this->assertSame(2, LeadAssignment::where('method', 'bulk')->count());
    }

    public function test_round_robin_distribution_skips_ineligible_users(): void
    {
        $manager = $this->user('manager', ['receives_leads' => false]);
        $a = $this->user('agent');
        $b = $this->user('agent');
        $this->user('agent', ['is_active' => false]);
        $this->user('marketing');
        $leads = [$this->lead(), $this->lead(), $this->lead()];

        $this->actingAs($manager)->post(route('leads.bulk-assign'), ['lead_ids' => array_map(fn ($l) => $l->id, $leads), 'action' => 'round_robin']);

        $owners = collect($leads)->map(fn ($l) => $l->fresh()->assigned_to)->all();
        $this->assertSame([$a->id, $b->id, $a->id], $owners);
    }

    public function test_agent_cannot_see_or_open_other_agents_leads(): void
    {
        $a = $this->user('agent');
        $b = $this->user('agent');
        $mine = $this->lead(['assigned_to' => $a->id, 'name' => 'Mine Lead']);
        $theirs = $this->lead(['assigned_to' => $b->id, 'name' => 'Their Lead']);

        $this->actingAs($a)->get(route('leads.show', $theirs))->assertForbidden();
        $this->actingAs($a)->get(route('leads.index'))->assertOk()->assertSee('Mine Lead')->assertDontSee('Their Lead');
        $this->actingAs($a)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($a)->get(route('users.index'))->assertForbidden();
    }
}
