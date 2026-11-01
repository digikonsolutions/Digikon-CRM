<?php

namespace Tests\Feature;

use App\Models\LeadActivity;
use App\Models\LeadTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_log_a_call_on_own_lead(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), [
            'type' => 'call', 'outcome' => 'interested', 'notes' => 'Wants a website', 'duration_minutes' => 4, 'duration_seconds_part' => 30,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a = LeadActivity::where('type', 'call')->firstOrFail();
        $this->assertSame($agent->id, $a->user_id);
        $this->assertSame('interested', $a->outcome);
        $this->assertSame(270, $a->duration_seconds);
        $this->assertTrue($a->is_manual);
        $this->assertFalse($a->is_verified, 'manual logs are never "verified"');
        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->first_contacted_at);
    }

    public function test_agent_cannot_log_on_another_agents_lead(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $this->user('agent')->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'call', 'outcome' => 'answered'])->assertForbidden();
        $this->assertSame(0, LeadActivity::where('type', 'call')->count());
    }

    public function test_agent_cannot_impersonate_another_user(): void
    {
        $agent = $this->user('agent');
        $victim = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'call', 'outcome' => 'answered', 'user_id' => $victim->id]);

        $this->assertSame($agent->id, LeadActivity::where('type', 'call')->value('user_id'));
    }

    public function test_invalid_activity_types_and_outcomes_are_rejected(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'hack'])->assertSessionHasErrors('type');
        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'call', 'outcome' => 'teleported'])->assertSessionHasErrors('outcome');
        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'call'])->assertSessionHasErrors('outcome');
        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'status_change', 'notes' => 'x'])->assertSessionHasErrors('type');
        $this->assertSame(0, LeadActivity::where('is_manual', true)->count());
    }

    public function test_follow_up_timestamp_creates_task_and_updates_lead(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), [
            'type' => 'call', 'outcome' => 'callback_requested', 'next_follow_up_at' => '2026-10-22T15:30',
        ])->assertSessionHasNoErrors();

        $task = LeadTask::firstOrFail();
        $this->assertSame('2026-10-22 15:30:00', $task->due_at->format('Y-m-d H:i:s'));
        $this->assertSame($agent->id, $task->user_id);
        $this->assertSame('2026-10-22 15:30:00', $lead->fresh()->next_follow_up_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 15:30:00', LeadActivity::where('type', 'call')->first()->next_follow_up_at->format('Y-m-d H:i:s'));

        // completing it clears the lead's next follow-up
        $this->actingAs($agent)->post(route('tasks.update', $task), ['action' => 'complete']);
        $this->assertSame('done', $task->fresh()->status);
        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    public function test_follow_up_in_the_past_is_rejected(): void
    {
        $agent = $this->user('agent');
        $lead = $this->lead(['assigned_to' => $agent->id]);

        $this->actingAs($agent)->post(route('leads.activities.store', $lead), ['type' => 'note', 'notes' => 'x', 'next_follow_up_at' => now()->subDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('next_follow_up_at');
    }
}
