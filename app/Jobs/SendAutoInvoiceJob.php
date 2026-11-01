<?php

namespace App\Jobs;

use App\Models\Proposal;
use App\Services\SalesAutomation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAutoInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $proposalId) {}

    public function handle(SalesAutomation $automation): void
    {
        if ($proposal = Proposal::find($this->proposalId)) {
            $automation->createAndSendInvoice($proposal);
        }
    }
}
