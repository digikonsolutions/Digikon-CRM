<?php

namespace Database\Seeders;

use App\Models\FollowupStep;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \Illuminate\Support\Facades\Cache::forget('crm_settings');
        User::firstOrCreate(['email' => 'admin@crm.test'], [
            'name' => 'Admin', 'password' => 'password', 'role' => 'admin',
            'is_active' => true, 'receives_leads' => false,
        ]);

        foreach ([['Ali (Sales)', 'ali@crm.test'], ['Sara (Sales)', 'sara@crm.test']] as [$name, $email]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name, 'password' => 'password', 'role' => 'agent',
                'is_active' => true, 'receives_leads' => true,
            ]);
        }

        User::firstOrCreate(['email' => 'manager@crm.test'], [
            'name' => 'Sales Manager', 'password' => 'password', 'role' => 'manager',
            'is_active' => true, 'receives_leads' => false,
        ]);

        foreach (['Website', 'E-commerce', 'SEO', 'Social Media', 'Mobile App', 'Price sensitive', 'Urgent'] as $tag) {
            \App\Models\Tag::findOrCreateByName($tag);
        }

        $defaults = [
            'company_name' => 'Digikon Solutions',
            'default_country_code' => '92',
            'currency' => 'PKR',
            'distribution_method' => 'round_robin',
            'graph_version' => 'v26.0',
            'webhook_verify_token' => 'digikon_verify_'.substr(md5((string) microtime(true)), 0, 10),
            'auto_followups' => '1',
            'primary_color' => '#03171D',
            'accent_color' => '#81D870',
            'default_template' => 'modern',
            'proposal_prefix' => 'PRP',
            'default_intro' => "Thank you for the opportunity to submit this proposal. Based on our discussion, we have outlined the scope of work, deliverables and investment below.",
            'default_terms' => "1. 50% advance payment, 50% on delivery.\n2. Timeline starts after advance payment and receiving required content.\n3. This proposal is valid until the date mentioned above.",
            'proposal_footer' => 'Thank you for considering us. We look forward to working with you.',
            'meeting_reminder_minutes' => '30',
            'ai_provider' => 'anthropic',
            'ai_auto_analyze' => '0',
            'ai_auto_apply' => '1',
            'ai_min_confidence' => '0.7',
            'ai_debounce_minutes' => '10',
            // Sales automation
            'auto_landing_hot' => '1',
            'ai_auto_book_meetings' => '1',
            'auto_meeting_confirmation' => '1',
            'auto_meeting_minutes' => '30',
            'auto_proposal_after_meeting' => '1',
            'send_documents_whatsapp' => '1',
            'auto_invoice_trigger' => 'on_accept',
            'invoice_prefix' => 'INV',
            'invoice_advance_percent' => '50',
            'invoice_due_days' => '7',
            'invoice_payment_instructions' => "Bank: Your Bank Name\nAccount title: Digikon Solutions\nIBAN: PK00 XXXX 0000 0000 0000 0000\n(Update these details in Settings → Automation)",
        ];
        foreach ($defaults as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::set($key, $value);
            }
        }

        if (\App\Models\ServicePackage::count() === 0) {
            // SAMPLE prices — edit them in Management → Service Packages before going live.
            $packages = [
                ['Website Development', 'website,web site,webpage,landing page,company website,wordpress', 'Website Development Proposal', [
                    ['Custom responsive website design (up to 6 pages)', 1, 60000], ['Development, contact forms & WhatsApp button', 1, 25000],
                    ['Basic on-page SEO + Google Search Console setup', 1, 10000], ['Domain & hosting setup (1 year hosting)', 1, 15000]], true],
                ['E-commerce Store', 'ecommerce,e-commerce,online store,online shop,shopify,woocommerce,store', 'E-commerce Store Proposal', [
                    ['E-commerce store design & development', 1, 120000], ['Product upload (up to 200 products)', 1, 20000],
                    ['Payment gateway + COD + courier integration', 1, 20000], ['Training & 1 month support', 1, 10000]], false],
                ['SEO', 'seo,google ranking,search engine', 'SEO Proposal (3 months)', [
                    ['SEO audit & keyword research', 1, 15000], ['Monthly on-page + off-page SEO', 3, 30000]], false],
                ['Social Media Marketing', 'social media,smm,facebook ads,instagram,meta ads,marketing', 'Social Media Marketing Proposal', [
                    ['Monthly content plan + 12 designed posts', 1, 35000], ['Ads management (ad budget paid separately)', 1, 20000]], false],
                ['Mobile App', 'mobile app,android,ios,app', 'Mobile App Development Proposal', [
                    ['UI/UX design', 1, 80000], ['Android + iOS app development', 1, 350000], ['Admin panel & API', 1, 120000]], false],
                ['Branding / Logo', 'logo,branding,brand identity', 'Branding Proposal', [
                    ['Logo design (3 concepts, unlimited revisions)', 1, 25000], ['Brand guide + social media kit', 1, 15000]], false],
            ];
            foreach ($packages as $i => [$name, $keywords, $title, $items, $default]) {
                \App\Models\ServicePackage::create([
                    'name' => $name, 'keywords' => $keywords, 'title' => $title, 'sort_order' => $i,
                    'items' => array_map(fn ($it) => ['description' => $it[0], 'qty' => $it[1], 'price' => $it[2]], $items),
                    'is_default' => $default, 'is_active' => true,
                ]);
            }
        }

        if (FollowupStep::where('channel', 'whatsapp')->count() === 0) {
            FollowupStep::insert([
                ['name' => 'Instant welcome', 'delay_minutes' => 0, 'message_type' => 'template', 'template_name' => 'lead_welcome',
                    'template_language' => 'en', 'message' => '{name}|{company}', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Day 1 reminder', 'delay_minutes' => 1440, 'message_type' => 'template', 'template_name' => 'lead_followup_1',
                    'template_language' => 'en', 'message' => '{name}|{service}', 'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Day 3 last follow-up', 'delay_minutes' => 4320, 'message_type' => 'template', 'template_name' => 'lead_followup_2',
                    'template_language' => 'en', 'message' => '{name}|{agent}', 'sort_order' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }
}
