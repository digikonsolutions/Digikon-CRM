<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\LocalRuleClient;
use App\Services\PhoneNumber;

/** Shared helpers for the terminal test commands. */
trait TestingHelpers
{
    /** Run queued jobs (auto proposal / invoice / notifications) right away while testing from the terminal. */
    protected function runJobsNow(): void
    {
        config(['queue.default' => 'sync']);
    }

    /** No AI key yet → use the offline rule-based analyser so the flow still works. */
    protected function ensureAi(bool $forceLocal = false): string
    {
        if ($forceLocal || ! AiClientFactory::isConfigured()) {
            app()->instance(AiClient::class, new LocalRuleClient());

            return 'offline rules (no API key)';
        }

        return AiClientFactory::provider().' / '.AiClientFactory::model();
    }

    protected function findLead(string $idOrPhone): ?Lead
    {
        if (ctype_digit($idOrPhone) && strlen($idOrPhone) < 8) {
            return Lead::find((int) $idOrPhone);
        }
        $tail = PhoneNumber::tail($idOrPhone);

        return $tail ? Lead::where('phone', 'like', '%'.$tail)->latest()->first() : null;
    }

    protected function leadLine(Lead $lead): string
    {
        $lead->refresh();

        return "#{$lead->id} {$lead->display_name} · {$lead->phone} · status: {$lead->status_label} · temperature: ".($lead->temperature ?? '—')
            .' · owner: '.($lead->agent?->name ?? 'unassigned').' · score: '.$lead->score;
    }
}
