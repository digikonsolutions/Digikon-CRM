<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePublicLeadRequest;
use App\Models\Setting;
use App\Services\LeadIngestionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * POST /api/v1/public/leads — landing page forms.
 * Rate limited (throttle:public-leads), honeypot spam trap, optional form key,
 * CORS limited to the allowed origins from Settings (CORS is NOT authentication).
 */
class PublicLeadController extends Controller
{
    public function store(StorePublicLeadRequest $request, LeadIngestionService $ingestion)
    {
        $thanks = Setting::get('public_form_thanks', 'Thank you! Our team will contact you shortly.');

        // Optional shared form key (identifies your forms; it is visible in page source, so it is not a secret).
        $key = Setting::get('public_form_key');
        if ($key && ! hash_equals($key, (string) ($request->header('X-Form-Key') ?? $request->input('form_key')))) {
            return response()->json(['ok' => false, 'message' => 'This form is not authorised.'], 403);
        }

        $submissionId = $request->input('submission_id') ?: (string) Str::uuid();

        // Honeypot: real users never fill the hidden "hp_field" / "_gotcha" field. Pretend success, store as spam.
        // ("website" is NOT a honeypot — it is a normal field on many forms.)
        if (filled($request->input('hp_field')) || filled($request->input('_gotcha'))) {
            $ingestion->recordEvent('landing_page', 'spam-'.$submissionId, ['reason' => 'honeypot'], [], $request->ip())[0]
                ->update(['status' => 'spam']);

            return $this->success($request, $thanks, $submissionId, 201);
        }

        $v = $request->validated();
        $attribution = array_filter([
            'utm_source' => $v['utm_source'] ?? null,
            'utm_medium' => $v['utm_medium'] ?? null,
            'utm_campaign' => $v['utm_campaign'] ?? null,
            'utm_content' => $v['utm_content'] ?? null,
            'landing_page_url' => $v['landing_page_url'] ?? null,
            'referrer' => $v['referrer'] ?? $request->headers->get('referer'),
            'origin' => $request->headers->get('origin'),
        ]);

        try {
            $result = $ingestion->ingestLandingPage($v, $submissionId, $attribution, $request->ip(), (string) $request->userAgent());
        } catch (\Throwable $e) {
            Log::error('Landing page lead failed', ['submission' => $submissionId, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => 'Sorry, something went wrong. Please try again or contact us on WhatsApp.'], 500);
        }

        if ($result['replay']) {
            // Same submission_id sent again (double click / retry) → same answer, nothing new created.
            return $this->success($request, $thanks, $submissionId, 200);
        }

        // Internal lead IDs are never returned.
        return $this->success($request, $thanks, $submissionId, 201);
    }

    /**
     * JSON for fetch/AJAX forms. A plain HTML form (no JavaScript) can send a hidden "redirect_to"
     * field (your thank-you page) and the customer is redirected there. Only URLs on an allowed
     * origin (Lead Sources → allowed origins) are accepted, so it cannot be abused as an open redirect.
     */
    protected function success(StorePublicLeadRequest $request, string $thanks, string $reference, int $status)
    {
        $to = (string) $request->input('redirect_to', '');
        if ($to !== '' && ! $request->expectsJson()) {
            foreach (array_filter(preg_split('/\s+/', (string) Setting::get('public_form_allowed_origins', ''))) as $origin) {
                if ($to === $origin || str_starts_with($to, rtrim($origin, '/').'/')) {
                    return redirect()->away($to, 303);
                }
            }
        }

        return response()->json(['ok' => true, 'message' => $thanks, 'reference' => $reference], $status);
    }
}
