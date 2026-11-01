<?php

namespace App\Console\Commands;

use App\Services\LeadStatusService;
use App\Services\SalesAutomation;
use Illuminate\Console\Command;

class AcceptProposalCommand extends Command
{
    use TestingHelpers;

    protected $signature = 'crm:accept-proposal {lead : Lead ID or phone}';

    protected $description = 'TESTING: mark the latest proposal Accepted (lead → Won) → automatic invoice PDF';

    public function handle(LeadStatusService $statuses, SalesAutomation $automation): int
    {
        $this->runJobsNow();
        $lead = $this->findLead($this->argument('lead'));
        $proposal = $lead?->proposals()->latest()->first();
        if (! $proposal) {
            $this->error('No proposal found for this lead.');

            return self::FAILURE;
        }

        $proposal->update(['status' => 'accepted', 'accepted_at' => $proposal->accepted_at ?? now()]);
        $statuses->change($lead->fresh(), 'status', 'won', $lead->assigned_to, 'manual', "Proposal {$proposal->number} accepted (terminal test)");
        $automation->onProposalAccepted($proposal->fresh());

        $this->line($this->leadLine($lead));
        $invoice = $proposal->invoices()->latest()->first();
        if ($invoice) {
            $this->info("Invoice {$invoice->number}: total {$invoice->money($invoice->total)} · due now {$invoice->money($invoice->amount_due)} · status {$invoice->status}");
            $this->line('PDF: '.route('invoices.pdf', $invoice));
        } else {
            $this->warn('No invoice created (Settings → Sales Automation → Invoice PDF is not "when accepted").');
        }

        return self::SUCCESS;
    }
}
