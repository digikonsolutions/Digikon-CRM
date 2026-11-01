<?php

namespace App\Services;

use App\Jobs\SendAutoInvoiceJob;
use App\Jobs\SendAutoProposalJob;
use App\Mail\ProposalMail;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\Meeting;
use App\Models\Proposal;
use App\Models\ServicePackage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CrmAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The automatic sales flow:
 *
 *   Landing-page lead ──► Hot + assigned + WhatsApp & email follow-ups
 *   Customer agrees a time in WhatsApp ──► AI books the meeting ──► confirmation on WhatsApp + email
 *   Meeting marked Completed / lead status "Meeting Done" ──► proposal PDF (from Service Packages) emailed + WhatsApp
 *   Proposal accepted / lead Won (or right after the proposal) ──► invoice PDF emailed + WhatsApp
 *
 * Every step is switchable in Settings → Automation and is idempotent (re-running never double-sends).
 */
class SalesAutomation
{
    public static function on(string $key, string $default = '1'): bool
    {
        return Setting::get($key, $default) === '1';
    }

    // ------------------------------------------------------------------ landing page

    public function onLandingLeadCreated(Lead $lead): void
    {
        if (self::on('auto_landing_hot') && $lead->temperature !== 'hot') {
            app(LeadStatusService::class)->change($lead, 'temperature', 'hot', null, 'system', 'Landing-page lead (automatic rule)');
        }
    }

    // ------------------------------------------------------------------ meetings from chat

    public function onAnalysisCompleted(LeadAnalysis $analysis): ?Meeting
    {
        if ($analysis->status !== 'completed' || ! $analysis->meeting_at || $analysis->meeting_id || ! self::on('ai_auto_book_meetings')) {
            return null;
        }

        return $this->bookMeetingFromAnalysis($analysis);
    }

    public function bookMeetingFromAnalysis(LeadAnalysis $analysis): ?Meeting
    {
        $lead = $analysis->lead;
        $start = $analysis->meeting_at->copy();
        $agent = $lead->agent;

        if (! $agent || ! $agent->canOwnLeads()) {
            $lead->log('meeting_scheduled', 'AI found an agreed meeting time ('.$start->format('d M, h:i A').') but the lead has no active owner — book it manually.', null);

            return null;
        }
        if ($start->lt(now()->addMinutes(15))) {
            return null;
        }
        // Already booked for (about) that time? → just link it.
        $existing = $lead->meetings()->active()->whereBetween('starts_at', [$start->copy()->subHours(2), $start->copy()->addHours(2)])->first();
        if ($existing) {
            $analysis->update(['meeting_id' => $existing->id]);

            return null;
        }

        $minutes = (int) Setting::get('auto_meeting_minutes', 30);
        try {
            $meeting = app(MeetingService::class)->create($lead, $agent, [
                'title' => ($analysis->meeting_type === 'phone' ? 'Call' : 'Meeting').' with '.$lead->display_name,
                'meeting_type' => $analysis->meeting_type ?: 'phone',
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($minutes),
                'notes' => 'Booked automatically from the WhatsApp chat (AI analysis #'.$analysis->id.').'
                    .collect($analysis->evidenceFor('meeting_request'))->map(fn ($e) => "\nCustomer: “{$e['quote']}”")->implode(''),
            ]);
        } catch (ValidationException $e) {
            $lead->log('meeting_scheduled', 'AI found an agreed meeting time ('.$start->format('d M, h:i A').') but it could not be booked: '.collect($e->errors())->flatten()->first(), null);
            Notifier::send([$agent], 'meeting_reminder', new CrmAlert('meeting_reminder', 'Customer asked for a meeting — please book manually: '.$lead->display_name,
                $start->format('D d M, h:i A').' clashes with another meeting.', route('leads.show', $lead)));

            return null;
        }

        $meeting->update(['source' => 'ai']);
        $analysis->update(['meeting_id' => $meeting->id]);
        $lead->log('meeting_scheduled', 'Meeting booked automatically from the WhatsApp chat for '.$start->format('D d M, h:i A'), null);
        Notifier::send([$agent], 'meeting_reminder', new CrmAlert('meeting_reminder', 'AI booked a meeting: '.$lead->display_name,
            $start->format('D d M, h:i A').' · '.$meeting->type_label, route('meetings.show', $meeting)));

        if (self::on('auto_meeting_confirmation')) {
            $this->sendMeetingConfirmation($meeting);
        }

        return $meeting->fresh();
    }

