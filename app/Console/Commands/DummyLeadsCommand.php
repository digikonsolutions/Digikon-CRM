<?php

namespace App\Console\Commands;

use App\Services\LeadIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DummyLeadsCommand extends Command
{
    use TestingHelpers;

    protected $signature = 'crm:dummy-leads
        {count=5 : How many leads}
        {--service= : Force a service (e.g. "Website Development")}
        {--no-email : Create leads without email}';

    protected $description = 'TESTING: create dummy landing-page leads through the real pipeline (hot, assignment, follow-ups)';

    protected const NAMES = ['Ahmed Raza', 'Fatima Noor', 'Usman Tariq', 'Hina Malik', 'Bilal Ahmed', 'Ayesha Khan', 'Zain Ali', 'Sana Javed',
        'Hamza Iqbal', 'Mahnoor Shah', 'Ali Hassan', 'Iqra Saleem', 'Faisal Qureshi', 'Rabia Anwar', 'Saad Butt', 'Mehwish Akram'];

    protected const SERVICES = ['Website Development', 'E-commerce Store', 'SEO', 'Social Media Marketing', 'Mobile App', 'Branding / Logo'];

    protected const CITIES = ['Lahore', 'Karachi', 'Islamabad', 'Rawalpindi', 'Faisalabad', 'Multan'];

    public function handle(LeadIngestionService $ingestion): int
    {
        $this->runJobsNow();
        $rows = [];

        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            $name = self::NAMES[array_rand(self::NAMES)];
            $phone = '03'.random_int(0, 4).random_int(0, 9).random_int(1000000, 9999999);
            $service = $this->option('service') ?: self::SERVICES[array_rand(self::SERVICES)];
            $email = $this->option('no-email') ? null : Str::slug($name, '.').random_int(10, 999).'@example.com';

            $result = $ingestion->ingestLandingPage([
                'name' => $name, 'phone' => $phone, 'email' => $email, 'city' => self::CITIES[array_rand(self::CITIES)],
                'interested_service' => $service, 'message' => 'Dummy lead created from the terminal for testing.',
                'utm_source' => 'test', 'utm_medium' => 'terminal', 'utm_campaign' => 'dummy-leads',
            ], 'dummy-'.Str::uuid(), ['utm_source' => 'test', 'utm_medium' => 'terminal', 'utm_campaign' => 'dummy-leads', 'landing_page_url' => 'https://example.com/landing'], '127.0.0.1', 'artisan');

            $lead = $result['lead'];
            $rows[] = [$lead->id, $lead->name, $lead->phone, $lead->email ?? '—', $service, $lead->temperature ?? '—', $lead->agent?->name ?? 'unassigned',
                $lead->followups()->count().' scheduled', $result['event']->status];
        }

        $this->table(['ID', 'Name', 'Phone', 'Email', 'Service', 'Temp', 'Owner', 'Follow-ups', 'Ingestion'], $rows);
        $this->line('Next: simulate the customer on WhatsApp →  php artisan crm:simulate-whatsapp '.end($rows)[2].' "Kal 4 baje call kar lein, budget 80k hai"');

        return self::SUCCESS;
    }
}
