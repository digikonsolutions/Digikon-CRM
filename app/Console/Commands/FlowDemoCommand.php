<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\FollowupService;
use Illuminate\Console\Command;

/** End-to-end test of the whole sales flow (everything except fetching leads from Meta). */
class FlowDemoCommand extends Command
{
    use TestingHelpers;

    protected $signature = 'crm:flow-demo {--phone= : Use this phone (default random)} {--local-ai : Force the offline analyser}';

    protected $description = 'TESTING: run the complete flow — landing lead → hot → follow-ups → WhatsApp chat → AI → meeting → proposal → invoice';

    public function handle(FollowupService $followups): int
    {
        $this->runJobsNow();
        $phone = $this->option('phone') ?: '0300'.random_int(1000000, 9999999);
        $step = function (string $t) {
            $this->newLine();
            $this->line("<fg=green;options=bold>▶ {$t}</>");
        };

        $step('1. Landing-page lead arrives');
        $this->call('crm:dummy-leads', ['count' => 1, '--service' => 'E-commerce Store']);
        $lead = Lead::latest('id')->first();
        if ($this->option('phone')) {
            $lead->update(['phone' => \App\Services\PhoneNumber::normalize($phone)]);
        }
        $phone = $lead->phone;

        $step('2. Automatic follow-ups (WhatsApp + email) that are due now');
        $r = $followups->sendDue();
        $this->line("Sent: {$r['sent']} · Failed: {$r['failed']} (WhatsApp fails until the WhatsApp API is configured) · Skipped: {$r['skipped']}");
        foreach ($lead->followups()->with('step')->get() as $f) {
            $this->line(' - '.$f->step?->name.' ['.$f->step?->channel.'] → '.$f->status.($f->error ? ' ('.$f->error.')' : ''));
        }

        $step('3. Customer chats on WhatsApp → AI segregates the lead and books the meeting');
        $this->call('crm:simulate-whatsapp', ['phone' => $phone, 'message' => [
            'Assalam o alaikum, mujhe apne clothing brand ke liye online store chahiye',
            'Approx 150 products hain, budget 150k tak hai aur next month launch karna hai',
            'Kal 4 baje call kar lein, proposal bhi bhej dein please',
        ], '--local-ai' => (bool) $this->option('local-ai')]);

        $step('4. Meeting success → proposal PDF sent automatically');
        $this->call('crm:meeting-done', ['lead' => (string) $lead->id]);

        $step('5. Customer accepts → invoice PDF sent automatically');
        $this->call('crm:accept-proposal', ['lead' => (string) $lead->id]);

        $step('Timeline of lead #'.$lead->id);
        foreach ($lead->activities()->oldest('id')->get() as $a) {
            $this->line(' · '.$a->occurred_at->format('H:i').' ['.$a->type.'] '.str_replace("\n", ' ', \Illuminate\Support\Str::limit($a->description, 140)));
        }
        $this->newLine();
        $this->info('Done. Open the lead in the CRM: '.route('leads.show', $lead));
        $this->line('Emails are written to storage/logs/laravel.log while MAIL_MAILER=log.');

        return self::SUCCESS;
    }
}
