<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\Setting;
use App\Services\LeadDistributor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    protected const SECRET_KEYS = ['meta_page_access_token', 'meta_app_secret', 'wa_access_token', 'smtp_password', 'ai_api_key'];

    protected const BOOLEAN_KEYS = [
        'ai' => ['ai_auto_analyze', 'ai_auto_apply'],
        'automation' => ['auto_landing_hot', 'ai_auto_book_meetings', 'auto_meeting_confirmation', 'auto_proposal_after_meeting', 'send_documents_whatsapp'],
    ];

    public function index(Request $request)
    {
        return view('settings.index', [
            'tab' => $request->query('tab', 'general'),
            's' => fn ($key, $default = null) => Setting::get($key, $default),
            'methods' => LeadDistributor::METHODS,
            'aiProviders' => \App\Services\Ai\AiClientFactory::PROVIDERS,
            'aiDefaultModels' => \App\Services\Ai\AiClientFactory::DEFAULT_MODELS,
            'templates' => Proposal::TEMPLATES,
            'webhookMeta' => route('webhooks.meta'),
            'webhookWhatsapp' => route('webhooks.whatsapp'),
        ]);
    }

    public function update(Request $request)
    {
        $tab = $request->input('tab', 'general');

        $rules = match ($tab) {
            'general' => [
                'company_name' => 'required|string|max:255',
                'company_email' => 'nullable|email|max:255',
                'company_phone' => 'nullable|string|max:50',
                'company_website' => 'nullable|string|max:255',
                'company_address' => 'nullable|string|max:500',
                'default_country_code' => 'required|digits_between:1,4',
                'currency' => 'required|string|max:10',
                'distribution_method' => ['required', Rule::in(array_keys(LeadDistributor::METHODS))],
                'meeting_reminder_minutes' => 'nullable|integer|min:5|max:1440',
            ],
            'automation' => [
                'auto_invoice_trigger' => 'required|in:on_accept,after_proposal,off',
                'auto_meeting_minutes' => 'required|integer|min:10|max:240',
                'meeting_confirmation_message' => 'nullable|string|max:1000',
                'invoice_prefix' => 'required|alpha_dash|max:10',
                'invoice_advance_percent' => 'required|numeric|min:1|max:100',
                'invoice_due_days' => 'required|integer|min:0|max:120',
                'invoice_payment_instructions' => 'nullable|string|max:2000',
                'invoice_notes' => 'nullable|string|max:2000',
                'invoice_email_body' => 'nullable|string|max:5000',
            ],
            'ai' => [
                'ai_provider' => ['required', Rule::in(array_keys(\App\Services\Ai\AiClientFactory::PROVIDERS))],
                'ai_model' => 'nullable|string|max:80',
                'ai_api_key' => 'nullable|string|max:500',
                'ai_min_confidence' => 'required|numeric|min:0.3|max:1',
                'ai_debounce_minutes' => 'required|integer|min:1|max:240',
            ],
            'meta' => [
                'webhook_verify_token' => 'required|string|max:255',
                'meta_page_access_token' => 'nullable|string|max:2000',
                'meta_app_secret' => 'nullable|string|max:255',
                'graph_version' => 'required|string|max:10',
            ],
            'whatsapp' => [
    'wa_phone_number_id' => 'required|regex:/^[0-9]+$/|max:100',
    'wa_business_account_id' => 'required|regex:/^[0-9]+$/|max:100',
    'wa_access_token' => 'nullable|string|max:2000',
],
            'email' => [
                'smtp_host' => 'nullable|string|max:255',
                'smtp_port' => 'nullable|integer',
                'smtp_username' => 'nullable|string|max:255',
                'smtp_password' => 'nullable|string|max:255',
                'smtp_encryption' => 'nullable|in:tls,ssl,none',
                'mail_from_address' => 'nullable|email|max:255',
                'mail_from_name' => 'nullable|string|max:255',
                'email_body' => 'nullable|string|max:5000',
            ],
            'branding' => [
                'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'default_template' => ['required', Rule::in(array_keys(Proposal::TEMPLATES))],
                'proposal_prefix' => 'required|alpha_dash|max:10',
                'signer_name' => 'nullable|string|max:255',
                'signer_title' => 'nullable|string|max:255',
                'default_intro' => 'nullable|string|max:5000',
                'default_terms' => 'nullable|string|max:5000',
                'proposal_footer' => 'nullable|string|max:500',
                'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
                'signature' => 'nullable|image|mimes:png|max:2048',
            ],
            default => abort(404),
        };

        $data = $request->validate($rules);
        foreach (self::BOOLEAN_KEYS[$tab] ?? [] as $key) {
            $data[$key] = $request->boolean($key) ? '1' : '0';
        }

        // Empty secret fields mean "keep the saved value"
        foreach (self::SECRET_KEYS as $key) {
            if (array_key_exists($key, $data) && ($data[$key] === null || $data[$key] === '')) {
                unset($data[$key]);
            }
        }
        if ($tab === 'ai' && $request->boolean('remove_ai_api_key')) {
            $data['ai_api_key'] = null;
        }

        foreach (['logo' => 'logo_path', 'signature' => 'signature_path'] as $field => $key) {
            unset($data[$field]);
            if ($request->hasFile($field)) {
                if ($old = Setting::get($key)) {
                    Storage::disk('public')->delete($old);
                }
                $data[$key] = $request->file($field)->store('branding', 'public');
            }
            if ($request->boolean('remove_'.$field) && $old = Setting::get($key)) {
                Storage::disk('public')->delete($old);
                $data[$key] = null;
            }
        }

        Setting::setMany($data);
        \App\Services\AuditLogger::log('settings.updated', null, [], ['tab' => $tab, 'keys' => array_keys($data)]);

        return redirect()->route('settings.index', ['tab' => $tab])->with('success', 'Settings saved.');
    }

    /** Quick connection checks from the settings page. */
    public function test(Request $request, string $what)
    {
        $version = Setting::get('graph_version', 'v26.0');

        try {
            if ($what === 'meta') {
                $res = Http::timeout(15)->get("https://graph.facebook.com/{$version}/me", [
                    'access_token' => Setting::get('meta_page_access_token'), 'fields' => 'id,name',
                ]);
                $msg = $res->ok() ? 'Meta connected: page "'.$res->json('name').'" ('.$res->json('id').')' : 'Meta error: '.$res->json('error.message');
            } elseif ($what === 'whatsapp') {
                $res = Http::withToken(Setting::get('wa_access_token'))->timeout(15)
                    ->get("https://graph.facebook.com/{$version}/".Setting::get('wa_phone_number_id'), ['fields' => 'display_phone_number,verified_name']);
                $msg = $res->ok() ? 'WhatsApp connected: '.$res->json('verified_name').' ('.$res->json('display_phone_number').')' : 'WhatsApp error: '.$res->json('error.message');
            } elseif ($what === 'ai') {
                $res = null;
                $client = (new \App\Services\Ai\AiClientFactory)->make();
                $out = $client->structured('Reply via the tool.', 'Say OK.', 'ping', ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']]);
                $msg = 'AI connected: '.$client->provider().' / '.$client->model().' responded'.(isset($out['ok']) ? '.' : ' (unexpected format).');
            } elseif ($what === 'email') {
                $to = $request->user()->email;
                Mail::raw('This is a test email from your CRM. Your email settings are working.', fn ($m) => $m->to($to)->subject('CRM test email'));
                $res = null;
                $msg = "Test email sent to {$to}.";
            } else {
                abort(404);
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Test fail: '.$e->getMessage());
        }

        return back()->with(($res === null || $res->ok()) ? 'success' : 'error', $msg);
    }
}
