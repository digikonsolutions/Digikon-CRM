<?php

namespace App\Services;

use App\Exceptions\MetaApiException;
use App\Jobs\ProcessMetaLeadJob;
use App\Models\Lead;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Meta Lead Ads (Facebook + Instagram lead forms).
 * Docs: https://developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving
 *
 * Flow: webhook → LeadSourceEvent (pending) → ProcessMetaLeadJob → fetchLead() → normalize() → LeadIngestionService.
 * The access token is sent in the Authorization header (never in URLs, logs or responses).
 */
class MetaLeadService
{
    /** Graph error codes that are worth retrying (rate limits / temporary errors). */
    protected const TEMPORARY_CODES = [1, 2, 4, 17, 32, 341, 613, 80000, 80001, 80004];

    public function __construct(protected LeadIngestionService $ingestion) {}

    /** Record a webhook leadgen event and queue it. Returns false if it was already received. */
    public function queueLeadgen(string $leadgenId, ?string $pageId = null, ?string $formId = null, array $value = []): bool
    {
        [$event, $isNew] = $this->ingestion->recordEvent('meta', $leadgenId, [
            'leadgen_id' => $leadgenId,
            'page_id' => $pageId,
            'form_id' => $formId,
            'ad_id' => $value['ad_id'] ?? null,
            'adgroup_id' => $value['adgroup_id'] ?? null,
            'created_time' => $value['created_time'] ?? null,
        ]);

        if ($isNew || $event->status === 'pending') {
            ProcessMetaLeadJob::dispatch($event->id);
        }

        return $isNew;
    }

    /** Backward-compatible synchronous import (used by older code paths / manual retry). */
    public function importLeadgen(string $leadgenId, ?string $pageId = null, ?string $formId = null): ?Lead
    {
        [$event] = $this->ingestion->recordEvent('meta', $leadgenId, ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => $formId]);
        if ($event->lead_id) {
            return $event->lead;
        }
        $data = $this->fetchLead($leadgenId);

        return $this->ingestion->process($event, $this->normalize($data, $leadgenId, $pageId, $formId));
    }

    public function token(): ?string
    {
        return Setting::get('meta_page_access_token');
    }

    /** @throws MetaApiException */
    public function fetchLead(string $leadgenId): array
    {
        $token = $this->token();
        if (! $token) {
            throw new MetaApiException('Meta Page access token is not configured (Settings → Meta Lead Ads).', temporary: false);
        }

        $version = Setting::get('graph_version', 'v26.0');

        try {
            $response = Http::withToken($token)->timeout(20)->acceptJson()
                ->get("https://graph.facebook.com/{$version}/".rawurlencode($leadgenId), [
                    'fields' => 'created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,platform,is_organic',
                ]);
        } catch (ConnectionException $e) {
            throw new MetaApiException('Could not reach Meta: '.$e->getMessage(), temporary: true);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $code = (int) $response->json('error.code');
        $message = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
        $temporary = $response->serverError() || $response->status() === 429 || in_array($code, self::TEMPORARY_CODES, true);

        if ($code === 190) {
            $message = 'Meta access token expired or was revoked — generate a new Page token in Settings → Meta Lead Ads. ('.$message.')';
        }

        throw new MetaApiException('Meta API error: '.$message, $temporary, $code ?: null);
    }

    /** Map Meta's form answers + ad attribution to CRM lead fields. Missing fields stay null. */
    public function normalize(array $data, string $leadgenId, ?string $pageId = null, ?string $formId = null): array
    {
        $f = $this->flattenFields($data['field_data'] ?? []);
        $pick = function (array $keys) use ($f) {
            foreach ($keys as $k) {
                if (filled($f[$k] ?? null)) {
                    return $f[$k];
                }
            }

            return null;
        };

        $formId = $data['form_id'] ?? $formId;
        $name = $pick(['full_name', 'name']) ?? (trim(($f['first_name'] ?? '').' '.($f['last_name'] ?? '')) ?: null);

        return [
            'name' => $name,
            'email' => $pick(['email', 'work_email']),
            'phone' => $pick(['phone_number', 'phone', 'whatsapp_number', 'mobile_number']),
            'company' => $pick(['company_name', 'company']),
            'city' => $pick(['city']),
            'service' => $pick(['service', 'which_service_do_you_need?', 'interested_service', 'services']),
            'budget' => $pick(['budget', 'your_budget']),
            'source' => 'meta',
            'campaign_name' => $data['campaign_name'] ?? null,
            'adset_name' => $data['adset_name'] ?? null,
            'ad_name' => $data['ad_name'] ?? null,
            'meta_campaign_id' => $data['campaign_id'] ?? null,
            'meta_adset_id' => $data['adset_id'] ?? null,
            'meta_ad_id' => $data['ad_id'] ?? null,
            'platform' => $data['platform'] ?? null,
            'form_name' => $formId ? $this->formName($formId) : null,
            'meta_lead_id' => $leadgenId,
            'meta_form_id' => $formId,
            'meta_page_id' => $pageId,
            'submitted_at' => ! empty($data['created_time']) ? \Illuminate\Support\Carbon::parse($data['created_time'])->setTimezone(config('app.timezone')) : null,
            'raw_data' => $data,
        ];
    }

    protected function flattenFields(array $fieldData): array
    {
        $out = [];
        foreach ($fieldData as $field) {
            $key = strtolower(str_replace(' ', '_', (string) ($field['name'] ?? '')));
            $out[$key] = implode(', ', (array) ($field['values'] ?? []));
        }

        return $out;
    }

    protected function formName(string $formId): ?string
    {
        try {
            return Http::withToken((string) $this->token())->timeout(10)
                ->get('https://graph.facebook.com/'.Setting::get('graph_version', 'v26.0').'/'.rawurlencode($formId), ['fields' => 'name'])
                ->json('name');
        } catch (\Throwable) {
            return null;
        }
    }
}
