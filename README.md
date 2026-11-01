# Digikon CRM (Laravel 12)

Meta (Facebook/Instagram) Lead Ads → CRM → automatic lead distribution → WhatsApp follow-ups → branded PDF proposals by email.
Built with Laravel 12, Blade and one plain CSS file (`public/css/app.css`). No npm, Vite or Tailwind required.

Theme: Digikon Brand Guide 02 — Deep Teal / Green (`#81D870`, `#1C2E33`, `#03171D`, `#FFFFFF`) applied as a light theme (lightest green/teal tints for backgrounds, deep teal text, green actions) with DM Serif Display + Manrope (fonts are bundled in `public/fonts`, no internet needed).

> **Full API connection guide:** `docs/Digikon-CRM-Integration-Guide.pdf`

## Features

| Feature | Where |
|---|---|
| Leads arrive automatically from Meta Lead Ads (webhook) | `WebhookController@meta`, `MetaLeadService` |
| Leads distributed to agents (Round robin / Least open leads / Manual) | `LeadDistributor`, Settings → General |
| Duplicate detection (same phone or email) | `LeadService` |
| Automatic WhatsApp follow-up sequence (Instant, Day 1, Day 3 …) | WhatsApp Follow-ups page, `FollowupService` |
| Follow-ups stop when the customer replies or sends STOP | `WebhookController@whatsapp` |
| Manual WhatsApp (text or template) + chat history on each lead | Lead detail page |
| Drag-and-drop sales pipeline | Leads → Pipeline |
| Proposals with 3 PDF templates (Modern, Classic, Minimal) | Proposals |
| Your logo, signature PNG and color theme on proposals | Settings → Proposal Branding |
| Email proposals with the PDF attached | Proposal page → Send |
| Admin / Agent roles (agents see only their own leads) | Team / Agents |

---

## 1. Installation

Requirements: **PHP 8.2+**, Composer, MySQL (XAMPP is fine) or SQLite.

**XAMPP (Windows):** open `C:\xampp\php\php.ini`, remove the `;` in front of these lines, then restart your terminal:

```
extension=fileinfo
extension=gd
extension=zip
extension=mbstring
extension=pdo_mysql
```

Create a database called `digikon_crm` in phpMyAdmin, then run:

```bash
composer install
copy .env.example .env        # macOS/Linux: cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Open http://localhost:8000 and log in with **admin@crm.test / password**
(sample agents: ali@crm.test and sara@crm.test, password `password`). Change these passwords right away from the Team page.

**Run the follow-up scheduler** (keep it open in a second terminal):

```bash
php artisan schedule:work
```

On a live server, add this cron job (runs every minute):

```
* * * * * cd /path/to/crm && php artisan schedule:run >> /dev/null 2>&1
```

Create a sample lead for testing: `php artisan crm:fake-lead 03001234567 --name="Ahmed"`

---

## 2. Connecting the APIs (summary)

The step-by-step guide with screenshots-level detail is in **`docs/Digikon-CRM-Integration-Guide.pdf`**. In short:

| Service | What you paste into the CRM | CRM location |
|---|---|---|
| Meta Lead Ads | Verify token, long-lived Page access token, App secret | Settings → Meta Lead Ads |
| WhatsApp Cloud API | Phone number ID, WhatsApp Business Account ID, System-user access token | Settings → WhatsApp API |
| Email (SMTP) | Host, port, username, password / app password, sender address | Settings → Email (SMTP) |

Webhook URLs (your site must be on public **HTTPS**; for local testing use `ngrok http 8000`):

- Meta Lead Ads (Page object, `leadgen` field): `https://your-domain.com/webhooks/meta`
- WhatsApp (`messages` field): `https://your-domain.com/webhooks/whatsapp`

### WhatsApp templates
WhatsApp only allows a business to start a conversation (or message after 24 hours) with an **approved template**.
Create these in WhatsApp Manager → Message templates (names used by the default follow-up steps):

| Template name | Example body | CRM variables |
|---|---|---|
| `lead_welcome` | Hi {{1}}, thanks for contacting {{2}}! Our team will reach out shortly. | `{name}\|{company}` |
| `lead_followup_1` | Hi {{1}}, just checking whether you are still interested in {{2}}? | `{name}\|{service}` |
| `lead_followup_2` | Hi {{1}}, this is {{2}}. Shall we schedule a quick call? | `{name}\|{agent}` |

Placeholders: `{name}` `{agent}` `{agent_phone}` `{company}` `{service}`.

**Follow-ups stop automatically when:** the customer replies, sends "STOP", the lead becomes Qualified/Won/Lost, the lead is paused, or the global switch is OFF.

---

## 3. Proposals

1. Settings → **Proposal Branding**: logo, signature (transparent PNG), primary/accent colors, signer name, default introduction and terms.
2. Settings → **Email (SMTP)**: for Gmail use `smtp.gmail.com`, port 587, TLS and a Google **App Password**.
3. Open a lead → **+ Create Proposal** → choose a template → add items and prices → Save.
4. Review the preview → write the email → **Send** (PDF attached; lead status becomes "Proposal Sent").
5. When the client accepts, set the proposal to **Accepted** — the lead is marked **Won** automatically.

To add a new PDF design, copy a file in `resources/views/pdf/proposals/` and register its name in `Proposal::TEMPLATES`.

---

## Folder map

```
app/Services/                  LeadService, LeadDistributor, MetaLeadService, WhatsAppService, FollowupService, ProposalPdf
app/Http/Controllers/          Lead, Proposal, FollowupStep, Setting, User, Webhook, Dashboard, Auth
resources/views/               Blade pages (plain CSS classes)
resources/views/pdf/proposals/ modern / classic / minimal PDF templates
public/css/app.css             All styling (brand theme)
public/fonts/                  DM Serif Display + Manrope (self-hosted)
routes/web.php                 Routes + webhooks
routes/console.php             Follow-up scheduler (every minute)
docs/                          Integration guide PDF
```

## Going live checklist
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain.com`, HTTPS enabled.
- `php artisan storage:link` (needed for logo/signature).
- `storage/` and `bootstrap/cache/` must be writable.
- Cron job added (otherwise automatic follow-ups will not be sent).
