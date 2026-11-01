<?php

namespace App\Services;

use App\Mail\CrmMessageMail;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Sends a branded email to a lead and logs it on the timeline. Never throws. */
class LeadMailer
{
    /** @return string|null error message, null on success */
    public function send(Lead $lead, string $subject, string $body, array $files = [], ?int $userId = null, ?string $logText = null): ?string
    {
        if (! $lead->email) {
            return 'Lead has no email address.';
        }

        try {
            Mail::to($lead->email)->send(new CrmMessageMail($subject, $body, ProposalPdf::branding(), $files));
        } catch (\Throwable $e) {
            Log::warning('Lead email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return 'Email could not be sent: '.$e->getMessage();
        }

        $lead->log('email', ($logText ?? 'Email sent').': '.$subject.' → '.$lead->email, $userId);
        $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();

        return null;
    }
}
