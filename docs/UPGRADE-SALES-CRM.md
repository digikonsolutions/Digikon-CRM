# Digikon CRM — Sales CRM Upgrade (October 2026)

The existing Laravel 12 CRM was upgraded in place. Stack is unchanged: **plain Blade + vanilla JS, no Livewire, no Spatie** — roles & permissions are built into the CRM. The theme (`public/css/app.css`, DM Serif Display + Manrope, deep teal / green) was extended, not replaced. No new Composer packages.

---

## 0. Update 2 — Automatic sales flow

| Step | What happens automatically | Switch |
|---|---|---|
| Landing-page lead | Marked **Hot**, assigned, WhatsApp + **email** follow-ups scheduled | Sales Automation |
| Customer agrees a time in WhatsApp ("kal 4 baje call kar lein") | AI books the meeting for the lead owner (conflicts are never double-booked) and sends a **confirmation on WhatsApp + email** | Sales Automation |
| Meeting marked **Completed** / lead status **Meeting Done** (new stage) | **Proposal PDF** built from the matching **Service Package** and emailed (+ WhatsApp document); lead → Proposal Sent | Sales Automation |
| Proposal **accepted** / lead **Won** (or right after the proposal) | **Invoice PDF** (advance %, due date, bank details) emailed (+ WhatsApp document) | Sales Automation |

New: `invoices`, `service_packages` tables (migration `2026_10_20_000002_sales_automation.php`), email channel for follow-up steps,
Invoices pages (PDF preview, resend, mark paid / cancelled — permission `invoices.manage`), Service Packages page,
offline AI provider (no key — for testing), terminal test commands. See **docs/TESTING-DUMMY-LEADS.md**.

Before going live: edit the **sample prices** in Management → Service Packages and the **bank details** in Management → Sales Automation.

## 1. Deploy steps

```bash
# 1. BACK UP THE DATABASE FIRST
mysqldump -u USER -p digikon_crm > backup-before-upgrade.sql

# 2. upload the new code (vendor/ is unchanged), then:
php artisan migrate --force          # one additive migration, existing data kept
php artisan db:seed --force          # idempotent: adds Sales Manager demo user, default tags & settings only if missing
php artisan optimize:clear
```

The migration `2026_10_20_000001_upgrade_sales_crm.php` only **adds** columns/tables, back-fills
assignment history from the current `leads.assigned_to`, and copies `created_at` → `occurred_at`
for old activities. Tested up + down on MariaDB 10.11 and SQLite with existing data.

**Cron (required, one line):**
```
* * * * * cd /path/to/crm && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler now also processes the queue (`queue:work --stop-when-empty` every minute), so
Meta leads, notifications and AI analysis work on shared hosting. If you run Supervisor:
set `CRM_SCHEDULER_RUNS_QUEUE=false` and run `php artisan queue:work --tries=3`.

| Scheduled command | When | What |
|---|---|---|
| `crm:send-followups` | every minute | automated WhatsApp sequence (existing) |
| `crm:meeting-reminders` | every 5 min | reminder N min before meetings + "outcome missing" alerts |
| `crm:overdue-followups` | every 15 min | one alert per agent for newly overdue follow-ups |
| `crm:snapshot-reports` | 00:20 PKT | daily / weekly (Sun) / monthly (last day) snapshots — idempotent |
| `crm:analyze-conversations --limit=50` | manual | queue AI analysis for existing chats |

---

## 2. What was built

**Lead assignment** — `LeadAssignmentService` is the only place ownership changes (row lock + transaction). Assign / reassign (reason required) / unassign, bulk assign, bulk round-robin / workload distribution, full history (`lead_assignments`) in a modal on the lead page, notifications to the agent, inactive / non-sales users rejected. Users deleted → their open history rows are closed and leads become unassigned.

**Activities & manual call logging** — Log call (outcome, duration mm:ss, notes, time, next follow-up) / WhatsApp / email / meeting / follow-up / note. Actor is always the logged-in user (a `user_id` in the request is ignored). Manual logs are marked `is_manual` and **never** `is_verified` (reserved for a future telephony integration). No edit/delete of activity records. Manual follow-ups live in `lead_tasks`; `leads.next_follow_up_at` = earliest of open tasks and pending automated WhatsApp follow-ups.

**Reports** — Daily / weekly / monthly / custom, agent filter, trend vs previous period, per-agent team table, CSV export, nightly snapshots. Every metric is SQL-aggregated and defined on the page (unique leads ≠ calls; booked ≠ held; assignment ≠ conversion; revenue only from accepted proposals). Agents always get only their own numbers.

**Meetings calendar** — Month / week / day / agenda views (server-rendered, no JS library), filters, book from lead page, reschedule / cancel (reason) / outcome (completed / no-show, only after start), conflict detection (managers can override), reminders, missed-outcome alerts, history from the audit log, `.ics` download for Google / Outlook.

**Meta Lead Ads** — Webhook verifies signature (when App Secret saved), stores a `lead_source_events` row (unique `source + leadgen_id`) and queues `ProcessMetaLeadJob`. The job fetches the lead with the token in the Authorization header, retries temporary errors (5 tries, backoff), marks expired tokens (code 190) as permanent failures and alerts admins. Captures campaign / ad set / ad IDs + names, form, page, platform (fb/ig), submit time.

**Landing pages** — `POST /api/v1/public/leads`, shared `LeadIngestionService`, rate limit (10/min, 60/hour per IP), honeypot `hp_field`, optional form key, CORS allow-list editable in Lead Sources, `submission_id` idempotency, UTM + referrer + landing URL stored. Response never contains internal IDs.

**AI WhatsApp lead segregation** (what the original prompt called "AI chatbot") — reads the lead's **complete WhatsApp conversation** (`whatsapp_messages`) and returns: summary, service interest, budget, timeline, industry, buying intent, hot/warm/cold, qualification, suggested stage, next steps, tags, confidence. Runs on demand ("Analyse WhatsApp chat") or automatically after customer messages (debounced, unique per lead).
- Provider abstraction: Anthropic (forced tool call) or OpenAI (JSON mode). Key in Settings → AI Analysis or `.env`.
- Chat is framed as untrusted data; output is validated server-side: unknown keys dropped, enums enforced, **every fact needs a quote that literally exists in that lead's messages** — otherwise it is discarded.
- Stored separately in `lead_analyses` (history kept); chat messages are never changed.
- Safe auto-apply fills only empty fields, sets temperature/qualification only above the confidence threshold and never over a manual value, never changes the sales stage.
- Reviewers (AI Reviews page) approve, correct (corrections become `manual` and are protected) or reject (reverts untouched auto-applied values).

**Segmentation** — Status (now incl. Negotiation), temperature, qualification and buying intent are separate fields with history (`lead_status_changes`, incl. owner at time of change), tags (manual / AI), industry, timeline, and an explainable rule-based **lead score** (0–100, reasons shown on the lead).

**Roles & permissions** — `admin`, `manager` (Sales Manager), `agent` (Sales Agent), `marketing`. 23 permissions in `config/crm.php`, editable matrix at Management → Roles & Permissions. Enforced by `permission:` middleware, `LeadPolicy` / `MeetingPolicy`, query scopes (`Lead::visibleTo`, `Meeting::visibleTo`). No super-admin bypass; admin keeps settings + user management to avoid lock-out.

**Notifications** — In-app bell + optional email per user, preferences page, queued, de-duplicated with claim flags: lead assigned/reassigned, new inbound lead, meeting reminder, missed meeting, overdue follow-ups, ingestion failed, daily report.

**Audit log** — assignments, status/segmentation changes, lead edits/deletes, meetings, AI reviews, settings, permissions, exports, users.

---

## 3. Endpoints

Public:
```
POST /api/v1/public/leads
Content-Type: application/json
{"name":"Sana","phone":"03331234567","email":"sana@example.com","interested_service":"SEO",
 "utm_source":"google","utm_campaign":"seo-oct","landing_page_url":"https://site.com/seo",
 "submission_id":"3f1c2b8e-...","hp_field":""}

