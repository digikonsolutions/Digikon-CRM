<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\FollowUpTaskService;
use App\Services\LeadActivityService;
use App\Services\LeadAssignmentService;
use App\Services\LeadStatusService;
use Illuminate\Database\Seeder;

/**
 * LOCAL TESTING ONLY — fills the CRM with sample leads, chats, calls, meetings and an AI analysis.
 *   php artisan db:seed --class=DemoDataSeeder
 * Never run this on the live CRM.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDataSeeder refuses to run in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);
        Setting::set('auto_followups', '0');

        $manager = User::where('email', 'manager@crm.test')->first();
        $ali = User::where('email', 'ali@crm.test')->first();
        $sara = User::where('email', 'sara@crm.test')->first();
        $assign = app(LeadAssignmentService::class);
        $activities = app(LeadActivityService::class);
        $statuses = app(LeadStatusService::class);
        $tasks = app(FollowUpTaskService::class);

        $people = [
            ['Ahmed Raza', '03001234501', 'meta', 'Website Development', 'Oct — Website Leads', $ali],
            ['Fatima Noor', '03211234502', 'website', 'SEO', null, $sara],
            ['Usman Tariq', '03331234503', 'whatsapp', 'E-commerce Store', null, $ali],
            ['Hina Malik', '03451234504', 'meta', 'Social Media Marketing', 'Oct — SMM Leads', $sara],
            ['Bilal Ahmed', '03011234505', 'referral', 'Mobile App', null, null],
            ['Ayesha Khan', '03121234506', 'meta', 'Website Development', 'Oct — Website Leads', null],
            ['Zain Ali', '03091234507', 'website', 'Branding', null, $ali],
            ['Sana Javed', '03151234508', 'meta', 'SEO', 'Oct — SEO Leads', $sara],
        ];

        $leads = [];
        foreach ($people as $i => [$name, $phone, $source, $service, $campaign, $owner]) {
            $lead = Lead::create([
                'name' => $name, 'phone' => '92'.substr($phone, 1), 'source' => $source, 'service' => $service,
                'campaign_name' => $campaign, 'status' => 'new', 'priority' => $i === 0 ? 'high' : 'normal',
                'utm_source' => $source === 'website' ? 'google' : null, 'utm_campaign' => $source === 'website' ? 'seo-oct' : null,
                'created_at' => now()->subDays(7 - min($i, 6))->setTime(10 + $i, 15),
            ]);
            $lead->log('created', 'Lead received via '.(Lead::SOURCES[$source] ?? $source));
            if ($owner) {
                $assign->assign($lead, $owner, $manager->id, null, 'manual', notify: false);
            }
            $leads[] = $lead->fresh();
        }

        // ---- WhatsApp chat (Usman) + AI analysis ----
        $usman = $leads[2];
        $chat = [
            ['in', 'Assalam o alaikum, mujhe apne kapron ke brand ke liye online store chahiye', 180],
            ['out', 'Walaikum salam Usman bhai! Zaroor. Kitne products hain aur budget kya socha hai?', 175],
            ['in', 'Approx 200 products hain. Budget 150k tak hai', 170],
            ['in', 'Aur Eid se pehle live karna hai, matlab 3-4 weeks mein', 168],
            ['out', 'Perfect, main aaj proposal bhej deta hoon. Kal call kar lein?', 160],
            ['in', 'Ji kal 4 baje call kar lein. Proposal bhi bhej dein please', 150],
        ];
        $ids = [];
        foreach ($chat as [$dir, $body, $minsAgo]) {
            $ids[] = WhatsappMessage::create([
                'lead_id' => $usman->id, 'direction' => $dir, 'phone' => $usman->phone, 'body' => $body,
                'status' => $dir === 'in' ? 'received' : 'read', 'user_id' => $dir === 'out' ? $ali->id : null,
                'created_at' => now()->subMinutes($minsAgo), 'updated_at' => now()->subMinutes($minsAgo),
            ])->id;
        }
        $usman->update(['last_reply_at' => now()->subMinutes(150)]);
        $analysis = LeadAnalysis::create([
            'lead_id' => $usman->id, 'trigger' => 'auto', 'status' => 'completed', 'provider' => 'demo', 'model' => 'demo',
            'messages_analyzed' => count($ids), 'last_message_id' => max($ids),
            'summary' => 'Usman wants an online store for his clothing brand (~200 products), budget up to 150k PKR, must go live before Eid (3–4 weeks). He asked for a proposal and agreed to a call tomorrow at 4 PM.',
            'service_interest' => 'E-commerce store', 'budget' => '150k PKR', 'timeline' => '3–4 weeks (before Eid)', 'industry' => 'Clothing / fashion',
            'buying_intent' => 'high', 'temperature' => 'hot', 'qualification' => 'qualified', 'recommended_status' => 'proposal',
            'recommended_actions' => ['Send the e-commerce proposal today', 'Call tomorrow at 4 PM as agreed', 'Share 2–3 fashion store portfolio links'],
            'follow_up_in_days' => 1, 'tags' => ['E-commerce', 'Urgent'], 'confidence' => 0.88, 'language' => 'Roman Urdu', 'review_status' => 'pending',
            'evidence' => [
                'service_interest' => [['message_id' => $ids[0], 'quote' => 'online store chahiye']],
                'budget' => [['message_id' => $ids[2], 'quote' => 'Budget 150k tak hai']],
                'timeline' => [['message_id' => $ids[3], 'quote' => 'Eid se pehle live karna hai, matlab 3-4 weeks mein']],
                'industry' => [['message_id' => $ids[0], 'quote' => 'kapron ke brand']],
                'buying_intent' => [['message_id' => $ids[5], 'quote' => 'Proposal bhi bhej dein please']],
                'temperature' => [['message_id' => $ids[5], 'quote' => 'Proposal bhi bhej dein please']],
            ],
        ]);
        app(\App\Services\LeadQualificationService::class)->applySafe($analysis);

        // ---- Calls, follow-ups, meetings ----
        auth()->login($ali);
        $activities->log($leads[0], $ali, ['type' => 'call', 'outcome' => 'interested', 'notes' => 'Needs a 5-page company website. Asked for portfolio.', 'duration_seconds' => 340, 'occurred_at' => now()->subHours(3), 'next_follow_up_at' => now()->addDay()->setTime(11, 0)]);
        $activities->log($leads[0], $ali, ['type' => 'call', 'outcome' => 'no_answer', 'occurred_at' => now()->subHours(1)]);
        $activities->log($leads[6], $ali, ['type' => 'call', 'outcome' => 'callback_requested', 'notes' => 'In a meeting, call after 5', 'duration_seconds' => 45, 'occurred_at' => now()->subHours(2)]);
        $tasks->schedule($leads[6], now()->subHours(1), $ali, 'Call back after 5 PM');
        $activities->log($usman, $ali, ['type' => 'whatsapp', 'notes' => 'Sent portfolio links', 'occurred_at' => now()->subMinutes(140)]);
        $statuses->change($leads[0]->fresh(), 'temperature', 'warm', $ali->id);

        auth()->login($sara);
        $activities->log($leads[1], $sara, ['type' => 'call', 'outcome' => 'meeting_booked', 'notes' => 'Wants SEO audit discussion', 'duration_seconds' => 420, 'occurred_at' => now()->subDay()->setTime(15, 0)]);
        $activities->log($leads[3], $sara, ['type' => 'email', 'notes' => 'Sent SMM packages PDF', 'occurred_at' => now()->subHours(5)]);
        $statuses->change($leads[3]->fresh(), 'status', 'proposal', $sara->id);
        $statuses->change($leads[7]->fresh(), 'status', 'won', $sara->id, 'manual', 'Signed 6-month SEO');
        $statuses->change($leads[1]->fresh(), 'status', 'qualified', $sara->id);

        Meeting::create(['lead_id' => $leads[1]->id, 'organizer_id' => $sara->id, 'assigned_agent_id' => $sara->id, 'title' => 'SEO audit walkthrough', 'meeting_type' => 'online',
            'starts_at' => now()->addDay()->setTime(14, 0), 'ends_at' => now()->addDay()->setTime(14, 45), 'meeting_link' => 'https://meet.google.com/abc-defg-hij', 'status' => 'scheduled']);
        Meeting::create(['lead_id' => $usman->id, 'organizer_id' => $ali->id, 'assigned_agent_id' => $ali->id, 'title' => 'Call with Usman — store scope', 'meeting_type' => 'phone',
            'starts_at' => now()->addDay()->setTime(16, 0), 'ends_at' => now()->addDay()->setTime(16, 30), 'status' => 'scheduled']);
        Meeting::create(['lead_id' => $leads[3]->id, 'organizer_id' => $sara->id, 'assigned_agent_id' => $sara->id, 'title' => 'SMM strategy meeting', 'meeting_type' => 'in_person',
            'starts_at' => now()->subDays(2)->setTime(12, 0), 'ends_at' => now()->subDays(2)->setTime(13, 0), 'location' => 'Client office, Gulberg', 'status' => 'completed', 'outcome' => 'Liked package B', 'completed_at' => now()->subDays(2)]);
        Meeting::create(['lead_id' => $leads[0]->id, 'organizer_id' => $ali->id, 'assigned_agent_id' => $ali->id, 'title' => 'Website requirements', 'meeting_type' => 'online',
            'starts_at' => now()->subHours(4), 'ends_at' => now()->subHours(3)->subMinutes(30), 'status' => 'scheduled']);
        auth()->logout();

        $leads[3]->tags()->syncWithoutDetaching([Tag::findOrCreateByName('Social Media')->id => ['source' => 'manual']]);
        $this->command?->info('Demo data created. Logins: admin@crm.test / manager@crm.test / ali@crm.test (password: password)');
    }
}
