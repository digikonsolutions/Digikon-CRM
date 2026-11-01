<?php

namespace Tests\Feature;

use App\Mail\CrmMessageMail;
use App\Mail\ProposalMail;
use App\Models\FollowupStep;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\LocalRuleClient;
use App\Services\FollowupService;
use App\Services\LeadQualificationService;
use App\Services\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The automatic flow: landing → hot → follow-ups → chat → meeting → proposal → invoice. */
class SalesAutomationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'Asia/Karachi'));
        $this->seed(\Database\Seeders\DatabaseSeeder::class); // packages, settings, follow-up steps (WhatsApp + email)
        $this->agent = User::where('email', 'ali@crm.test')->first();
        Mail::fake();
        app()->instance(AiClient::class, new LocalRuleClient());
    }

    protected function landingLead(array $extra = []): Lead
    {
        $this->postJson('/api/v1/public/leads', array_merge([
            'name' => 'Usman', 'phone' => '03001112233', 'email' => 'usman@example.com', 'interested_service' => 'Online store',
        ], $extra))->assertCreated();

        return Lead::latest('id')->first();
    }

    protected function chat(Lead $lead, array $messages): void
    {
        foreach ($messages as $i => $text) {
            $this->postJson('/webhooks/whatsapp', ['entry' => [['changes' => [['value' => [
                'messages' => [['from' => $lead->phone, 'id' => 'wamid.'.$lead->id.'.'.$i.uniqid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]]])->assertOk();
        }
    }

    public function test_landing_page_leads_are_hot_and_get_whatsapp_and_email_followups(): void
    {
        $lead = $this->landingLead();

        $this->assertSame('hot', $lead->temperature);
        $this->assertSame('system', $lead->temperature_source);
        $this->assertNotNull($lead->assigned_to);
        $channels = $lead->followups()->with('step')->get()->pluck('step.channel')->unique()->sort()->values()->all();
        $this->assertSame(['email', 'whatsapp'], $channels);

        app(FollowupService::class)->sendDue();
        Mail::assertSent(CrmMessageMail::class, fn ($m) => $m->hasTo('usman@example.com') && str_contains($m->subjectLine, 'Thank you'));
        $this->assertSame('sent', $lead->followups()->whereHas('step', fn ($q) => $q->where('channel', 'email')->where('delay_minutes', 0))->value('status'));
    }

    public function test_landing_hot_rule_can_be_switched_off_and_leads_without_email_get_no_email_steps(): void
    {
        Setting::set('auto_landing_hot', '0');
        $lead = $this->landingLead(['email' => null]);

        $this->assertNull($lead->temperature);
        $this->assertSame(0, $lead->followups()->whereHas('step', fn ($q) => $q->where('channel', 'email'))->count());
    }

    public function test_chat_books_meeting_and_sends_confirmation(): void
    {
        $lead = $this->landingLead();
        $this->chat($lead, ['Mujhe clothing brand ke liye online store chahiye, budget 150k hai', 'Kal 4 baje call kar lein please']);

        $analysis = app(LeadQualificationService::class)->analyze($lead->fresh());

        $this->assertSame('2026-10-21 16:00', $analysis->meeting_at->format('Y-m-d H:i'));
        $meeting = Meeting::firstOrFail();
        $this->assertSame($analysis->meeting_id, $meeting->id);
        $this->assertSame('ai', $meeting->source);
        $this->assertSame($lead->assigned_to, $meeting->assigned_agent_id);
        $this->assertSame('2026-10-21 16:00', $meeting->starts_at->format('Y-m-d H:i'));
        $this->assertNotNull($meeting->confirmation_sent_at);
        Mail::assertSent(CrmMessageMail::class, fn ($m) => str_contains($m->subjectLine, 'Meeting confirmed') && str_contains($m->messageBody, '04:00 PM'));

        // re-analysing does not double-book or re-send
        app(LeadQualificationService::class)->analyze($lead->fresh());
        $this->assertSame(1, Meeting::count());
        Mail::assertSent(CrmMessageMail::class, fn ($m) => str_contains($m->subjectLine, 'Meeting confirmed'), 1);
    }

    public function test_conflicting_chat_time_is_not_booked(): void
    {
        $lead = $this->landingLead();
        Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $this->agent->id, 'assigned_agent_id' => $lead->assigned_to, 'title' => 'Other', 'meeting_type' => 'phone',
            'starts_at' => Carbon::parse('2026-10-21 15:45'), 'ends_at' => Carbon::parse('2026-10-21 16:30'), 'status' => 'scheduled']);
        $other = $this->lead(['assigned_to' => $lead->assigned_to, 'phone' => '923009990000']);
        $this->chat($other, ['Kal 4 baje call kar lein']);

        app(LeadQualificationService::class)->analyze($other->fresh());

        $this->assertSame(0, $other->meetings()->count());
        $this->assertTrue($other->activities()->where('description', 'like', '%could not be booked%')->exists());
    }

    public function test_meeting_success_sends_proposal_then_acceptance_sends_invoice(): void
    {
        $lead = $this->landingLead();
        $meeting = Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $lead->assigned_to, 'assigned_agent_id' => $lead->assigned_to, 'title' => 'Call',
            'meeting_type' => 'phone', 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30), 'status' => 'scheduled']);

        $this->actingAs($lead->agent)->post(route('meetings.update', $meeting), ['action' => 'outcome', 'status' => 'completed', 'outcome' => 'Wants 200 products'])->assertSessionHasNoErrors();

        $proposal = Proposal::firstOrFail();
        $this->assertSame('auto', $proposal->source);
        $this->assertSame('sent', $proposal->status);
        $this->assertSame($meeting->id, $proposal->meeting_id);
        $this->assertSame('E-commerce Store', $proposal->title === 'E-commerce Store Proposal' ? 'E-commerce Store' : $proposal->title);
        $this->assertGreaterThan(0, $proposal->total);
        $this->assertSame('proposal', $lead->fresh()->status);
        Mail::assertSent(ProposalMail::class, fn ($m) => $m->hasTo('usman@example.com') && $m->filename === $proposal->number.'.pdf');

        // marking it completed again / setting Meeting Done does not create a second proposal
        app(\App\Services\SalesAutomation::class)->createAndSendProposal($lead->fresh(), $meeting);
        $this->assertSame(1, Proposal::count());

        // customer accepts → invoice
        $this->actingAs(User::where('email', 'admin@crm.test')->first())->post(route('proposals.status', $proposal), ['status' => 'accepted'])->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $this->assertSame($proposal->id, $invoice->proposal_id);
        $this->assertSame('sent', $invoice->status);
        $this->assertEquals(50.0, $invoice->advance_percent);
        $this->assertEquals(round($proposal->total / 2, 2), $invoice->amount_due);
        $this->assertSame('won', $lead->fresh()->status);
        Mail::assertSent(CrmMessageMail::class, fn ($m) => str_contains($m->subjectLine, $invoice->number) && $m->files[0]['name'] === $invoice->number.'.pdf'
            && str_starts_with($m->files[0]['data'], '%PDF'));

        // idempotent
        app(\App\Services\SalesAutomation::class)->createAndSendInvoice($proposal->fresh());
        $this->assertSame(1, Invoice::count());
    }

    public function test_status_meeting_done_triggers_proposal_and_won_triggers_invoice(): void
    {
        $lead = $this->landingLead(['interested_service' => 'SEO']);
        $this->actingAs($lead->agent)->post(route('leads.status', $lead), ['status' => 'meeting_done'])->assertSessionHasNoErrors();
        $proposal = Proposal::firstOrFail();
        $this->assertSame('SEO Proposal (3 months)', $proposal->title);

        $this->actingAs($lead->agent)->post(route('leads.status', $lead->fresh()), ['status' => 'won']);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_invoice_trigger_after_proposal_and_off(): void
    {
        Setting::set('auto_invoice_trigger', 'after_proposal');
        $lead = $this->landingLead();
        app(\App\Services\SalesAutomation::class)->createAndSendProposal($lead);
        $this->assertSame(1, Invoice::count());

        Setting::set('auto_invoice_trigger', 'off');
        $lead2 = $this->landingLead(['phone' => '03007776655', 'email' => 'b@example.com']);
        $p = app(\App\Services\SalesAutomation::class)->createAndSendProposal($lead2);
        $p->update(['status' => 'accepted']);
        app(\App\Services\SalesAutomation::class)->onProposalAccepted($p);
        $this->assertSame(1, Invoice::count());
    }

    public function test_no_matching_package_means_no_automatic_proposal(): void
    {
        \App\Models\ServicePackage::query()->update(['is_default' => false]);
        $lead = $this->landingLead(['interested_service' => 'Plumbing']);
        $this->assertNull(app(\App\Services\SalesAutomation::class)->createAndSendProposal($lead));
        $this->assertSame(0, Proposal::count());
        $this->assertTrue($lead->activities()->where('description', 'like', '%no Service Package matches%')->exists());
    }

    public function test_invoice_pages_and_permissions(): void
    {
        $lead = $this->landingLead();
        $proposal = app(\App\Services\SalesAutomation::class)->createAndSendProposal($lead);
        $invoice = app(\App\Services\SalesAutomation::class)->createAndSendInvoice($proposal);
        $admin = User::where('email', 'admin@crm.test')->first();
        $otherAgent = User::where('email', 'sara@crm.test')->first() ;
        $owner = $lead->agent;
        $stranger = $owner->is($otherAgent) ? User::where('email', 'ali@crm.test')->first() : $otherAgent;

        $this->actingAs($admin)->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        $this->actingAs($admin)->get(route('invoices.show', $invoice))->assertOk();
        $this->assertStringStartsWith('%PDF', $this->actingAs($admin)->get(route('invoices.pdf', $invoice))->getContent());
        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk();
        $this->actingAs($stranger)->get(route('invoices.show', $invoice))->assertForbidden();
        $this->actingAs($owner)->post(route('invoices.status', $invoice), ['status' => 'paid'])->assertForbidden(); // agents cannot mark paid
        $this->actingAs($admin)->post(route('invoices.status', $invoice), ['status' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);

        foreach (['packages.index', 'packages.create'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk();
        }
        $this->actingAs($admin)->get(route('settings.index', ['tab' => 'automation']))->assertOk();
        $this->actingAs($owner)->get(route('packages.index'))->assertForbidden();
    }

    public function test_offline_analyser_understands_roman_urdu_times(): void
    {
        $client = new LocalRuleClient();
        $prompt = "[#5] [2026-10-20 10:00] CUSTOMER: Monday 11 am zoom meeting kar lete hain\n[#6] [2026-10-20 10:05] CUSTOMER: website chahiye budget 80k";
        $out = $client->structured('', $prompt, 'record_lead_analysis', []);
        $this->assertSame('2026-10-26 11:00', $out['meeting_request']['value']);
        $this->assertSame('online', $out['meeting_request']['type']);
        $this->assertSame('80k PKR', $out['budget']['value']);
        $this->assertSame('Website development', $out['service_interest']['value']);
    }
}
