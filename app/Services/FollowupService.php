<?php

namespace App\Services;

use App\Models\FollowupStep;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Setting;

class FollowupService
{
    public function __construct(protected WhatsAppService $whatsapp, protected LeadMailer $mailer) {}

    /** Can this step reach this lead? (WhatsApp needs a phone + no opt-out, email needs an address) */
    protected function reachable(FollowupStep $step, Lead $lead): bool
    {
        return ($step->channel ?? 'whatsapp') === 'email'
            ? filled($lead->email)
            : filled($lead->phone) && ! $lead->whatsapp_opt_out;
    }

    /** Create the follow-up schedule for a new lead. */
    public function scheduleFor(Lead $lead, ?\Illuminate\Support\Carbon $from = null): void
    {
        if (Setting::get('auto_followups', '1') !== '1' || (! $lead->phone && ! $lead->email)) {
            return;
        }

        $base = ($from ?? $lead->created_at ?? now())->copy();

        foreach (FollowupStep::where('is_active', true)->orderBy('sort_order')->orderBy('delay_minutes')->get() as $step) {
            if (! $this->reachable($step, $lead)) {
                continue;
            }
            LeadFollowup::firstOrCreate(
                ['lead_id' => $lead->id, 'followup_step_id' => $step->id],
                ['scheduled_at' => $base->copy()->addMinutes($step->delay_minutes), 'status' => 'pending']
            );
        }

        FollowUpClock::refresh($lead);
    }

    /** Stop all pending follow-ups (customer replied, opted out, won/lost ...). */
    public function cancelFor(Lead $lead, string $reason): int
    {
        $count = $lead->followups()->where('status', 'pending')
            ->update(['status' => 'cancelled', 'error' => $reason]);

        if ($count) {
            FollowUpClock::refresh($lead);
            $lead->log('followup', "{$count} pending follow-up(s) stopped: {$reason}", null);
        }

        return $count;
    }

    /** Called every minute by the scheduler. */
    public function sendDue(int $limit = 50): array
    {
        $sent = $failed = $skipped = 0;

        if (Setting::get('auto_followups', '1') !== '1') {
            return compact('sent', 'failed', 'skipped'); // globally switched off
        }

        $due = LeadFollowup::with(['lead.agent', 'step'])
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        foreach ($due as $followup) {
            $lead = $followup->lead;
            $step = $followup->step;

            $stopReason = match (true) {
                ! $lead || ! $step => 'Lead or step was deleted',
                ! $step->is_active => 'Step is inactive',
                $lead->followups_paused => 'Follow-ups paused',
                ! $this->reachable($step, $lead) => ($step->channel === 'email' ? 'Lead has no email' : 'No phone / opted out of WhatsApp'),
                in_array($lead->status, Lead::CLOSED_STATUSES) => 'Lead '.$lead->status,
                $lead->last_reply_at !== null => 'Customer has replied',
                default => null,
            };

            if ($stopReason) {
                $followup->update(['status' => 'cancelled', 'error' => $stopReason]);
                $skipped++;
                continue;
            }

            if ($step->channel === 'email') {
                $error = $this->mailer->send($lead,
                    WhatsAppService::fill($step->email_subject ?: $step->name, $lead),
                    WhatsAppService::fill($step->message, $lead), [], null, 'Automated follow-up email');
            } elseif ($step->message_type === 'template') {
                $vars = array_filter(array_map('trim', explode('|', WhatsAppService::fill($step->message, $lead))), fn ($v) => $v !== '');
                $msg = $this->whatsapp->sendTemplate($lead, $step->template_name, $step->template_language ?: 'en', $vars);
                $error = $msg->status === 'failed' ? $msg->error : null;
            } else {
                $msg = $this->whatsapp->sendText($lead, WhatsAppService::fill($step->message, $lead));
                $error = $msg->status === 'failed' ? $msg->error : null;
            }

            if ($error) {
                $followup->update(['status' => 'failed', 'error' => $error]);
                $lead->log('followup', "Follow-up \"{$step->name}\" failed: {$error}", null);
                $failed++;
            } else {
                $followup->update(['status' => 'sent', 'sent_at' => now()]);
                if ($lead->status === 'new') {
                    app(LeadStatusService::class)->change($lead, 'status', 'contacted', null, 'system', 'Automated '.($step->channel === 'email' ? 'email' : 'WhatsApp').' follow-up sent');
                }
                $sent++;
            }

            FollowUpClock::refresh($lead);
        }

        return compact('sent', 'failed', 'skipped');
    }
}
