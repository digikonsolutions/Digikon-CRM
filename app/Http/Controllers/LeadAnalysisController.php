<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Services\Ai\AiException;
use App\Services\LeadQualificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadAnalysisController extends Controller
{
    /** Review queue for managers. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('lead-analyses.review'), 403);

        $analyses = LeadAnalysis::with(['lead.agent', 'reviewer'])
            ->whereHas('lead', fn ($q) => $q->visibleTo($user))
            ->where('status', 'completed')
            ->when($request->query('status', 'open') === 'open', fn ($q) => $q->whereIn('review_status', ['pending', 'auto_applied']))
            ->when(in_array($request->query('status'), array_keys(LeadAnalysis::REVIEW_STATUSES), true), fn ($q) => $q->where('review_status', $request->query('status')))
            ->latest()
            ->paginate(30)->withQueryString();

        return view('analyses.index', compact('analyses'));
    }

    /** "Analyse WhatsApp chat" button. Runs now (synchronously) so the agent sees the result immediately. */
    public function store(Request $request, Lead $lead, LeadQualificationService $service)
    {
        $this->authorize('runAnalysis', $lead);

        try {
            $analysis = $service->analyze($lead, $request->user(), 'manual');
        } catch (AiException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($analysis->status === 'failed') {
            return back()->with('error', 'AI analysis failed: '.$analysis->error);
        }

        $applied = $analysis->review_status === 'auto_applied' ? ' Empty fields were filled automatically — please review.' : '';

        return back()->with('success', 'WhatsApp chat analysed ('.$analysis->messages_analyzed.' messages).'.$applied);
    }

    public function approve(Request $request, LeadAnalysis $analysis, LeadQualificationService $service)
    {
        $this->authorize('reviewAnalysis', $analysis->lead);
        abort_if($analysis->status !== 'completed', 422, 'Only completed analyses can be applied.');

        $data = $request->validate([
            'apply' => 'nullable|array',
            'apply.*' => ['string', Rule::in(LeadAnalysis::APPLICABLE_FIELDS)],
            'service_interest' => 'nullable|string|max:190',
            'budget' => 'nullable|string|max:190',
            'timeline' => 'nullable|string|max:190',
            'industry' => 'nullable|string|max:190',
            'buying_intent' => ['nullable', Rule::in(array_keys(config('crm.buying_intents')))],
            'temperature' => ['nullable', Rule::in(array_keys(config('crm.temperatures')))],
            'qualification' => ['nullable', Rule::in(['qualified', 'needs_info', 'not_qualified', 'pending'])],
            'tags' => 'nullable|string|max:300',
        ]);

        // Only the fields the reviewer ticked are written.
        $values = [];
        foreach ($data['apply'] ?? [] as $field) {
            $values[$field] = $data[$field] ?? null;
        }

        $applied = $service->approve($analysis, $request->user(), $values);

        return back()->with('success', $applied ? 'Applied to lead: '.implode(', ', array_keys($applied)).'.' : 'Analysis approved (no lead fields changed).');
    }

    public function reject(Request $request, LeadAnalysis $analysis, LeadQualificationService $service)
    {
        $this->authorize('reviewAnalysis', $analysis->lead);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $service->reject($analysis, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Analysis rejected.');
    }
}
