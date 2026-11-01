<?php

namespace App\Jobs;

use App\Exceptions\MetaApiException;
use App\Models\LeadSourceEvent;
use App\Services\LeadIngestionService;
use App\Services\MetaLeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMetaLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MetaLeadService $meta, LeadIngestionService $ingestion): void
    {
        $event = LeadSourceEvent::find($this->eventId);
        if (! $event || in_array($event->status, ['processed', 'duplicate'], true)) {
            return;
        }

        $event->update(['status' => 'processing']);
        $p = $event->payload ?? [];

        try {
            $data = $meta->fetchLead($event->external_id);
            $normalized = $meta->normalize($data, $event->external_id, $p['page_id'] ?? null, $p['form_id'] ?? null);

            $event->update(['attribution' => array_filter([
                'campaign_id' => $normalized['meta_campaign_id'],
                'campaign_name' => $normalized['campaign_name'],
                'adset_id' => $normalized['meta_adset_id'],
                'ad_id' => $normalized['meta_ad_id'],
                'form_id' => $normalized['meta_form_id'],
                'page_id' => $normalized['meta_page_id'],
                'platform' => $normalized['platform'],
                'created_time' => $data['created_time'] ?? null,
            ])]);

            $ingestion->process($event, $normalized);
        } catch (MetaApiException $e) {
            Log::warning('Meta lead fetch failed', ['event_id' => $event->id, 'leadgen_id' => $event->external_id, 'code' => $e->metaCode, 'error' => $e->getMessage()]);

            if ($e->temporary && $this->attempts() < $this->tries) {
                $ingestion->fail($event, $e->getMessage().' (will retry)');
                $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);

                return;
            }
            $ingestion->fail($event, $e->getMessage(), permanent: true);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($event = LeadSourceEvent::find($this->eventId)) {
            app(LeadIngestionService::class)->fail($event, 'Processing error: '.$e->getMessage(), permanent: true);
        }
    }
}