    /** WhatsApp + email confirmation to the customer. Sent once per meeting (claimed atomically). */
    public function sendMeetingConfirmation(Meeting $meeting, ?int $userId = null): array
    {
        if (! Meeting::whereKey($meeting->id)->whereNull('confirmation_sent_at')->update(['confirmation_sent_at' => now()])) {
            return ['skipped' => 'already sent'];
        }

        $lead = $meeting->lead;
        $vars = [
            '{name}' => $lead->name ?: 'Sir/Madam',
            '{agent}' => $meeting->agent?->name ?? Setting::get('company_name', ''),
            '{agent_phone}' => $meeting->agent?->phone ?? Setting::get('company_phone', ''),
            '{company}' => Setting::get('company_name', ''),
            '{date}' => $meeting->starts_at->format('l, d M Y'),
            '{time}' => $meeting->starts_at->format('h:i A'),
            '{type}' => strtolower($meeting->type_label),
            '{link}' => $meeting->meeting_link ?: ($meeting->location ?: ''),
        ];
        $text = trim(strtr(Setting::get('meeting_confirmation_message', self::DEFAULT_CONFIRMATION), $vars));

        $result = [];
        $wa = app(WhatsAppService::class)->sendText($lead, $text, $userId);
        $result['whatsapp'] = $wa->status === 'failed' ? 'failed: '.$wa->error : 'sent';
        $mailError = app(LeadMailer::class)->send($lead, 'Meeting confirmed — '.$vars['{date}'].' at '.$vars['{time}'], $text, [], $userId, 'Meeting confirmation email');
        $result['email'] = $mailError ?? 'sent';

        $lead->log('meeting_scheduled', 'Meeting confirmation sent — WhatsApp: '.$result['whatsapp'].' · Email: '.$result['email'], $userId);

        return $result;
    }

    public const DEFAULT_CONFIRMATION = "Assalam o Alaikum {name}! ✅ Your {type} meeting with {agent} from {company} is confirmed for {date} at {time}. {link}\nIf you need to change the time, just reply to this message.";

    // ------------------------------------------------------------------ meeting success → proposal

    /** Called when a meeting is marked Completed or the lead status becomes "Meeting Done". */
    public function onMeetingSuccess(Lead $lead, ?Meeting $meeting = null): void
    {
        if (! self::on('auto_proposal_after_meeting')) {
            return;
        }
        $meeting ??= $lead->meetings()->where('status', 'completed')->latest('starts_at')->first();
        SendAutoProposalJob::dispatch($lead->id, $meeting?->id);
    }

