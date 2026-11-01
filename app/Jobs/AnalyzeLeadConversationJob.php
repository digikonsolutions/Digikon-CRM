<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiException;
use App\Services\LeadQualificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto analysis after customer WhatsApp messages. Dispatched with a delay (debounce) and
 * unique per lead, so a burst of 10 messages produces ONE analysis of the whole chat.
 */
class AnalyzeLeadConversationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public int $leadId, public string $trigger = 'auto') {}

    public function uniqueId(): string
    {
        return 'lead-analysis-'.$this->leadId;
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(LeadQualificationService $service): void
    {
        $lead = Lead::find($this->leadId);
        if (! $lead || ! AiClientFactory::isConfigured()) {
            return;
        }
        // Idempotent: nothing new since the last analysis → skip (webhook retries, repeated events).
        if (! $service->needsAnalysis($lead)) {
            return;
        }

        try {
            $service->analyze($lead, null, $this->trigger);
        } catch (AiException $e) {
            if ($e->temporary) {
                throw $e; // let the queue retry with backoff
            }
            Log::info('AI analysis skipped for lead '.$lead->id.': '.$e->getMessage());
        }
    }
}
