<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\MeetingService;
use Illuminate\Console\Command;

class MeetingDoneCommand extends Command
{
    use TestingHelpers;

    protected $signature = 'crm:meeting-done {lead : Lead ID or phone} {--outcome=Meeting went well, wants the proposal}';

    protected $description = 'TESTING: mark the lead\'s meeting as Completed (meeting success) → automatic proposal PDF';

    public function handle(MeetingService $meetings): int
    {
        $this->runJobsNow();
        $lead = $this->findLead($this->argument('lead'));
        if (! $lead) {
            $this->error('Lead not found.');

            return self::FAILURE;
        }

        $meeting = $lead->meetings()->active()->latest('starts_at')->first();
        if (! $meeting) {
            $agent = $lead->agent ?? \App\Models\User::assignable()->first();
            $meeting = Meeting::create(['lead_id' => $lead->id, 'organizer_id' => $agent->id, 'assigned_agent_id' => $agent->id, 'title' => 'Meeting with '.$lead->display_name,
                'meeting_type' => 'phone', 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30), 'status' => 'scheduled']);
            $this->warn('No meeting found — created a test meeting 1 hour ago.');
        } elseif ($meeting->starts_at->isFuture()) {
            $length = $meeting->starts_at->diffInMinutes($meeting->ends_at ?? $meeting->starts_at->copy()->addMinutes(30));
            $meeting->update(['starts_at' => now()->subMinutes($length + 5), 'ends_at' => now()->subMinutes(5)]);
            $this->warn('Meeting was in the future — moved it to just now (testing only).');
        }

        $actor = $meeting->agent ?? $lead->agent;
        $meetings->recordOutcome($meeting, $actor, 'completed', $this->option('outcome'), null);
        $this->info("Meeting #{$meeting->id} marked Completed.");

        $proposal = $lead->proposals()->latest()->first();
        $this->line($this->leadLine($lead));
        if ($proposal) {
            $this->info("Proposal {$proposal->number} ({$proposal->money($proposal->total)}) — status: {$proposal->status}");
            $this->line('PDF: '.route('proposals.pdf', $proposal));
            $this->line('Next: customer accepts →  php artisan crm:accept-proposal '.$lead->id);
        } else {
            $this->warn('No proposal was created — check the lead timeline (automation off, or no Service Package matched).');
        }

        return self::SUCCESS;
    }
}
