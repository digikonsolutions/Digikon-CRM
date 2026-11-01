<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Proposal;
use App\Services\AuditLogger;
use App\Services\InvoicePdf;
use App\Services\SalesAutomation;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::visibleTo($request->user())->with(['lead:id,name', 'proposal:id,number'])
            ->when($request->status, fn ($q, $v) => $v === 'overdue'
                ? $q->where('status', 'sent')->whereDate('due_date', '<', today())
                : $q->where('status', $v))
            ->latest()->paginate(30)->withQueryString();

        $base = Invoice::visibleTo($request->user());

        return view('invoices.index', [
            'invoices' => $invoices,
            'outstanding' => (clone $base)->where('status', 'sent')->selectRaw('currency, SUM(amount_due) as total')->groupBy('currency')->pluck('total', 'currency'),
            'paidThisMonth' => (clone $base)->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->selectRaw('currency, SUM(amount_due) as total')->groupBy('currency')->pluck('total', 'currency'),
        ]);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return view('invoices.show', ['invoice' => $invoice->load(['lead', 'proposal', 'user'])]);
    }

    public function pdf(Request $request, Invoice $invoice, InvoicePdf $pdf)
    {
        $this->authorizeInvoice($request, $invoice);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$invoice->number.'.pdf"',
        ]);
    }

    /** Create an invoice from a proposal by hand (same builder as the automatic one). */
    public function store(Request $request, Proposal $proposal, SalesAutomation $automation)
    {
        abort_unless($request->user()->hasPermission('invoices.manage'), 403);
        abort_unless($proposal->lead === null || $request->user()->can('view', $proposal->lead), 403);
        $invoice = $automation->createAndSendInvoice($proposal, $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', "Invoice {$invoice->number} ready".($invoice->status === 'sent' ? ' and sent.' : '.'));
    }

    public function send(Request $request, Invoice $invoice, SalesAutomation $automation)
    {
        $this->authorizeInvoice($request, $invoice);
        $data = $request->validate(['to' => 'nullable|email']);
        $r = $automation->sendInvoice($invoice, $request->user(), $data['to'] ?? null);

        return back()->with($r['email'] === 'sent' || $r['whatsapp'] === 'sent' ? 'success' : 'error', 'Email: '.$r['email'].' · WhatsApp: '.$r['whatsapp']);
    }

    public function status(Request $request, Invoice $invoice)
    {
        abort_unless($request->user()->hasPermission('invoices.manage'), 403);
        $this->authorizeInvoice($request, $invoice);
        $data = $request->validate(['status' => 'required|in:sent,paid,cancelled']);

        $old = $invoice->status;
        $invoice->update(['status' => $data['status'], 'paid_at' => $data['status'] === 'paid' ? now() : null]);
        $invoice->lead?->log('invoice', "Invoice {$invoice->number}: {$old} → {$data['status']}", $request->user()->id);
        AuditLogger::log('invoice.status', $invoice, ['status' => $old], ['status' => $data['status']]);

        return back()->with('success', 'Invoice marked '.$data['status'].'.');
    }

    protected function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        abort_unless(Invoice::visibleTo($request->user())->whereKey($invoice->id)->exists(), 403);
    }
}
