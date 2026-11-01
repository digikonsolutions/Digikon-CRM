<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Key/value settings (Meta, WhatsApp, distribution, branding)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('company')->nullable();
            $table->string('city')->nullable();
            $table->string('service')->nullable();
            $table->string('budget')->nullable();

            $table->string('source')->default('manual'); // meta, website, whatsapp, referral, manual
            $table->string('campaign_name')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('form_name')->nullable();
            $table->string('meta_lead_id')->nullable()->unique();
            $table->string('meta_form_id')->nullable();
            $table->string('meta_page_id')->nullable();

            $table->string('status')->default('new'); // new, contacted, qualified, proposal, won, lost
            $table->string('priority')->default('normal'); // low, normal, high
            $table->string('lost_reason')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->boolean('followups_paused')->default(false);
            $table->boolean('whatsapp_opt_out')->default(false);
            $table->text('notes')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // created, assigned, status, note, whatsapp, email, proposal
            $table->text('description');
            $table->timestamps();
        });

        // Follow-up sequence steps (Day 0 instant, Day 1, Day 3 ...)
        Schema::create('followup_steps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('delay_minutes')->default(0); // after lead created
            $table->string('message_type')->default('template'); // template | text
            $table->string('template_name')->nullable();          // approved WhatsApp template
            $table->string('template_language')->default('en');
            $table->text('message')->nullable();                  // text body / template variables
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lead_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('followup_step_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending'); // pending, sent, failed, cancelled
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // out | in
            $table->string('phone');
            $table->text('body')->nullable();
            $table->string('wa_message_id')->nullable()->index();
            $table->string('status')->default('queued'); // queued, sent, delivered, read, failed, received
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template')->default('modern'); // modern | classic | minimal
            $table->string('title');
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->string('client_company')->nullable();
            $table->text('introduction')->nullable();
            $table->json('items')->nullable(); // [{description, qty, price}]
            $table->string('currency')->default('PKR');
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status')->default('draft'); // draft, sent, accepted, rejected
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('lead_followups');
        Schema::dropIfExists('followup_steps');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('settings');
    }
};
