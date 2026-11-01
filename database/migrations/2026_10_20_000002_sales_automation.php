<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales automation (additive only):
 *  - follow-up steps can be WhatsApp or Email
 *  - AI analysis can detect an agreed meeting time
 *  - service packages (price list used for automatic proposals)
 *  - invoices (PDF, emailed automatically)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followup_steps', function (Blueprint $table) {
            $table->string('channel', 10)->default('whatsapp')->after('name'); // whatsapp | email
            $table->string('email_subject')->nullable()->after('message');
        });

        Schema::table('lead_analyses', function (Blueprint $table) {
            $table->timestamp('meeting_at')->nullable()->after('follow_up_in_days');
            $table->string('meeting_type', 15)->nullable()->after('meeting_at');
            $table->foreignId('meeting_id')->nullable()->after('meeting_type')->constrained('meetings')->nullOnDelete();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('status'); // manual | ai
            $table->timestamp('confirmation_sent_at')->nullable()->after('reminder_sent_at');
        });

        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('keywords', 500)->nullable();   // comma separated, matched against lead service / AI service interest
            $table->string('title')->nullable();            // proposal title
            $table->text('introduction')->nullable();
            $table->json('items');                          // [{description, qty, price}]
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('status'); // manual | auto
            $table->foreignId('meeting_id')->nullable()->after('lead_id')->constrained('meetings')->nullOnDelete();
            $table->foreignId('service_package_id')->nullable()->after('meeting_id')->constrained('service_packages')->nullOnDelete();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('manual'); // manual | auto
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('client_company')->nullable();
            $table->json('items');
            $table->string('currency', 10)->default('PKR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('advance_percent', 5, 2)->default(100); // % of total payable now
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->string('status', 15)->default('draft'); // draft | sent | paid | cancelled
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'issue_date']);
        });

        // Default email follow-ups (only when no email step exists yet). WhatsApp steps are untouched.
        if (! DB::table('followup_steps')->where('channel', 'email')->exists()) {
            $now = now();
            DB::table('followup_steps')->insert([
                ['name' => 'Instant thank-you email', 'channel' => 'email', 'delay_minutes' => 0, 'message_type' => 'text', 'template_name' => null, 'template_language' => 'en',
                    'email_subject' => 'Thank you for contacting {company}',
                    'message' => "Dear {name},\n\nThank you for your interest in {service}. {agent} from our team will contact you shortly on WhatsApp / phone.\n\nIf you prefer, simply reply to this email with a good time to talk.\n\nRegards,\n{agent}\n{company}",
                    'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Day 2 email follow-up', 'channel' => 'email', 'delay_minutes' => 2880, 'message_type' => 'text', 'template_name' => null, 'template_language' => 'en',
                    'email_subject' => 'Shall we schedule a quick call about {service}?',
                    'message' => "Dear {name},\n\nJust following up on your enquiry about {service}. We would love to understand your requirements in a quick 15-minute call.\n\nReply with a suitable day and time, or WhatsApp {agent} on {agent_phone}.\n\nRegards,\n{agent}\n{company}",
                    'sort_order' => 11, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::table('proposals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('service_package_id');
            $t->dropConstrainedForeignId('meeting_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('service_packages');
        Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn(['source', 'confirmation_sent_at']));
        Schema::table('lead_analyses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('meeting_id');
            $t->dropColumn(['meeting_at', 'meeting_type']);
        });
        DB::table('followup_steps')->where('channel', 'email')->delete();
        Schema::table('followup_steps', fn (Blueprint $t) => $t->dropColumn(['channel', 'email_subject']));
    }
};
