<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeLeadConversationJob;
use App\Models\Setting;
use App\Services\Ai\AiClientFactory;
use App\Models\WhatsappMessage;
use App\Services\FollowupService;
use App\Services\LeadService;
use App\Services\MetaLeadService;
use App\Services\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Public endpoints Meta calls:
 *   /webhooks/meta      -> Facebook Page "leadgen" events (Lead Ads)
 *   /webhooks/whatsapp  -> WhatsApp Cloud API incoming messages & delivery statuses
 */
class WebhookController extends Controller
{
    /** Meta webhook verification handshake (GET). */
    public function verify(Request $request)
    {
        $expected = Setting::get('webhook_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $expected && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response($request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Invalid verify token', 403);
    }

    public function meta(Request $request, MetaLeadService $meta)
    {
        if (! $this->validSignature($request)) {
            return response('Invalid signature', 403);
        }

        // Persist + queue quickly; the Graph API call happens in ProcessMetaLeadJob (retries on temporary errors).
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? null) !== 'leadgen' || empty($value['leadgen_id'])) {
                    continue;
                }
                try {
                    $meta->queueLeadgen((string) $value['leadgen_id'], isset($value['page_id']) ? (string) $value['page_id'] : null,
                        isset($value['form_id']) ? (string) $value['form_id'] : null, $value);
                } catch (\Throwable $e) {
                    Log::error('Meta leadgen event could not be stored: '.$e->getMessage(), ['leadgen_id' => $value['leadgen_id']]);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function whatsapp(Request $request, LeadService $leads, FollowupService $followups)
    {
        if (! $this->validSignature($request)) {
            return response('Invalid signature', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // Delivery / read receipts
                foreach ($value['statuses'] ?? [] as $status) {
                    WhatsappMessage::where('wa_message_id', $status['id'] ?? '')->update(array_filter([
                        'status' => $status['status'] ?? null,
                        'error' => $status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? null,
                    ]));
                }

                // Incoming customer messages
                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleIncoming($message, $value['contacts'][0]['profile']['name'] ?? null, $leads, $followups);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    protected function handleIncoming(array $message, ?string $profileName, LeadService $leads, FollowupService $followups): void
    {
        $from = PhoneNumber::normalize('+'.($message['from'] ?? ''));
        $body = match ($message['type'] ?? 'text') {
            'text' => $message['text']['body'] ?? '',
            'button' => $message['button']['text'] ?? '',
            'interactive' => $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? '',
            default => '['.($message['type'] ?? 'message').']',
        };

        $waId = (string) ($message['id'] ?? '');

        // Meta can retry the same webhook (even concurrently) → lock on the message id + existence check.
        $lock = Cache::lock('wa-in-'.md5($waId ?: uniqid()), 15);
        if (! $lock->get()) {
            return;
        }

        try {
            if ($waId !== '' && WhatsappMessage::where('wa_message_id', $waId)->exists()) {
                return;
            }

            $lead = $leads->findByPhone($from);
            if (! $lead) {
                // New WhatsApp enquiry becomes a lead and is distributed automatically
                $lead = $leads->create(['name' => $profileName, 'phone' => $from, 'source' => 'whatsapp']);
            }

            WhatsappMessage::create([
                'lead_id' => $lead->id,
                'direction' => 'in',
                'message_type' => (string) ($message['type'] ?? 'text'),
                'phone' => $from,
                'body' => $body,
                'wa_message_id' => $waId ?: null,
                'wa_timestamp' => isset($message['timestamp']) && is_numeric($message['timestamp'])
                    ? \Illuminate\Support\Carbon::createFromTimestamp((int) $message['timestamp'], config('app.timezone')) : null,
                'status' => 'received',
            ]);
        } finally {
            $lock->release();
        }

        // AI segregation of the whole chat — debounced so a burst of messages is analysed once.
        if (Setting::get('ai_auto_analyze', '0') === '1' && AiClientFactory::isConfigured()) {
            AnalyzeLeadConversationJob::dispatch($lead->id, 'auto')
                ->delay(now()->addMinutes((int) Setting::get('ai_debounce_minutes', config('crm.ai.debounce_minutes', 10))));
        }

        $lead->update(['last_reply_at' => now()]);
        app(\App\Services\LeadScoreService::class)->refresh($lead);
        $lead->log('whatsapp', 'Customer replied on WhatsApp: '.\Illuminate\Support\Str::limit($body, 150), null);

        if (in_array(strtolower(trim($body)), ['stop', 'unsubscribe', 'stop messages'])) {
            $lead->update(['whatsapp_opt_out' => true]);
            $followups->cancelFor($lead, 'Customer sent STOP (opted out)');
        } else {
            $followups->cancelFor($lead, 'Customer replied — agent takes over');
        }
    }

    /** Verify X-Hub-Signature-256 using the Meta App Secret (skipped if no secret saved). */
    /**
 * Verify X-Hub-Signature-256 using the Meta App Secret.
 * Reject webhook requests if the secret is missing or invalid.
 */
protected function validSignature(Request $request): bool
{
    $secret = Setting::get('meta_app_secret');

    if (! is_string($secret) || trim($secret) === '') {
        Log::error(
            'Meta webhook rejected: Meta App Secret is not configured.'
        );

        return false;
    }

    $header = (string) $request->header('X-Hub-Signature-256');

    if (! str_starts_with($header, 'sha256=')) {
        return false;
    }

    $expected = 'sha256='.hash_hmac(
        'sha256',
        $request->getContent(),
        $secret
    );

    return hash_equals($expected, $header);
}
}
