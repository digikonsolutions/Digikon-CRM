<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaLeadJob;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Lead sources: ingestion log (Meta / landing pages), retry, landing page setup. */
class LeadSourceController extends Controller
{
    public function index(Request $request)
    {
        $events = LeadSourceEvent::with('lead:id,name,phone')
            ->when($request->source, fn ($q, $v) => $q->where('source', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(40)->withQueryString();

        $since = now()->subDays(30);

        return view('lead-sources.index', [
            'events' => $events,
            'stats' => LeadSourceEvent::where('created_at', '>=', $since)->selectRaw('source, status, COUNT(*) as total')->groupBy('source', 'status')->get(),
            'bySource' => Lead::where('created_at', '>=', $since)->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source'),
            'byUtm' => Lead::where('created_at', '>=', $since)->whereNotNull('utm_campaign')->selectRaw('utm_campaign, COUNT(*) as total')->groupBy('utm_campaign')->orderByDesc('total')->limit(10)->pluck('total', 'utm_campaign'),
            'endpoint' => url('/api/v1/public/leads'),
            'formKey' => Setting::get('public_form_key'),
            'origins' => Setting::get('public_form_allowed_origins'),
        ]);
    }

    public function retry(Request $request, LeadSourceEvent $event)
    {
        abort_unless($event->source === 'meta' && in_array($event->status, ['failed', 'pending'], true), 422, 'Only failed or pending Meta events can be retried.');
        $event->update(['status' => 'pending', 'error' => null, 'failure_notified_at' => null]);
        ProcessMetaLeadJob::dispatch($event->id);
        AuditLogger::log('lead_source_event.retried', $event);

        return back()->with('success', 'Meta lead queued for another attempt.');
    }

    public function settings(Request $request)
    {
        $data = $request->validate([
            'public_form_allowed_origins' => 'nullable|string|max:2000',
            'regenerate_key' => 'nullable|boolean',
            'remove_key' => 'nullable|boolean',
        ]);

        $origins = collect(preg_split('/[\s,]+/', (string) ($data['public_form_allowed_origins'] ?? '')))
            ->map(fn ($o) => rtrim(trim($o), '/'))
            ->filter(fn ($o) => preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $o))
            ->unique()->implode("\n");

        $values = ['public_form_allowed_origins' => $origins];
        if ($request->boolean('regenerate_key')) {
            $values['public_form_key'] = 'pf_'.Str::random(32);
        }
        if ($request->boolean('remove_key')) {
            $values['public_form_key'] = null;
        }
        Setting::setMany($values);
        AuditLogger::log('settings.landing_pages', null, [], ['origins' => $origins, 'key_changed' => isset($values['public_form_key'])]);

        return back()->with('success', 'Landing page settings saved.');
    }
}
