<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\LeadQualificationService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SimulateWhatsappCommand extends Command
{
    use TestingHelpers;

    protected $signature = 'crm:simulate-whatsapp
        {phone : Customer phone (existing lead, or a new number → new WhatsApp lead)}
        {message* : One or more messages (each in quotes)}
        {--name=WhatsApp Customer : Profile name for a new number}
        {--no-analyze : Only store the messages, do not run the AI}
        {--local-ai : Use the offline analyser even if an API key is set}';

    protected $description = 'TESTING: pretend the customer sent WhatsApp message(s) — goes through the real webhook, then runs the AI';

    public function handle(LeadQualificationService $qualification): int
    {
        $this->runJobsNow();
        $digits = preg_replace('/\D+/', '', $this->argument('phone'));
        $from = str_starts_with($digits, '0') ? (Setting::get('default_country_code', '92').substr($digits, 1)) : $digits;

        foreach ($this->argument('message') as $text) {
            $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'contacts' => [['profile' => ['name' => $this->option('name')]]],
                'messages' => [['from' => $from, 'id' => 'wamid.TEST'.Str::random(16), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]]];
            $body = json_encode($payload);
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            if ($secret = Setting::get('meta_app_secret')) {
                $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
            }
            $response = app()->handle(Request::create('/webhooks/whatsapp', 'POST', [], [], [], $server, $body));
            $this->line(($response->getStatusCode() === 200 ? '<info>✓</info>' : '<error>✗ '.$response->getStatusCode().'</error>').' Customer: '.$text);
        }

        $lead = $this->findLead($from);
        if (! $lead) {
            $this->error('Lead not found after the webhook.');

            return self::FAILURE;
        }

        if (! $this->option('no-analyze')) {
            $ai = $this->ensureAi((bool) $this->option('local-ai'));
            $this->line("AI: {$ai}");
            $analysis = $qualification->analyze($lead, null, 'manual');
            if ($analysis->status !== 'completed') {
                $this->error('AI failed: '.$analysis->error);
            } else {
                $this->info('AI summary: '.$analysis->summary);
                $this->table(['Field', 'Value'], [
                    ['Temperature', $analysis->temperature ?? '—'], ['Qualification', $analysis->qualification ?? '—'],
                    ['Service', $analysis->service_interest ?? '—'], ['Budget', $analysis->budget ?? '—'], ['Timeline', $analysis->timeline ?? '—'],
                    ['Agreed meeting', $analysis->meeting_at?->format('D d M Y, h:i A') ?? '—'],
                    ['Meeting booked', $analysis->meeting_id ? 'yes (#'.$analysis->meeting_id.')' : 'no'],
                    ['Review', $analysis->review_label],
                ]);
            }
        }

        $this->line($this->leadLine($lead));
        if ($meeting = $lead->meetings()->active()->latest('id')->first()) {
            $this->line("Meeting #{$meeting->id}: ".$meeting->starts_at->format('D d M, h:i A').' · confirmation '.($meeting->confirmation_sent_at ? 'sent' : 'not sent'));
            $this->line('Next: after the meeting →  php artisan crm:meeting-done '.$lead->id.' --outcome="Wants proposal"');
        }

        return self::SUCCESS;
    }
}