201 {"ok":true,"message":"Thank you! Our team will contact you shortly.","reference":"3f1c2b8e-..."}
422 {"ok":false,"message":"Please check the form and try again.","errors":{"phone":["..."]}}
429 {"ok":false,"message":"Too many submissions. Please try again in a minute."}
```
Webhooks (unchanged URLs): `GET|POST /webhooks/meta`, `GET|POST /webhooks/whatsapp`.

Authenticated (session, CSRF) — return JSON when called with `Accept: application/json`:
`POST /leads/{lead}/assign`, `POST /leads/bulk-assign`, `POST /leads/{lead}/activities`, `POST /leads/{lead}/tasks`, `POST /tasks/{task}`, `POST /leads/{lead}/meetings`, `POST /meetings/{meeting}`, `GET /meetings/{meeting}/ics`, `POST /leads/{lead}/analyses`, `POST /analyses/{analysis}/approve|reject`, `POST /leads/{lead}/segment`, `GET /reports/export`, `GET /leads/export`.

---

## 4. Environment variables (placeholders)

```
APP_TIMEZONE=Asia/Karachi
QUEUE_CONNECTION=database
AI_PROVIDER=anthropic          # or openai
AI_API_KEY=your-key-here       # or set in Settings → AI Analysis
AI_MODEL=                      # empty = default
CRM_ALLOWED_ORIGINS=https://www.example.com
CRM_SCHEDULER_RUNS_QUEUE=true
```
Meta / WhatsApp tokens stay in Settings (as before) and are never sent to the browser or logs.

---

## 5. Remaining manual setup

1. Settings → **AI Analysis**: add API key, click *Test AI connection*, choose auto-analyse on/off.
2. Settings → **Meta Lead Ads**: save the **App Secret** (signature check is skipped without it), test connection. App must be Live with Advanced Access for `leads_retrieval`.
3. Management → **Lead Sources**: add your landing-page domains, paste the snippet into the form.
4. Management → **Sales Agents**: set roles (Sales Manager / Sales Agent / Marketing).
5. Set the cron line above.

## 6. Tests

`php artisan test` — 55 feature tests, 506 assertions (MariaDB + SQLite) (assignment, activities, reports & timezone, meetings, Meta webhook, landing page, AI validation/corrections/idempotency, automatic flow, invoices, every page per role).

## 7. Known limitations / next steps

- Teams: the app has no team structure, so Sales Managers see all leads. Add `team_id` if managers should only see their own team.
- No token-based REST API for mobile apps (would need Laravel Sanctum).
- Google Calendar two-way sync is not built; agents use the `.ics` download.
- Calls are manual logs; telephony integration (verified calls) is a future step.
- WhatsApp media (images/voice notes) is stored as `[image]`/`[audio]` placeholders, so the AI cannot read them.
- Local testing only: `php artisan db:seed --class=DemoDataSeeder` (refuses to run in production).
