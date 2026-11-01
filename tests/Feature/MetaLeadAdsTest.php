<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MetaLeadAdsTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'EAAG-SECRET-PAGE-TOKEN-123';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setMany(['webhook_verify_token' => 'verify-me', 'meta_page_access_token' => self::TOKEN, 'meta_app_secret' => 'app-secret', 'graph_version' => 'v26.0', 'auto_followups' => '0']);
        $this->user('agent');
    }

    protected function payload(string $leadgenId = '111'): array
    {
        return ['object' => 'page', 'entry' => [['id' => 'PAGE1', 'changes' => [['field' => 'leadgen', 'value' => [
            'leadgen_id' => $leadgenId, 'page_id' => 'PAGE1', 'form_id' => 'FORM1', 'ad_id' => 'AD1',
        ]]]]]];
    }

    protected function postSigned(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    protected int $graphStatus = 200;

    protected array $graphError = [];

    protected bool $faked = false;

    /** One fake for the whole test; later calls only change the response. */
    protected function fakeGraph(int $status = 200, array $error = []): void
    {
        $this->graphStatus = $status;
        $this->graphError = $error;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(['graph.facebook.com/*' => function ($request) {
            $status = $this->graphStatus;
            $error = $this->graphError;
            if (str_contains($request->url(), 'FORM1')) {
                return Http::response(['name' => 'Website form'], 200);
            }

            return $status === 200
                ? Http::response(['created_time' => '2026-10-20T07:00:00+0000', 'campaign_id' => 'C1', 'campaign_name' => 'Oct Website', 'ad_id' => 'AD1', 'form_id' => 'FORM1', 'platform' => 'ig',
                    'field_data' => [['name' => 'full_name', 'values' => ['Ahmed Khan']], ['name' => 'phone_number', 'values' => ['03001234567']], ['name' => 'email', 'values' => ['ahmed@example.com']]]], 200)
                : Http::response(['error' => $error], $status);
        }]);
    }

    public function test_webhook_verification_challenge(): void
    {
        $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], json_encode($this->payload()))->assertForbidden();
        $this->assertSame(0, LeadSourceEvent::count());
    }

    public function test_lead_is_created_with_attribution_and_duplicates_are_ignored(): void
    {
        $this->fakeGraph();

        $this->postSigned($this->payload('111'))->assertOk();
        $this->postSigned($this->payload('111'))->assertOk(); // Meta retry

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, LeadSourceEvent::count());
        $lead = Lead::first();
        $this->assertSame('Ahmed Khan', $lead->name);
        $this->assertSame('923001234567', $lead->phone);
        $this->assertSame('C1', $lead->meta_campaign_id);
        $this->assertSame('ig', $lead->platform);
        $this->assertSame('Oct Website', $lead->campaign_name);
        $this->assertSame('processed', LeadSourceEvent::first()->status);
        $this->assertNotNull($lead->assigned_to, 'auto distributed');

        // token is sent as a header, never in the URL
        Http::assertSent(fn ($r) => ! str_contains($r->url(), self::TOKEN) && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_temporary_api_failure_is_kept_for_retry_and_token_not_logged(): void
    {
        $this->fakeGraph(500, ['message' => 'Service temporarily unavailable', 'code' => 2]);
        Log::spy();

        $this->postSigned($this->payload('222'))->assertOk();

        $event = LeadSourceEvent::firstOrFail();
        $this->assertContains($event->status, ['pending', 'failed']);
        $this->assertStringContainsString('temporarily', $event->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $event->error);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($event->payload));
        $this->assertSame(0, Lead::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => ! str_contains(json_encode($ctx).$msg, self::TOKEN));

        // later success via retry
        $this->fakeGraph();
        $manager = $this->user('admin');
        $this->actingAs($manager)->post(route('lead-sources.retry', $event))->assertRedirect();
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame(1, Lead::count());
    }

    public function test_expired_token_fails_permanently_and_alerts(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $admin = $this->user('admin');
        $this->fakeGraph(400, ['message' => 'Error validating access token', 'code' => 190]);

        $this->postSigned($this->payload('333'))->assertOk();

        $event = LeadSourceEvent::firstOrFail();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('expired', $event->error);
        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \App\Notifications\CrmAlert::class, fn ($n) => $n->type === 'ingestion_failed');
    }
}
