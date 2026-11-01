<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Notifications\CrmAlert;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Shared pipeline for external lead sources (Meta Lead Ads, landing pages, future sources).
 *
 *   1. recordEvent()  — durable, idempotent log row (unique source + external_id)
 *   2. process()      — validated lead creation through LeadService (dedupe → assignment → follow-ups → notifications)
 */
class LeadIngestionService
{
    public function __construct(protected LeadService $leads) {}

    /**
     * @return array{0: LeadSourceEvent, 1: bool} [event, wasNew]
     */
    public function recordEvent(string $source, string $externalId, array $payload = [], array $attribution = [], ?string $ip = null): array
    {
        $existing = LeadSourceEvent::where('source', $source)->where('external_id', $externalId)->first();
        if ($existing) {
            return [$existing, false];
        }

        try {
            $event = LeadSourceEvent::create([
                'source' => $source,
                'external_id' => $externalId,
                'status' => 'pending',
                'payload' => $payload ?: null,
                'attribution' => $attribution ?: null,
                'ip' => $ip,
            ]);

            return [$event, true];
        } catch (QueryException $e) {
            // Two identical webhooks at the same moment: the unique index wins, the second one reuses the row.
            $existing = LeadSourceEvent::where('source', $source)->where('external_id', $externalId)->first();
            if ($existing) {
                return [$existing, false];
            }
            throw $e;
        }
    }

    /**
     * Create (or match) the CRM lead for an event. Safe to call again for the same event.
     */
    public function process(LeadSourceEvent $event, array $leadData): ?Lead
    {
        return DB::transaction(function () use ($event, $leadData) {
            $event = LeadSourceEvent::whereKey($event->id)->lockForUpdate()->first();

            if (in_array($event->status, ['processed', 'duplicate', 'spam'], true)) {
                return $event->lead;
            }

            if (blank($leadData['phone'] ?? null) && blank($leadData['email'] ?? null)) {
                $this->fail($event, 'No phone number or email in the submission — lead not created.', permanent: true);

                return null;
            }

            $event->increment('attempts');
            $lead = $this->leads->create($leadData);

            $event->update([
                'status' => $lead->wasRecentlyCreated ? 'processed' : 'duplicate',
                'lead_id' => $lead->id,
                'error' => null,
                'processed_at' => now(),
            ]);

            return $lead;
        });
    }

    /**
     * Landing-page submission (used by the public API and the terminal test commands).
     * $v = validated form fields. Returns ['event' => ..., 'lead' => ?Lead, 'replay' => bool].
     */
    public function ingestLandingPage(array $v, string $submissionId, array $attribution = [], ?string $ip = null, ?string $userAgent = null): array
    {
        [$event, $isNew] = $this->recordEvent('landing_page', $submissionId, array_merge(
            array_intersect_key($v, array_flip(['name', 'email', 'phone', 'company', 'city', 'interested_service', 'budget', 'message', 'consent'])),
            ['user_agent' => mb_substr((string) $userAgent, 0, 255)]
        ), $attribution, $ip);

        if (! $isNew && in_array($event->status, ['processed', 'duplicate'], true)) {
            return ['event' => $event, 'lead' => $event->lead, 'replay' => true];
        }

        $notes = trim(($v['message'] ?? '')."\n".(isset($v['consent']) ? 'Consent to contact: '.($v['consent'] ? 'yes' : 'no') : ''));

        try {
            $lead = $this->process($event, array_filter([
                'name' => $v['name'] ?? null,
                'email' => $v['email'] ?? null,
                'phone' => $v['phone'] ?? null,
                'company' => $v['company'] ?? null,
                'city' => $v['city'] ?? null,
                'service' => $v['interested_service'] ?? null,
                'budget' => $v['budget'] ?? null,
                'notes' => $notes ?: null,
                'source' => 'website',
                'campaign_name' => $v['utm_campaign'] ?? null,
                'utm_source' => $attribution['utm_source'] ?? null,
                'utm_medium' => $attribution['utm_medium'] ?? null,
                'utm_campaign' => $attribution['utm_campaign'] ?? null,
                'utm_content' => $attribution['utm_content'] ?? null,
                'landing_page_url' => $attribution['landing_page_url'] ?? null,
                'referrer' => isset($attribution['referrer']) ? mb_substr($attribution['referrer'], 0, 1000) : null,
                'submitted_at' => now(),
            ], fn ($x) => $x !== null));
        } catch (\Throwable $e) {
            $this->fail($event, 'Processing error: '.$e->getMessage(), permanent: true);
            throw $e;
        }

        // New landing-page leads become Hot (Settings → Sales Automation).
        if ($lead && $event->fresh()->status === 'processed') {
            app(SalesAutomation::class)->onLandingLeadCreated($lead);
        }

        return ['event' => $event->fresh(), 'lead' => $lead?->fresh(), 'replay' => false];
    }

    public function fail(LeadSourceEvent $event, string $error, bool $permanent = false): void
    {
        $event->update([
            'status' => $permanent ? 'failed' : 'pending',
            'error' => mb_substr($error, 0, 2000),
        ]);

        if ($permanent && ! $event->failure_notified_at) {
            $event->update(['failure_notified_at' => now()]);
            Notifier::send(Notifier::usersWithPermission('lead-sources.manage'), 'ingestion_failed', new CrmAlert(
                'ingestion_failed',
                'Lead ingestion failed ('.$event->source.')',
                mb_substr($error, 0, 300),
                route('lead-sources.index', ['status' => 'failed']),
            ));
        }
    }
}
