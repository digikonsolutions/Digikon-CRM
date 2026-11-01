<?php

use App\Jobs\AnalyzeLeadConversationJob;
use App\Models\Lead;
use App\Services\Ai\AiClientFactory;
use App\Services\CrmAutomation;
use App\Services\FollowupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Server cron (one line):  * * * * * cd /path/to/crm && php artisan schedule:run >> /dev/null 2>&1
| Local:                   php artisan schedule:work
*/

Artisan::command('crm:send-followups', function (FollowupService $followups) {
    $result = $followups->sendDue();
    $this->info("Sent: {$result['sent']} | Failed: {$result['failed']} | Skipped: {$result['skipped']}");
})->purpose('Send due WhatsApp follow-up messages');

Artisan::command('crm:meeting-reminders', function (CrmAutomation $automation) {
    $this->info('Reminders sent: '.$automation->meetingReminders().' | Missed meetings flagged: '.$automation->missedMeetings());
})->purpose('Remind agents about upcoming meetings and flag meetings without an outcome');

Artisan::command('crm:overdue-followups', function (CrmAutomation $automation) {
    $this->info('Overdue follow-up alerts: '.$automation->overdueFollowUps());
})->purpose('Notify agents about overdue manual follow-ups');

Artisan::command('crm:snapshot-reports {--date= : Report date (default: yesterday)}', function (CrmAutomation $automation) {
    $result = $automation->snapshotReports($this->option('date'));
    $this->info('Snapshots saved: '.$result['saved'].' | Daily report notifications: '.$result['notified']);
})->purpose('Save daily / weekly / monthly report snapshots (idempotent)');

Artisan::command('crm:analyze-conversations {--limit=50} {--lead=}', function () {
    if (! AiClientFactory::isConfigured()) {
        $this->error('AI is not configured (Settings → AI Analysis).');

        return 1;
    }
    $query = Lead::query()->whereHas('messages', fn ($q) => $q->where('direction', 'in'))->open();
    if ($this->option('lead')) {
        $query->whereKey($this->option('lead'));
    }
    $count = 0;
    foreach ($query->latest('last_reply_at')->limit((int) $this->option('limit'))->get() as $lead) {
        AnalyzeLeadConversationJob::dispatch($lead->id, 'backfill');
        $count++;
    }
    $this->info("{$count} lead(s) queued for AI analysis (leads without new messages are skipped by the job).");
})->purpose('Queue AI WhatsApp analysis for existing leads');

// Testing helper: "php artisan crm:fake-lead" creates a sample lead (distribution + follow-ups run like a real Meta lead)
Artisan::command('crm:fake-lead {phone=03001234567} {--name=Test Customer}', function (App\Services\LeadService $leads) {
    $lead = $leads->create([
        'name' => $this->option('name'),
        'phone' => $this->argument('phone'),
        'source' => 'meta',
        'campaign_name' => 'Test Campaign',
        'service' => 'Website Development',
    ]);
    $this->info("Lead #{$lead->id} ".($lead->wasRecentlyCreated ? 'created' : 'already existed (duplicate policy)').', assigned to: '.($lead->agent?->name ?? 'nobody').', follow-ups: '.$lead->followups()->count());
})->purpose('Create a sample lead for testing');

// ---------------- Schedule ----------------
Schedule::command('crm:send-followups')->everyMinute()->withoutOverlapping();
Schedule::command('crm:meeting-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('crm:overdue-followups')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('crm:snapshot-reports')->dailyAt('00:20')->timezone(config('app.timezone'))->withoutOverlapping();

// Shared hosting: process queued jobs (Meta leads, notifications, AI analysis) from the scheduler.
// Disable with CRM_SCHEDULER_RUNS_QUEUE=false when a Supervisor queue worker is running.
if (config('crm.scheduler_runs_queue')) {
    Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=55 --sleep=1')->everyMinute()->withoutOverlapping(5);
}
