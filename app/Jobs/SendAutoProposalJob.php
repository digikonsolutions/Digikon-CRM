<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Meeting;
use App\Services\SalesAutomation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAutoProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $leadId, public ?int $meetingId = null) {}

    public function handle(SalesAutomation $automation): void
    {
        if ($lead = Lead::find($this->leadId)) {
            $automation->createAndSendProposal($lead, $this->meetingId ? Meeting::find($this->meetingId) : null);
        }
    }
}