    /**
     * Build a proposal from the matching Service Package and send it.
     * Idempotent: one automatic proposal per lead per 7 days.
     */
    public function createAndSendProposal(Lead $lead, ?Meeting $meeting = null, ?User $actor = null): ?Proposal
    {
        $lock = Cache::lock('auto-proposal-'.$lead->id, 60);
        if (! $lock->get()) {
            return null;
        }

        try {
            $recent = $lead->proposals()->where('source', 'auto')->where('created_at', '>=', now()->subDays(7))->latest()->first();
            if ($recent) {
                return $recent;
            }

            $analysis = $lead->analyses()->where('status', 'completed')->latest()->first();
            $package = ServicePackage::match($lead->service, $analysis?->service_interest, $lead->campaign_name);
            if (! $package || empty($package->items)) {
                $lead->log('proposal', 'Automatic proposal skipped: no Service Package matches "'.($lead->service ?: 'unknown service').'". Create the proposal manually or add a package.', null);
                if ($lead->agent) {
                    Notifier::send([$lead->agent], 'lead_assigned', new CrmAlert('lead_assigned', 'Create proposal manually: '.$lead->display_name,
                        'No service package matched "'.($lead->service ?: 'unknown').'".', route('proposals.create', ['lead' => $lead->id])));
                }

                return null;
            }

            $service = $lead->service ?: ($analysis?->service_interest ?: $package->name);
            $intro = $package->introduction ?: Setting::get('default_intro', 'Thank you for the opportunity to submit this proposal.');
            if ($meeting) {
                $intro = 'Thank you for meeting with us on '.$meeting->starts_at->format('d M Y').'. '.$intro
                    .($meeting->outcome ? "\n\nAs discussed: ".$meeting->outcome : '');
            }

            $proposal = new Proposal([
                'lead_id' => $lead->id,
                'meeting_id' => $meeting?->id,
                'service_package_id' => $package->id,
                'user_id' => $lead->assigned_to ?? $actor?->id,
                'source' => 'auto',
                'template' => Setting::get('default_template', 'modern'),
                'title' => $package->title ?: $service.' Proposal',
                'client_name' => $lead->name ?: 'Valued Customer',
                'client_email' => $lead->email,
                'client_company' => $lead->company,
                'introduction' => $intro,
                'items' => array_map(fn ($i) => ['description' => (string) $i['description'], 'qty' => (float) ($i['qty'] ?? 1), 'price' => (float) ($i['price'] ?? 0)], $package->items),
                'currency' => Setting::get('currency', 'PKR'),
                'discount' => $package->discount,
                'tax_percent' => $package->tax_percent,
                'terms' => Setting::get('default_terms'),
                'valid_until' => now()->addDays(15),
                'status' => 'draft',
            ]);
            $proposal->number = Proposal::nextNumber();
            $proposal->total = $proposal->calculateTotal();
            $proposal->save();

            $lead->log('proposal', "Proposal {$proposal->number} created automatically from package \"{$package->name}\" ({$proposal->money($proposal->total)})", null);
            AuditLogger::log('proposal.auto_created', $proposal, [], ['lead_id' => $lead->id, 'package' => $package->name, 'total' => $proposal->total], null, $actor?->id);

            $this->sendProposal($proposal, $actor);

            return $proposal->fresh();
        } finally {
            $lock->release();
        }
    }

    /** Email (PDF attached) + WhatsApp document. Moves the lead to "Proposal Sent". */
    public function sendProposal(Proposal $proposal, ?User $actor = null): array
    {
        $lead = $proposal->lead;
        $pdfService = app(ProposalPdf::class);
        $pdf = $pdfService->render($proposal);
        $filename = $pdfService->filename($proposal);
        $result = ['email' => 'no email address', 'whatsapp' => 'skipped'];

        if ($proposal->client_email) {
            try {
                $subject = 'Proposal: '.$proposal->title.' — '.Setting::get('company_name', config('app.name'));
                Mail::to($proposal->client_email)->send(new ProposalMail($proposal, $pdf, $filename, $subject, $this->proposalEmailBody($proposal), ProposalPdf::branding()));
                $result['email'] = 'sent';
                $lead?->log('email', "Proposal {$proposal->number} emailed to {$proposal->client_email} (automatic)", $actor?->id);
            } catch (\Throwable $e) {
                Log::warning('Auto proposal email failed', ['proposal' => $proposal->id, 'error' => $e->getMessage()]);
                $result['email'] = 'failed: '.$e->getMessage();
            }
        }

        if ($lead && $lead->phone && self::on('send_documents_whatsapp')) {
            $msg = app(WhatsAppService::class)->sendDocument($lead, $pdf, $filename,
                'Your proposal from '.Setting::get('company_name', '').' — total '.$proposal->money($proposal->total), $actor?->id);
            $result['whatsapp'] = $msg->status === 'failed' ? 'failed: '.$msg->error : 'sent';
        }

        $delivered = $result['email'] === 'sent' || $result['whatsapp'] === 'sent';
        if ($delivered) {
            $proposal->update(['status' => 'sent', 'sent_at' => now()]);
            if ($lead && in_array($lead->status, ['new', 'contacted', 'qualified', 'meeting_done'], true)) {
                app(LeadStatusService::class)->change($lead, 'status', 'proposal', null, 'system', "Proposal {$proposal->number} sent automatically");
            }
            $this->onProposalSent($proposal->fresh());
        }

        $lead?->log('proposal', "Proposal {$proposal->number} delivery — Email: {$result['email']} · WhatsApp: {$result['whatsapp']}", $actor?->id);
        if (! $delivered && $lead?->agent) {
            Notifier::send([$lead->agent], 'lead_assigned', new CrmAlert('lead_assigned', "Proposal {$proposal->number} could not be delivered",
                'Email: '.$result['email'].' · WhatsApp: '.$result['whatsapp'].' — send it manually.', route('proposals.show', $proposal)));
        }

        return $result;
    }

