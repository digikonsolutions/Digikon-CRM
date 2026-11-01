<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('auto_followups', '0');
        $this->user('agent');
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/v1/public/leads', ['name' => 'No contact'])->assertStatus(422)->assertJsonPath('ok', false)->assertJsonValidationErrors(['phone', 'email']);
        $this->postJson('/api/v1/public/leads', ['phone' => 'abc'])->assertStatus(422);
        $this->postJson('/api/v1/public/leads', ['email' => 'not-an-email'])->assertStatus(422);
        $this->assertSame(0, Lead::count());
    }

    public function test_lead_is_created_with_attribution_and_no_internal_id_leaks(): void
    {
        $res = $this->postJson('/api/v1/public/leads', [
            'name' => 'Sana', 'phone' => '0333-1234567', 'email' => 'SANA@Example.com', 'interested_service' => 'SEO', 'budget' => '50k',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'seo-oct', 'landing_page_url' => 'https://example.com/seo', 'message' => 'Call me',
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertArrayNotHasKey('lead_id', $res->json());
        $this->assertArrayNotHasKey('id', $res->json());
        $lead = Lead::firstOrFail();
        $this->assertSame('website', $lead->source);
        $this->assertSame('923331234567', $lead->phone);
        $this->assertSame('sana@example.com', $lead->email);
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('seo-oct', $lead->utm_campaign);
        $this->assertSame('https://example.com/seo', $lead->landing_page_url);
        $this->assertSame('processed', LeadSourceEvent::first()->status);
        $this->assertSame('google', LeadSourceEvent::first()->attribution['utm_source']);
    }

    public function test_duplicate_submissions_follow_the_policy(): void
    {
        $body = ['name' => 'Bilal', 'phone' => '03001112222', 'submission_id' => 'sub_abcdef123456'];
        $this->postJson('/api/v1/public/leads', $body)->assertCreated();
        $this->postJson('/api/v1/public/leads', $body)->assertOk();            // same submission id → idempotent
        $this->assertSame(1, LeadSourceEvent::count());

        // same phone written differently, new submission → matched to the open lead (no duplicate)
        $this->postJson('/api/v1/public/leads', ['name' => 'Bilal K', 'phone' => '+92 300 1112222', 'email' => 'bilal@x.com'])->assertCreated();
        $this->assertSame(1, Lead::count());
        $this->assertSame('duplicate', LeadSourceEvent::latest('id')->first()->status);
        $this->assertSame('bilal@x.com', Lead::first()->email, 'empty field filled');
        $this->assertSame('Bilal', Lead::first()->name, 'existing name not overwritten');

        // same name only → NOT merged
        $this->postJson('/api/v1/public/leads', ['name' => 'Bilal', 'phone' => '03009998888'])->assertCreated();
        $this->assertSame(2, Lead::count());
    }

    public function test_honeypot_spam_creates_no_lead(): void
    {
        $this->postJson('/api/v1/public/leads', ['name' => 'Bot', 'phone' => '03001234000', 'hp_field' => 'http://spam'])->assertCreated();
        $this->assertSame(0, Lead::count());
        $this->assertSame('spam', LeadSourceEvent::first()->status);
    }

    public function test_rate_limiting(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/public/leads', ['phone' => '0300555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])->assertSuccessful();
        }
        $this->postJson('/api/v1/public/leads', ['phone' => '03005559999'])->assertStatus(429);
    }

    public function test_plain_html_form_redirects_only_to_allowed_thank_you_page(): void
    {
        Setting::set('public_form_allowed_origins', 'https://www.mysite.com');

        $this->post('/api/v1/public/leads', ['phone' => '03006660000', 'redirect_to' => 'https://www.mysite.com/thank-you'])
            ->assertRedirect('https://www.mysite.com/thank-you');
        // other domains are ignored (no open redirect) → JSON answer
        $this->post('/api/v1/public/leads', ['phone' => '03006661111', 'redirect_to' => 'https://evil.com/x'])->assertCreated()->assertJsonPath('ok', true);
        // fetch/AJAX callers always get JSON
        $this->postJson('/api/v1/public/leads', ['phone' => '03006662222', 'redirect_to' => 'https://www.mysite.com/thank-you'])->assertCreated();
        $this->assertSame(3, Lead::count());
    }

    public function test_digikon_talk_to_us_form_fields_create_a_hot_lead(): void
    {
        $this->postJson('/api/v1/public/leads', [
            'name' => 'Alex Morgan', 'work_email' => 'Alex@Company.com', 'website' => 'company.com', 'automate' => 'Lead follow-ups',
        ])->assertCreated()->assertJsonPath('ok', true);

        $lead = Lead::firstOrFail();
        $this->assertSame('alex@company.com', $lead->email);
        $this->assertSame('company.com', $lead->company);
        $this->assertSame('Lead follow-ups', $lead->service);
        $this->assertSame('website', $lead->source);
        $this->assertSame('hot', $lead->temperature);
        $this->assertNotNull($lead->assigned_to);
    }

    public function test_form_key_is_enforced_when_configured(): void
    {
        Setting::set('public_form_key', 'pf_secret');
        $this->postJson('/api/v1/public/leads', ['phone' => '03007770000'])->assertForbidden();
        $this->postJson('/api/v1/public/leads', ['phone' => '03007770000'], ['X-Form-Key' => 'pf_secret'])->assertCreated();
    }
}
