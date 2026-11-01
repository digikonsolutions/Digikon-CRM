@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="page-head"><h1>Settings</h1></div>

<div class="tabs">
    @foreach(['general' => 'General & Distribution', 'meta' => 'Meta Lead Ads', 'whatsapp' => 'WhatsApp API', 'ai' => 'AI Analysis', 'automation' => 'Sales Automation', 'email' => 'Email (SMTP)', 'branding' => 'Proposal Branding'] as $key => $label)
        <a href="{{ route('settings.index', ['tab' => $key]) }}" class="{{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="tab" value="{{ $tab }}">

    {{-- ================= GENERAL ================= --}}
    @if($tab === 'general')
    <div class="card">
        <h2>Company</h2>
        <div class="form-row">
            <div class="form-group"><label>Company name *</label><input type="text" name="company_name" class="form-control" value="{{ old('company_name', $s('company_name')) }}" required></div>
            <div class="form-group"><label>Company email</label><input type="email" name="company_email" class="form-control" value="{{ old('company_email', $s('company_email')) }}"></div>
            <div class="form-group"><label>Company phone</label><input type="text" name="company_phone" class="form-control" value="{{ old('company_phone', $s('company_phone')) }}"></div>
            <div class="form-group"><label>Website</label><input type="text" name="company_website" class="form-control" value="{{ old('company_website', $s('company_website')) }}"></div>
            <div class="form-group"><label>Default country code</label><input type="text" name="default_country_code" class="form-control" value="{{ old('default_country_code', $s('default_country_code', '92')) }}"><div class="help">Pakistan = 92. Local numbers like 0300... are stored as 92300...</div></div>
            <div class="form-group"><label>Default currency</label><input type="text" name="currency" class="form-control" value="{{ old('currency', $s('currency', 'PKR')) }}"></div>
        </div>
        <div class="form-group"><label>Address</label><textarea name="company_address" class="form-control" rows="2">{{ old('company_address', $s('company_address')) }}</textarea></div>
    </div>
    <div class="card">
        <h2>Lead Distribution</h2>
        <p class="help" style="margin-bottom:12px">How new leads are shared between your sales agents.</p>
        @foreach($methods as $key => $label)
            <label class="check" style="margin-bottom:8px"><input type="radio" name="distribution_method" value="{{ $key }}" @checked(old('distribution_method', $s('distribution_method', 'round_robin')) === $key)> {{ $label }}</label>
        @endforeach
        <p class="help">Choose which agents receive leads with the "Receives leads" option on the <a href="{{ route('users.index') }}">Team</a> page. Only active Sales Agents / Sales Managers can own leads.</p>
    </div>
    <div class="card">
        <h2>Meetings</h2>
        <div class="form-group" style="max-width:280px">
            <label>Reminder before a meeting (minutes)</label>
            <input type="number" name="meeting_reminder_minutes" class="form-control" min="5" max="1440" value="{{ old('meeting_reminder_minutes', $s('meeting_reminder_minutes', 30)) }}">
        </div>
    </div>
    @endif

    {{-- ================= AUTOMATION ================= --}}
    @if($tab === 'automation')
    <div class="card">
        <h2>Automatic sales flow</h2>
        <ol class="steps">
            <li><label class="check"><input type="checkbox" name="auto_landing_hot" value="1" @checked($s('auto_landing_hot', '1') === '1')> Landing-page leads are marked <strong>Hot</strong></label>
                <div class="help">They are also assigned and get the WhatsApp + email follow-ups from <a href="{{ route('followups.index') }}">Follow-up Automation</a>.</div></li>
            <li><label class="check"><input type="checkbox" name="ai_auto_book_meetings" value="1" @checked($s('ai_auto_book_meetings', '1') === '1')> Book the meeting the customer agrees to in WhatsApp (AI reads the chat)</label>
                <div class="form-group mt" style="max-width:260px"><label>Meeting length (minutes)</label><input type="number" name="auto_meeting_minutes" class="form-control" min="10" max="240" value="{{ old('auto_meeting_minutes', $s('auto_meeting_minutes', 30)) }}"></div></li>
            <li><label class="check"><input type="checkbox" name="auto_meeting_confirmation" value="1" @checked($s('auto_meeting_confirmation', '1') === '1')> Send the customer a confirmation on WhatsApp + email</label>
                <div class="form-group mt"><label>Confirmation message</label><textarea name="meeting_confirmation_message" class="form-control" rows="3">{{ old('meeting_confirmation_message', $s('meeting_confirmation_message', \App\Services\SalesAutomation::DEFAULT_CONFIRMATION)) }}</textarea>
                <div class="help">Placeholders: {name} {agent} {agent_phone} {company} {date} {time} {type} {link}</div></div></li>
            <li><label class="check"><input type="checkbox" name="auto_proposal_after_meeting" value="1" @checked($s('auto_proposal_after_meeting', '1') === '1')> When a meeting is marked <strong>Completed</strong> (or the lead status is set to <strong>Meeting Done</strong>) → create the proposal PDF from <a href="{{ route('packages.index') }}">Service Packages</a> and send it</label></li>
            <li>
                <label for="inv-trigger">Invoice PDF</label>
                <select id="inv-trigger" name="auto_invoice_trigger" class="form-control" style="max-width:420px">
                    @foreach(['on_accept' => 'Create + send when the proposal is accepted / lead Won (recommended)', 'after_proposal' => 'Create + send right after the proposal is sent', 'off' => 'Off (create invoices by hand)'] as $k => $v)
                        <option value="{{ $k }}" @selected($s('auto_invoice_trigger', 'on_accept') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </li>
        </ol>
        <label class="check"><input type="checkbox" name="send_documents_whatsapp" value="1" @checked($s('send_documents_whatsapp', '1') === '1')> Also send proposal / invoice PDFs as WhatsApp documents</label>
        <p class="help">WhatsApp only delivers free messages and documents within 24 hours of the customer's last message — outside that window the email still goes out and the agent is notified.</p>
    </div>
    <div class="card">
        <h2>Invoices</h2>
        <div class="form-row-3">
            <div class="form-group"><label>Number prefix</label><input type="text" name="invoice_prefix" class="form-control" value="{{ old('invoice_prefix', $s('invoice_prefix', 'INV')) }}"></div>
            <div class="form-group"><label>Advance due now (%)</label><input type="number" name="invoice_advance_percent" class="form-control" min="1" max="100" step="0.01" value="{{ old('invoice_advance_percent', $s('invoice_advance_percent', 50)) }}"><div class="help">100 = full amount</div></div>
            <div class="form-group"><label>Due in (days)</label><input type="number" name="invoice_due_days" class="form-control" min="0" max="120" value="{{ old('invoice_due_days', $s('invoice_due_days', 7)) }}"></div>
        </div>
        <div class="form-group"><label>Payment details (bank / JazzCash / EasyPaisa)</label><textarea name="invoice_payment_instructions" class="form-control" rows="4">{{ old('invoice_payment_instructions', $s('invoice_payment_instructions')) }}</textarea></div>
        <div class="form-group"><label>Invoice notes</label><textarea name="invoice_notes" class="form-control" rows="2">{{ old('invoice_notes', $s('invoice_notes')) }}</textarea></div>
        <div class="form-group"><label>Invoice email text</label><textarea name="invoice_email_body" class="form-control" rows="6" placeholder="Dear {client}, ...">{{ old('invoice_email_body', $s('invoice_email_body')) }}</textarea>
            <div class="help">Placeholders: {client} {company} {number} {amount_due} {total} {due_date} {payment}</div></div>
    </div>
    @endif

    {{-- ================= AI ================= --}}
    @if($tab === 'ai')
    <div class="card">
        <h2>AI WhatsApp analysis</h2>
        <p class="help" style="margin-bottom:14px">The AI reads a lead's complete WhatsApp conversation and segregates the lead: summary, hot / warm / cold, qualification, service interest, budget, timeline, industry, buying intent, tags and next steps. Every value must be backed by a quote from the chat, otherwise it is ignored.</p>
        <div class="form-row">
            <div class="form-group">
                <label>Provider</label>
                <select name="ai_provider" class="form-control" id="ai-provider">
                    @foreach($aiProviders as $k => $v)<option value="{{ $k }}" @selected($s('ai_provider', 'anthropic') === $k)>{{ $v }}</option>@endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Model</label>
                <input type="text" name="ai_model" class="form-control" value="{{ old('ai_model', $s('ai_model')) }}" placeholder="default: {{ $aiDefaultModels[$s('ai_provider', 'anthropic')] ?? '' }}">
                <div class="help">Leave empty for the default ({{ implode(' / ', $aiDefaultModels) }}).</div>
            </div>
        </div>
        <div class="form-group">
            <label>API key {!! $s('ai_api_key') ? '<span class="badge badge-sent">saved</span>' : (config('crm.ai.api_key') ? '<span class="badge badge-sent">from .env</span>' : '') !!}</label>
            <input type="password" name="ai_api_key" class="form-control" autocomplete="off" placeholder="{{ $s('ai_api_key') ? '•••••••• saved — paste a new key to replace it' : 'sk-...' }}">
            @if($s('ai_api_key'))<label class="check mt"><input type="checkbox" name="remove_ai_api_key" value="1"> Remove saved key</label>@endif
            <div class="help">Stored on the server only — never sent to the browser. You can also set AI_PROVIDER / AI_API_KEY / AI_MODEL in .env.</div>
        </div>
    </div>
    <div class="card">
        <h2>Automation</h2>
        <label class="check" style="margin-bottom:10px"><input type="checkbox" name="ai_auto_analyze" value="1" @checked($s('ai_auto_analyze', '0') === '1')> Analyse automatically when a customer sends WhatsApp messages</label>
        <div class="form-group" style="max-width:320px">
            <label>Wait after the last customer message (minutes)</label>
            <input type="number" name="ai_debounce_minutes" class="form-control" min="1" max="240" value="{{ old('ai_debounce_minutes', $s('ai_debounce_minutes', 10)) }}">
            <div class="help">A burst of messages is analysed once, after the customer stops typing.</div>
        </div>
        <label class="check" style="margin-bottom:10px"><input type="checkbox" name="ai_auto_apply" value="1" @checked($s('ai_auto_apply', '1') === '1')> Safe auto-apply</label>
        <ul class="help">
            <li>Fills only <strong>empty</strong> lead fields (service, budget, timeline, industry, intent) and adds tags.</li>
            <li>Sets temperature / qualification only when confidence ≥ the minimum below and nobody set them by hand.</li>
            <li>Never changes the sales stage, owner, or any value a person entered. Reviewers can reject and revert.</li>
        </ul>
        <div class="form-group" style="max-width:320px">
            <label>Minimum confidence for auto temperature / qualification</label>
            <input type="number" name="ai_min_confidence" class="form-control" step="0.05" min="0.3" max="1" value="{{ old('ai_min_confidence', $s('ai_min_confidence', 0.7)) }}">
        </div>
        <p class="help">Existing leads: run <code>php artisan crm:analyze-conversations --limit=50</code> on the server to analyse old chats.</p>
    </div>
    @endif

    {{-- ================= META ================= --}}
    @if($tab === 'meta')
    <div class="card">
        <h2>Webhook details</h2>
        <p class="help" style="margin-bottom:12px">Enter these values in your Meta App → Webhooks → Page.</p>
        <label>Callback URL</label>
        <span class="code">{{ $webhookMeta }}</span>
        <div class="form-group mt">
            <label>Verify token *</label>
            <input type="text" name="webhook_verify_token" class="form-control" value="{{ old('webhook_verify_token', $s('webhook_verify_token')) }}" required>
            <div class="help">Any secret phrase you choose. Use the same value as the "Verify token" in Meta for both the Page (leads) and WhatsApp webhooks.</div>
        </div>
    </div>
    <div class="card">
        <h2>Access</h2>
        <div class="form-group">
            <label>Page Access Token (long-lived) {!! $s('meta_page_access_token') ? '<span class="badge badge-sent">saved</span>' : '' !!}</label>
            <input type="password" name="meta_page_access_token" class="form-control" placeholder="{{ $s('meta_page_access_token') ? '•••••••• saved — paste a new token to replace it' : 'EAAG...' }}" autocomplete="off">
            <div class="help">Required permissions: leads_retrieval, pages_show_list, pages_read_engagement, pages_manage_metadata, pages_manage_ads, ads_management.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>App Secret (verifies webhook signatures) {!! $s('meta_app_secret') ? '<span class="badge badge-sent">saved</span>' : '' !!}</label>
                <input type="password" name="meta_app_secret" class="form-control" placeholder="{{ $s('meta_app_secret') ? '•••••••• saved' : 'Recommended' }}" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Graph API version</label>
                <input type="text" name="graph_version" class="form-control" value="{{ old('graph_version', $s('graph_version', 'v26.0')) }}">
            </div>
        </div>
    </div>
    <div class="card">
        <h2>How to connect Meta Lead Ads</h2>
        <ol class="steps">
            <li>Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> and create a <strong>Business</strong> app linked to your business portfolio. Add the <strong>Webhooks</strong> product.</li>
            <li>In Webhooks choose the <strong>Page</strong> object → <em>Subscribe to this object</em>. Paste the Callback URL and Verify token shown above, click <strong>Verify and save</strong>, then subscribe to the <strong>leadgen</strong> field.</li>
            <li>Open <a href="https://developers.facebook.com/tools/explorer" target="_blank" rel="noopener">Graph API Explorer</a>, select your app, and request the permissions listed above. Choose your Page under "User or Page" to get a Page token, then extend it in the <a href="https://developers.facebook.com/tools/debug/accesstoken" target="_blank" rel="noopener">Access Token Debugger</a> (long-lived). Paste it above and save.</li>
            <li>Still in Graph API Explorer, send <code>POST /{page-id}/subscribed_apps?subscribed_fields=leadgen</code> using the Page token. A <code>{"success": true}</code> reply means your Page is connected.</li>
            <li>In Meta Business Suite → Settings → Integrations → <strong>Leads access</strong>, make sure your app (CRM) is allowed to access leads.</li>
            <li>Copy the <strong>App Secret</strong> from App settings → Basic into the field above, then click <strong>Test Meta connection</strong>.</li>
            <li>Send a test lead with the <a href="https://developers.facebook.com/tools/lead-ads-testing" target="_blank" rel="noopener">Lead Ads Testing Tool</a>. It should appear under Leads within a few seconds.</li>
        </ol>
        <p class="help">Your app must be in <strong>Live</strong> mode (with Advanced Access for leads_retrieval) to receive real leads from people other than app admins.</p>
        <p class="help">Incoming leads are saved first and fetched from Meta by a background job (with automatic retries). Make sure the server cron runs <code>php artisan schedule:run</code> every minute. Results and failures are listed under <a href="{{ route('lead-sources.index') }}">Lead Sources</a>.</p>
        @unless($s('meta_app_secret'))<div class="alert alert-error mt">No App Secret saved — webhook signatures are not being verified. Add the App Secret above.</div>@endunless
    </div>
    @endif

   {{-- ================= WHATSAPP ================= --}}
@if($tab === 'whatsapp')

    <div class="card">
        <h2>WhatsApp Cloud API Connection</h2>

        <p class="help">
            Connect your real WhatsApp Business Platform number to this CRM.
            Use the Phone Number ID and access token belonging to the same
            WhatsApp Business Account.
        </p>

        <div class="form-row">
            <div class="form-group">
                <label for="wa_phone_number_id">
                    Phone Number ID *
                </label>

                <input
                    type="text"
                    id="wa_phone_number_id"
                    name="wa_phone_number_id"
                    class="form-control"
                    value="{{ old('wa_phone_number_id', $s('wa_phone_number_id')) }}"
                    inputmode="numeric"
                    pattern="[0-9]+"
                    required
                >

                <div class="help">
                    This is the numeric ID of your WhatsApp phone number,
                    not the phone number itself.
                </div>
            </div>

            <div class="form-group">
                <label for="wa_business_account_id">
                    WhatsApp Business Account ID (WABA ID) *
                </label>

                <input
                    type="text"
                    id="wa_business_account_id"
                    name="wa_business_account_id"
                    class="form-control"
                    value="{{ old('wa_business_account_id', $s('wa_business_account_id')) }}"
                    inputmode="numeric"
                    pattern="[0-9]+"
                    required
                >

                <div class="help">
                    The WABA ID identifies the WhatsApp Business Account.
                    It is different from the Phone Number ID.
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="wa_access_token">
                System User Access Token *
                @if($s('wa_access_token'))
                    <span class="badge badge-sent">Token saved</span>
                @endif
            </label>

            <input
                type="password"
                id="wa_access_token"
                name="wa_access_token"
                class="form-control"
                placeholder="{{ $s('wa_access_token') ? 'Leave blank to keep the existing token' : 'Paste your access token' }}"
                autocomplete="new-password"
            >

            <div class="help">
                Generate a token for your Meta app and assign the correct
                WhatsApp Business Account assets and permissions.
                Never paste this token into a message, URL, or public log.
            </div>
        </div>

        <div class="alert alert-info">
            <strong>Important:</strong>
            Saving these settings does not register a phone number with Meta.
            Your real number must already be correctly onboarded and accessible
            to the app and token.
        </div>
    </div>

    <div class="card">
    <h2>Test Your WhatsApp Connection</h2>

    <p class="help">
        Save your settings first. Then click the existing
        "Test WhatsApp connection" button below the settings form.
        This checks whether your access token can access the
        configured Phone Number ID.
    </p>
</div>

    <div class="card">
        <h2>Incoming WhatsApp Messages</h2>

        <div class="form-group">
            <label>Webhook Callback URL</label>
            <span class="code">{{ $webhookWhatsapp }}</span>
        </div>

        <div class="form-group">
            <label>Webhook Verify Token</label>

            <input
                type="text"
                class="form-control"
                value="{{ $s('webhook_verify_token') }}"
                readonly
            >

            <div class="help">
                Configure this same verify token in your Meta app's
                WhatsApp webhook settings.
            </div>
        </div>

        <p class="help">
            Subscribe to the WhatsApp <code>messages</code> webhook field
            so customer messages and delivery status updates can reach
            the CRM.
        </p>
    </div>

    <div class="card">
        <h2>Setup Checklist</h2>

        <ol class="steps">
            <li>
                Confirm that the real phone number is connected to the
                correct WhatsApp Business Account in Meta Business Settings.
            </li>

            <li>
                Confirm that your Meta app has access to that WhatsApp
                Business Account and phone number.
            </li>

            <li>
                Generate an access token with the required WhatsApp
                permissions and appropriate asset access.
            </li>

            <li>
                Enter the real Phone Number ID, WABA ID, and access token
                in this form.
            </li>

            <li>
                Save the settings and click
                <strong>Test WhatsApp Connection</strong>.
            </li>

            <li>
                Configure the WhatsApp webhook in your Meta app and
                subscribe to the <code>messages</code> field.
            </li>

            <li>
                Test incoming messages and outgoing messages separately.
                Use an approved message template when required.
            </li>
        </ol>
    </div>

@endif

    {{-- ================= EMAIL ================= --}}
    @if($tab === 'email')
    <div class="card">
        <h2>SMTP (used to email proposals)</h2>
        <p class="help">Leave blank to use the MAIL_ settings from your .env file. For Gmail: host smtp.gmail.com, port 587, TLS, and a Google "App Password" (not your normal password).</p>
        <div class="form-row">
            <div class="form-group"><label>SMTP host</label><input type="text" name="smtp_host" class="form-control" value="{{ old('smtp_host', $s('smtp_host')) }}" placeholder="smtp.gmail.com"></div>
            <div class="form-group"><label>Port</label><input type="number" name="smtp_port" class="form-control" value="{{ old('smtp_port', $s('smtp_port', 587)) }}"></div>
            <div class="form-group"><label>Username</label><input type="text" name="smtp_username" class="form-control" value="{{ old('smtp_username', $s('smtp_username')) }}"></div>
            <div class="form-group"><label>Password {!! $s('smtp_password') ? '<span class="badge badge-sent">saved</span>' : '' !!}</label><input type="password" name="smtp_password" class="form-control" autocomplete="off"></div>
            <div class="form-group">
                <label>Encryption</label>
                <select name="smtp_encryption" class="form-control">
                    @foreach(['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $v)
                        <option value="{{ $k }}" @selected($s('smtp_encryption', 'tls') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group"><label>From email</label><input type="email" name="mail_from_address" class="form-control" value="{{ old('mail_from_address', $s('mail_from_address')) }}"></div>
            <div class="form-group"><label>From name</label><input type="text" name="mail_from_name" class="form-control" value="{{ old('mail_from_name', $s('mail_from_name')) }}"></div>
        </div>
        <div class="form-group">
            <label>Default proposal email text</label>
            <textarea name="email_body" class="form-control" rows="7" placeholder="Dear {client}, ...">{{ old('email_body', $s('email_body')) }}</textarea>
            <div class="help">Placeholders: {client} {title} {number} {total} {valid_until} {sender} {company}</div>
        </div>
    </div>
    @endif

    {{-- ================= BRANDING ================= --}}
    @if($tab === 'branding')
    <div class="card">
        <h2>Logo, signature & colors</h2>
        <div class="form-row">
            <div class="form-group">
                <label>Company logo (PNG/JPG, max 2MB)</label>
                @if($s('logo_path'))
                    <p><img src="{{ asset('storage/'.$s('logo_path')) }}" class="preview-img" alt="logo"></p>
                    <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>
                @endif
                <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg">
            </div>
            <div class="form-group">
                <label>Signature (transparent PNG)</label>
                @if($s('signature_path'))
                    <p><img src="{{ asset('storage/'.$s('signature_path')) }}" class="preview-img" alt="signature"></p>
                    <label class="check"><input type="checkbox" name="remove_signature" value="1"> Remove signature</label>
                @endif
                <input type="file" name="signature" class="form-control" accept="image/png">
            </div>
            <div class="form-group"><label>Primary color</label><input type="color" name="primary_color" class="form-control" value="{{ old('primary_color', $s('primary_color', '#03171D')) }}"></div>
            <div class="form-group"><label>Accent color</label><input type="color" name="accent_color" class="form-control" value="{{ old('accent_color', $s('accent_color', '#81D870')) }}"></div>
            <div class="form-group"><label>Signer name</label><input type="text" name="signer_name" class="form-control" value="{{ old('signer_name', $s('signer_name')) }}"></div>
            <div class="form-group"><label>Signer title</label><input type="text" name="signer_title" class="form-control" value="{{ old('signer_title', $s('signer_title')) }}" placeholder="CEO"></div>
        </div>
    </div>
    <div class="card">
        <h2>Proposal defaults</h2>
        <div class="form-row">
            <div class="form-group">
                <label>Default template</label>
                <select name="default_template" class="form-control">
                    @foreach($templates as $k => $v)
                        <option value="{{ $k }}" @selected($s('default_template', 'modern') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group"><label>Number prefix</label><input type="text" name="proposal_prefix" class="form-control" value="{{ old('proposal_prefix', $s('proposal_prefix', 'PRP')) }}"></div>
        </div>
        <div class="form-group"><label>Default introduction</label><textarea name="default_intro" class="form-control">{{ old('default_intro', $s('default_intro')) }}</textarea></div>
        <div class="form-group"><label>Default terms</label><textarea name="default_terms" class="form-control" rows="5">{{ old('default_terms', $s('default_terms')) }}</textarea></div>
        <div class="form-group"><label>PDF footer text</label><input type="text" name="proposal_footer" class="form-control" value="{{ old('proposal_footer', $s('proposal_footer')) }}"></div>
    </div>
    @endif

    <button class="btn">Save Settings</button>
</form>

@if(in_array($tab, ['meta', 'whatsapp', 'email', 'ai']))
    <form method="POST" action="{{ route('settings.test', $tab) }}" class="mt">
        @csrf
        <button class="btn btn-outline">Test {{ $tab === 'email' ? 'email (sent to your login email)' : (['meta' => 'Meta', 'whatsapp' => 'WhatsApp', 'ai' => 'AI'][$tab]).' connection' }}</button>
    </form>
@endif
@endsection