    public function proposalEmailBody(Proposal $proposal): string
    {
        $template = Setting::get('email_body', "Dear {client},\n\nThank you for your interest. Please find attached our proposal \"{title}\" ({number}).\n\nTotal: {total}\nValid until: {valid_until}\n\nFeel free to reply to this email or WhatsApp us if you have any questions.\n\nBest regards,\n{sender}\n{company}");

        return strtr($template, [
            '{client}' => $proposal->client_name,
            '{title}' => $proposal->title,
            '{number}' => $proposal->number,
            '{total}' => $proposal->money($proposal->total),
            '{valid_until}' => $proposal->valid_until?->format('d M Y') ?? '-',
            '{sender}' => $proposal->user?->name ?? Setting::get('company_name', ''),
            '{company}' => Setting::get('company_name', config('app.name')),
        ]);
    }

    // ------------------------------------------------------------------ proposal → invoice

    public function onProposalSent(Proposal $proposal): void
    {
        if (Setting::get('auto_invoice_trigger', 'on_accept') === 'after_proposal') {
            SendAutoInvoiceJob::dispatch($proposal->id);
        }
    }

    public function onProposalAccepted(Proposal $proposal): void
    {
        if (Setting::get('auto_invoice_trigger', 'on_accept') === 'on_accept') {
            SendAutoInvoiceJob::dispatch($proposal->id);
        }
    }

    /** Lead marked Won by hand: accept its latest sent proposal (if any) so revenue + invoice follow. */
    public function onLeadWon(Lead $lead): void
    {
        $proposal = $lead->proposals()->where('status', 'sent')->latest()->first();
        if ($proposal) {
            $proposal->update(['status' => 'accepted', 'accepted_at' => now()]);
            $lead->log('proposal', "Proposal {$proposal->number} marked accepted (lead Won)", null);
            $this->onProposalAccepted($proposal);
        }
    }

