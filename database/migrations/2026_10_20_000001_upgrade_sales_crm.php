<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM upgrade — ADDITIVE ONLY.
 * Reuses existing tables (users, leads, lead_activities, whatsapp_messages, proposals)
 * and only adds missing columns / new tables. No existing data is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------- users ----------------
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notification_preferences')) {
                $table->json('notification_preferences')->nullable()->after('last_lead_assigned_at');
            }
        });

        // ---------------- leads ----------------
        Schema::table('leads', function (Blueprint $table) {
            $add = fn (string $col) => ! Schema::hasColumn('leads', $col);

            if ($add('assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            }
            if ($add('temperature')) {
                $table->string('temperature', 10)->nullable()->index();          // hot | warm | cold
                $table->string('temperature_source', 10)->nullable();            // manual | ai
            }
            if ($add('qualification')) {
                $table->string('qualification', 20)->default('pending')->index(); // pending | qualified | needs_info | not_qualified
                $table->string('qualification_source', 10)->nullable();          // manual | ai
            }
            if ($add('industry')) {
                $table->string('industry')->nullable();
                $table->string('timeline')->nullable();
                $table->string('buying_intent', 10)->nullable();                 // high | medium | low | unknown
                $table->unsignedSmallInteger('score')->default(0)->index();      // rule-based lead score 0-100
            }
            if ($add('utm_source')) {
                $table->string('utm_source')->nullable();
                $table->string('utm_medium')->nullable();
                $table->string('utm_campaign')->nullable();
                $table->string('utm_content')->nullable();
                $table->string('landing_page_url', 1000)->nullable();
                $table->string('referrer', 1000)->nullable();
            }
            if ($add('meta_campaign_id')) {
                $table->string('meta_campaign_id')->nullable();
                $table->string('meta_adset_id')->nullable();
                $table->string('meta_ad_id')->nullable();
                $table->string('platform', 30)->nullable();                     // fb | ig
                $table->timestamp('submitted_at')->nullable();
            }
            if ($add('first_contacted_at')) {
                $table->timestamp('first_contacted_at')->nullable();
            }
            $table->index('status');
            $table->index('source');
            $table->index('created_at');
            $table->index(['assigned_to', 'status']);
            $table->index('next_follow_up_at');
        });

        // ---------------- lead assignment history ----------------
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();     // the owner
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unassigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 20)->default('manual');   // manual | bulk | round_robin | least_leads | creator | import
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'unassigned_at']);
            $table->index(['user_id', 'assigned_at']);
        });

        // ---------------- status / segmentation history ----------------
        Schema::create('lead_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who changed it (null = system/AI)
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); // lead owner at that moment (for reports)
            $table->string('field', 20);           // status | temperature | qualification
            $table->string('from_value', 30)->nullable();
            $table->string('to_value', 30)->nullable();
            $table->string('source', 10)->default('manual'); // manual | ai | system
            $table->string('reason', 500)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['field', 'to_value', 'changed_at']);
            $table->index(['lead_id', 'field']);
        });

        // ---------------- sales activities (extend existing table) ----------------
        Schema::table('lead_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('lead_activities', 'outcome')) {
                $table->string('outcome', 30)->nullable()->after('type');
                $table->unsignedInteger('duration_seconds')->nullable()->after('description');
                $table->timestamp('occurred_at')->nullable()->after('duration_seconds');
                $table->timestamp('next_follow_up_at')->nullable()->after('occurred_at');
                // false = typed in by an agent (manual log). true = confirmed by an integration (e.g. future telephony).
                $table->boolean('is_verified')->default(false)->after('next_follow_up_at');
                $table->boolean('is_manual')->default(false)->after('is_verified');
                $table->json('meta')->nullable()->after('is_manual');
            }
            $table->index(['type', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });

        // ---------------- manual follow-up tasks ----------------
        Schema::create('lead_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // owner
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lead_activities')->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestamp('due_at')->index();
            $table->string('status', 15)->default('open')->index(); // open | done | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'due_at']);
        });

        // ---------------- meetings ----------------
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('meeting_type', 15)->default('online'); // online | phone | in_person
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 50)->default('Asia/Karachi');
            $table->string('location')->nullable();
            $table->string('meeting_link', 1000)->nullable();
            $table->string('status', 15)->default('scheduled'); // scheduled | rescheduled | completed | cancelled | no_show
            $table->string('outcome', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('reschedule_count')->default(0);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('missed_notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_agent_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        // ---------------- tags ----------------
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 7)->default('#1F6E85');
            $table->timestamps();
        });

        Schema::create('lead_tag', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('manual'); // manual | ai
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['lead_id', 'tag_id']);
        });

        // ---------------- AI analysis of WhatsApp chats (separate from message history) ----------------
        Schema::create('lead_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 20)->default('manual');   // manual | auto | backfill
            $table->string('status', 15)->default('completed'); // completed | failed | skipped
            $table->string('provider', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('messages_analyzed')->default(0);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->text('summary')->nullable();
            $table->string('service_interest')->nullable();
            $table->string('budget')->nullable();
            $table->string('timeline')->nullable();
            $table->string('industry')->nullable();
            $table->string('buying_intent', 10)->nullable();
            $table->string('temperature', 10)->nullable();
            $table->string('qualification', 20)->nullable();
            $table->string('recommended_status', 20)->nullable();
            $table->json('recommended_actions')->nullable();
            $table->unsignedSmallInteger('follow_up_in_days')->nullable();
            $table->json('tags')->nullable();
            $table->json('evidence')->nullable();        // field => [{message_id, quote}]
            $table->json('rejected_fields')->nullable(); // fields the AI returned without valid evidence
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('language', 30)->nullable();
            $table->text('error')->nullable();
            $table->string('review_status', 15)->default('pending'); // pending | approved | edited | rejected | auto_applied
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('applied_fields')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
            $table->index('review_status');
        });

        // ---------------- WhatsApp messages (extend existing table) ----------------
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_messages', 'message_type')) {
                $table->string('message_type', 20)->default('text')->after('direction');
                $table->timestamp('wa_timestamp')->nullable()->after('wa_message_id');
            }
            $table->index(['lead_id', 'created_at']);
        });

        // ---------------- lead source ingestion log (Meta, landing pages, WhatsApp) ----------------
        Schema::create('lead_source_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);                 // meta | landing_page
            $table->string('external_id', 191);           // leadgen_id / submission id
            $table->string('status', 20)->default('pending'); // pending | processing | processed | duplicate | failed | spam
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();          // sanitized — never contains tokens
            $table->json('attribution')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failure_notified_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index(['status', 'created_at']);
        });

        // ---------------- audit trail ----------------
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('auditable_type', 60)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });

        // ---------------- report snapshots (idempotent by unique key) ----------------
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('period', 10);        // daily | weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->string('scope_key', 30);     // team | user:5
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metrics');
            $table->timestamp('generated_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['period', 'period_start', 'scope_key']);
        });

        // ---------------- proposals: revenue date ----------------
        Schema::table('proposals', function (Blueprint $table) {
            if (! Schema::hasColumn('proposals', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('sent_at');
            }
        });

        // ---------------- in-app notifications (Laravel database channel) ----------------
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        $this->backfill();
    }

    /** Copy existing ownership / dates into the new history tables (non-destructive). */
    protected function backfill(): void
    {
        DB::table('lead_activities')->whereNull('occurred_at')->update(['occurred_at' => DB::raw('created_at')]);

        DB::table('proposals')->where('status', 'accepted')->whereNull('accepted_at')->update(['accepted_at' => DB::raw('updated_at')]);

        DB::table('leads')->whereNotNull('assigned_to')->orderBy('id')->chunkById(500, function ($leads) {
            $rows = [];
            foreach ($leads as $lead) {
                $rows[] = [
                    'lead_id' => $lead->id,
                    'user_id' => $lead->assigned_to,
                    'assigned_by' => null,
                    'method' => 'import',
                    'assigned_at' => $lead->assigned_at ?? $lead->created_at,
                    'reason' => 'Existing assignment before CRM upgrade',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('lead_assignments')->insert($rows);
        });
    }

    public function down(): void
    {
        // Only drops what this migration created. Existing CRM data stays intact.
        Schema::dropIfExists('report_snapshots');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('lead_source_events');
        Schema::dropIfExists('lead_analyses');
        Schema::dropIfExists('lead_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('lead_tasks');
        Schema::dropIfExists('lead_status_changes');
        Schema::dropIfExists('lead_assignments');

        Schema::table('proposals', fn (Blueprint $t) => $t->dropColumn('accepted_at'));
        // MySQL silently reuses composite indexes for foreign keys, so give each FK its own index first.
        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->index('lead_id', 'wa_msg_lead_fk_idx');
            $t->dropIndex(['lead_id', 'created_at']);
            $t->dropColumn(['message_type', 'wa_timestamp']);
        });
        Schema::table('lead_activities', function (Blueprint $t) {
            $t->index('user_id', 'lead_act_user_fk_idx');
            $t->dropIndex(['type', 'occurred_at']);
            $t->dropIndex(['user_id', 'occurred_at']);
            $t->dropColumn(['outcome', 'duration_seconds', 'occurred_at', 'next_follow_up_at', 'is_verified', 'is_manual', 'meta']);
        });
        Schema::table('leads', function (Blueprint $t) {
            $t->index('assigned_to', 'leads_assigned_to_fk_idx');
            $t->dropConstrainedForeignId('assigned_by');
            $t->dropIndex(['status']);
            $t->dropIndex(['source']);
            $t->dropIndex(['created_at']);
            $t->dropIndex(['assigned_to', 'status']);
            $t->dropIndex(['next_follow_up_at']);
            $t->dropIndex(['temperature']);
            $t->dropIndex(['qualification']);
            $t->dropIndex(['score']);
            $t->dropColumn([
                'temperature', 'temperature_source', 'qualification', 'qualification_source',
                'industry', 'timeline', 'buying_intent', 'score',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'landing_page_url', 'referrer',
                'meta_campaign_id', 'meta_adset_id', 'meta_ad_id', 'platform', 'submitted_at', 'first_contacted_at',
            ]);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
        // The notifications table is kept on rollback because other features may use it.
    }
};
