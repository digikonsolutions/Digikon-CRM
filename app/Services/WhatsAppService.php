<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Cloud API (official Meta API).
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class WhatsAppService
{
    public function isConfigured(): bool
{
    $phoneNumberId = trim((string) Setting::get('wa_phone_number_id', ''));
    $accessToken = trim((string) Setting::get('wa_access_token', ''));

    return $phoneNumberId !== ''
        && $accessToken !== ''
        && ctype_digit($phoneNumberId);
}

    /** Free-form text – only works within 24h after the customer messaged you. */
    public function sendText(Lead $lead, string $body, ?int $userId = null): WhatsappMessage
    {
        return $this->send($lead, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ], $body, $userId);
    }

    /**
     * Approved template – required for the first message / outside 24h window.
     * $variables fill {{1}}, {{2}} ... in the template body.
     */
    public function sendTemplate(Lead $lead, string $name, string $language = 'en', array $variables = [], ?int $userId = null): WhatsappMessage
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($variables) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($variables)),
            ]];
        }

        $preview = "[Template: {$name}]".($variables ? ' '.implode(' | ', $variables) : '');

        return $this->send($lead, ['type' => 'template', 'template' => $template], $preview, $userId);
    }

    /**
     * Send a PDF as a WhatsApp document (proposal / invoice). The file is uploaded to the
     * WhatsApp media endpoint first. Like free text, documents are only delivered inside the
     * 24-hour customer-service window.
     */
    public function sendDocument(Lead $lead, string $pdf, string $filename, ?string $caption = null, ?int $userId = null): WhatsappMessage
    {
        $preview = "[Document: {$filename}]".($caption ? ' '.$caption : '');
        if (! $this->isConfigured() || ! PhoneNumber::normalize($lead->phone) || $lead->whatsapp_opt_out) {
            return $this->send($lead, [], $preview, $userId); // records the failure with the reason
        }

        try {
            $upload = Http::withToken(Setting::get('wa_access_token'))->timeout(30)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post('https://graph.facebook.com/'.Setting::get('graph_version', 'v26.0').'/'.Setting::get('wa_phone_number_id').'/media', [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);
            $mediaId = $upload->json('id');
        } catch (\Throwable $e) {
            $mediaId = null;
            $upload = null;
        }

        if (! $mediaId) {
            $message = WhatsappMessage::create(['lead_id' => $lead->id, 'user_id' => $userId, 'direction' => 'out', 'message_type' => 'document',
                'phone' => PhoneNumber::normalize($lead->phone) ?? '', 'body' => $preview, 'status' => 'queued']);

            return $this->fail($message, 'Media upload failed: '.($upload?->json('error.message') ?? 'no response'));
        }

        $msg = $this->send($lead, ['type' => 'document', 'document' => array_filter(['id' => $mediaId, 'filename' => $filename, 'caption' => $caption])], $preview, $userId);
        $msg->update(['message_type' => 'document']);

        return $msg;
    }

    protected function send(Lead $lead, array $payload, string $preview, ?int $userId): WhatsappMessage
    {
        $phone = PhoneNumber::normalize($lead->phone);

        $message = WhatsappMessage::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'direction' => 'out',
            'phone' => $phone ?? '',
            'body' => $preview,
            'status' => 'queued',
        ]);

        if (! $phone) {
            return $this->fail($message, 'Lead has no phone number.');
        }
        if ($lead->whatsapp_opt_out) {
            return $this->fail($message, 'Lead has opted out of WhatsApp messages.');
        }
        if (! $this->isConfigured()) {
    return $this->fail(
        $message,
        'WhatsApp configuration is incomplete. Check Settings > WhatsApp API and verify the Phone Number ID and access token.'
    );
}
        if (! $payload) {
            return $this->fail($message, 'Nothing to send.');
        }

        try {
            $version = Setting::get('graph_version', 'v26.0');
            $response = Http::withToken(Setting::get('wa_access_token'))
                ->timeout(20)
                ->post("https://graph.facebook.com/{$version}/".Setting::get('wa_phone_number_id').'/messages',
                    array_merge(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $phone], $payload));

            if ($response->failed()) {
                return $this->fail($message, $response->json('error.message') ?? $response->body());
            }

            $message->update(['status' => 'sent', 'wa_message_id' => $response->json('messages.0.id')]);
            $lead->update(['last_contacted_at' => now()]);
            $lead->log('whatsapp', 'WhatsApp sent: '.\Illuminate\Support\Str::limit($preview, 120), $userId);
        } catch (\Throwable $e) {
            return $this->fail($message, $e->getMessage());
        }

        return $message;
    }

    protected function fail(WhatsappMessage $message, string $error): WhatsappMessage
    {
        $message->update(['status' => 'failed', 'error' => $error]);

        return $message;
    }

    /** Replace {name}, {agent}, {company}, {service} placeholders. */
    public static function fill(?string $text, Lead $lead): string
    {
        return strtr((string) $text, [
            '{name}' => $lead->name ?: 'Sir/Madam',
            '{agent}' => $lead->agent?->name ?? Setting::get('company_name', 'Our team'),
            '{agent_phone}' => $lead->agent?->phone ?? Setting::get('company_phone', ''),
            '{company}' => Setting::get('company_name', ''),
            '{service}' => $lead->service ?: 'our services',
        ]);
    }
}