    /** Idempotent: one active (non-cancelled) invoice per proposal. */
    public function createAndSendInvoice(Proposal $proposal, ?User $actor = null): ?Invoice
    {
        $existing = $proposal->invoices()->where('status', '!=', 'cancelled')->first();
        if ($existing) {
            return $existing;
        }

        $advance = (float) Setting::get('invoice_advance_percent', 50);
        $advance = $advance > 0 && $advance <= 100 ? $advance : 100;
        $invoice = Invoice::create([
            'number' => Invoice::nextNumber(),
            'lead_id' => $proposal->lead_id,
            'proposal_id' => $proposal->id,
            'user_id' => $proposal->user_id ?? $actor?->id,
            'source' => $actor ? 'manual' : 'auto',
            'client_name' => $proposal->client_name,
            'client_email' => $proposal->client_email ?: $proposal->lead?->email,
            'client_phone' => $proposal->lead?->phone,
            'client_company' => $proposal->client_company,
            'items' => $proposal->items,
            'currency' => $proposal->currency,
            'subtotal' => $proposal->subtotal,
            'discount' => $proposal->discount,
            'tax_percent' => $proposal->tax_percent,
            'total' => $proposal->total,
            'advance_percent' => $advance,
            'amount_due' => round($proposal->total * $advance / 100, 2),
            'status' => 'draft',
            'issue_date' => today(),
            'due_date' => today()->addDays((int) Setting::get('invoice_due_days', 7)),
            'notes' => Setting::get('invoice_notes'),
            'payment_instructions' => Setting::get('invoice_payment_instructions'),
        ]);

        $proposal->lead?->log('invoice', "Invoice {$invoice->number} created for proposal {$proposal->number} — due now {$invoice->money($invoice->amount_due)}", $actor?->id);
        AuditLogger::log('invoice.created', $invoice, [], ['proposal' => $proposal->number, 'amount_due' => $invoice->amount_due], null, $actor?->id);

        $this->sendInvoice($invoice, $actor);

        return $invoice->fresh();
    }

    public function sendInvoice(Invoice $invoice, ?User $actor = null, ?string $to = null): array
    {
        $lead = $invoice->lead;
        $pdfService = app(InvoicePdf::class);
        $pdf = $pdfService->render($invoice);
        $filename = $invoice->number.'.pdf';
        $to ??= $invoice->client_email;
        $result = ['email' => 'no email address', 'whatsapp' => 'skipped'];

        $body = strtr(Setting::get('invoice_email_body', "Dear {client},\n\nThank you for choosing {company}. Please find attached invoice {number}.\n\nAmount due now: {amount_due}\nDue date: {due_date}\n\n{payment}\n\nBest regards,\n{company}"), [
            '{client}' => $invoice->client_name,
            '{company}' => Setting::get('company_name', config('app.name')),
            '{number}' => $invoice->number,
            '{amount_due}' => $invoice->money($invoice->amount_due),
            '{total}' => $invoice->money($invoice->total),
            '{due_date}' => $invoice->due_date?->format('d M Y') ?? '-',
            '{payment}' => $invoice->payment_instructions ? "Payment details:\n".$invoice->payment_instructions : '',
        ]);

        if ($to) {
            try {
                Mail::to($to)->send(new \App\Mail\CrmMessageMail('Invoice '.$invoice->number.' — '.Setting::get('company_name', config('app.name')), $body, ProposalPdf::branding(), [['data' => $pdf, 'name' => $filename]]));
                $result['email'] = 'sent';
                $lead?->log('email', "Invoice {$invoice->number} emailed to {$to}", $actor?->id);
            } catch (\Throwable $e) {
                $result['email'] = 'failed: '.$e->getMessage();
            }
        }
        if ($lead && $lead->phone && self::on('send_documents_whatsapp')) {
            $msg = app(WhatsAppService::class)->sendDocument($lead, $pdf, $filename, 'Invoice '.$invoice->number.' — due now '.$invoice->money($invoice->amount_due), $actor?->id);
            $result['whatsapp'] = $msg->status === 'failed' ? 'failed: '.$msg->error : 'sent';
        }

        if ($result['email'] === 'sent' || $result['whatsapp'] === 'sent') {
            $invoice->update(['status' => $invoice->status === 'paid' ? 'paid' : 'sent', 'sent_at' => now(), 'client_email' => $to ?? $invoice->client_email]);
        } elseif ($lead?->agent) {
            Notifier::send([$lead->agent], 'lead_assigned', new CrmAlert('lead_assigned', "Invoice {$invoice->number} could not be delivered",
                'Email: '.$result['email'].' · WhatsApp: '.$result['whatsapp'], route('invoices.show', $invoice)));
        }
        $lead?->log('invoice', "Invoice {$invoice->number} delivery — Email: {$result['email']} · WhatsApp: {$result['whatsapp']}", $actor?->id);

        return $result;
    }
}
