<?php

namespace App\Http\Controllers;

use App\Mail\ProposalMail;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Setting;
use App\Services\ProposalPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ProposalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $proposals = Proposal::with('lead', 'user')
            ->when(! $user->hasPermission('leads.view-all'), fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)
                ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id))))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(25)->withQueryString();

        return view('proposals.index', compact('proposals'));
    }

    public function create(Request $request)
    {
        $lead = $request->lead ? Lead::visibleTo($request->user())->findOrFail($request->lead) : null;

        $proposal = new Proposal([
            'template' => Setting::get('default_template', 'modern'),
            'currency' => Setting::get('currency', 'PKR'),
            'title' => $lead?->service ? $lead->service.' Proposal' : 'Project Proposal',
            'client_name' => $lead?->name,
            'client_email' => $lead?->email,
            'client_company' => $lead?->company,
            'introduction' => Setting::get('default_intro'),
            'terms' => Setting::get('default_terms'),
            'valid_until' => now()->addDays(15),
            'items' => [['description' => '', 'qty' => 1, 'price' => 0]],
            'lead_id' => $lead?->id,
        ]);

        return view('proposals.form', compact('proposal'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $proposal = new Proposal($data);
        $proposal->number = Proposal::nextNumber();
        $proposal->user_id = $request->user()->id;
        $proposal->total = $proposal->calculateTotal();
        $proposal->save();

        $proposal->lead?->log('proposal', "Proposal {$proposal->number} created ({$proposal->money($proposal->total)})");

        return redirect()->route('proposals.show', $proposal)->with('success', 'Proposal created. Review the preview, then email it.');
    }

    public function show(Request $request, Proposal $proposal)
    {
        $this->authorizeProposal($request, $proposal);

        return view('proposals.show', [
            'proposal' => $proposal->load('lead'),
            'subject' => 'Proposal: '.$proposal->title.' — '.Setting::get('company_name', config('app.name')),
            'body' => $this->defaultEmailBody($proposal),
        ]);
    }

    public function edit(Request $request, Proposal $proposal)
    {
        $this->authorizeProposal($request, $proposal);

        return view('proposals.form', compact('proposal'));
    }

    public function update(Request $request, Proposal $proposal)
    {
        $this->authorizeProposal($request, $proposal);
        $proposal->fill($this->validated($request));
        $proposal->total = $proposal->calculateTotal();
        $proposal->save();

        return redirect()->route('proposals.show', $proposal)->with('success', 'Proposal updated.');
    }

    public function destroy(Request $request, Proposal $proposal)
    {
        $this->authorizeProposal($request, $proposal);
        $proposal->delete();

        return redirect()->route('proposals.index')->with('success', 'Proposal deleted.');
    }

    public function pdf(Request $request, Proposal $proposal, ProposalPdf $pdf)
    {
        $this->authorizeProposal($request, $proposal);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->render($proposal), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$pdf->filename($proposal).'"',
        ]);
    }

    public function send(Request $request, Proposal $proposal, ProposalPdf $pdf)
    {
        $this->authorizeProposal($request, $proposal);
        $data = $request->validate([
            'to' => 'required|email',
            'cc' => 'nullable|email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        try {
            $mail = Mail::to($data['to']);
            if (! empty($data['cc'])) {
                $mail->cc($data['cc']);
            }
            $mail->send(new ProposalMail($proposal, $pdf->render($proposal), $pdf->filename($proposal), $data['subject'], $data['body'], ProposalPdf::branding()));
        } catch (\Throwable $e) {
            return back()->with('error', 'Email could not be sent: '.$e->getMessage());
        }

        $proposal->update(['status' => 'sent', 'sent_at' => now(), 'client_email' => $data['to']]);

        if ($lead = $proposal->lead) {
            $lead->log('email', "Proposal {$proposal->number} emailed to {$data['to']}");
            $statuses = app(\App\Services\LeadStatusService::class);
            $statuses->markContacted($lead, $request->user()->id);
            if (in_array($lead->fresh()->status, ['new', 'contacted', 'qualified'])) {
                $statuses->change($lead->fresh(), 'status', 'proposal', $request->user()->id, 'manual', "Proposal {$proposal->number} emailed");
            }
        }

        return back()->with('success', "Proposal PDF emailed to {$data['to']}.");
    }

    public function status(Request $request, Proposal $proposal)
    {
        $this->authorizeProposal($request, $proposal);
        $data = $request->validate(['status' => 'required|in:draft,sent,accepted,rejected']);
        $data['accepted_at'] = $data['status'] === 'accepted' ? ($proposal->accepted_at ?? now()) : null;
        $proposal->update($data);
        \App\Services\AuditLogger::log('proposal.status', $proposal, [], ['status' => $data['status']]);

        if ($proposal->lead && $data['status'] === 'accepted') {
            app(\App\Services\LeadStatusService::class)->change($proposal->lead, 'status', 'won', $request->user()->id, 'manual', "Proposal {$proposal->number} accepted");
        }
        if ($data['status'] === 'accepted') {
            app(\App\Services\SalesAutomation::class)->onProposalAccepted($proposal->fresh());
        }

        return back()->with('success', 'Proposal status updated.');
    }

    protected function defaultEmailBody(Proposal $proposal): string
    {
        $template = Setting::get('email_body', "Dear {client},\n\nThank you for your interest. Please find attached our proposal \"{title}\" ({number}).\n\nTotal: {total}\nValid until: {valid_until}\n\nFeel free to reply to this email or WhatsApp us if you have any questions.\n\nBest regards,\n{sender}\n{company}");

        return strtr($template, [
            '{client}' => $proposal->client_name,
            '{title}' => $proposal->title,
            '{number}' => $proposal->number,
            '{total}' => $proposal->money($proposal->total),
            '{valid_until}' => $proposal->valid_until?->format('d M Y') ?? '-',
            '{sender}' => auth()->user()->name,
            '{company}' => Setting::get('company_name', config('app.name')),
        ]);
    }

    protected function authorizeProposal(Request $request, Proposal $proposal): void
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('leads.view-all') || (int) $proposal->user_id === (int) $user->id
            || ($proposal->lead && $user->can('view', $proposal->lead)),
            403
        );
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'exists:leads,id', function ($attr, $value, $fail) use ($request) {
                if ($value && ! $request->user()->can('view', \App\Models\Lead::find($value))) {
                    $fail('You cannot attach a proposal to this lead.');
                }
            }],
            'template' => ['required', Rule::in(array_keys(Proposal::TEMPLATES))],
            'title' => 'required|string|max:255',
            'client_name' => 'required|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_company' => 'nullable|string|max:255',
            'introduction' => 'nullable|string|max:10000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.qty' => 'nullable|numeric|min:0',
            'items.*.price' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:10',
            'discount' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'terms' => 'nullable|string|max:10000',
            'valid_until' => 'nullable|date',
        ]);

        $data['items'] = array_values(array_filter($data['items'], fn ($i) => trim($i['description'] ?? '') !== ''));
        if (! $data['items']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'Add a description for at least one item.']);
        }
        $data['items'] = array_map(fn ($i) => [
            'description' => trim($i['description']),
            'qty' => (float) ($i['qty'] ?? 1),
            'price' => (float) ($i['price'] ?? 0),
        ], $data['items']);
        $data['discount'] = $data['discount'] ?? 0;
        $data['tax_percent'] = $data['tax_percent'] ?? 0;

        return $data;
    }
}
