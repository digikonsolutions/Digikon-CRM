# Dummy leads & full-flow testing from the terminal

Is guide ki commands se aap terminal se dummy leads daal kar **poora sales flow** test kar sakte hain —
Meta se lead fetch karne ke ilawa sab kuch (landing page → Hot → follow-ups → WhatsApp chat → AI → meeting → proposal PDF → invoice PDF).

> Ye commands **testing** ke liye hain. Live CRM par sirf apni test numbers / emails ke sath chalayein.

---

## 0. Ek dafa setup

```bash
cd path/to/digikon-crm
php artisan migrate --force          # naye tables (invoices, packages, email follow-ups)
php artisan db:seed --force          # default service packages, email follow-up steps, automation settings
```

`.env` mein testing ke liye:

```
MAIL_MAILER=log        # emails send nahi hongi, storage/logs/laravel.log mein likhi jayengi
QUEUE_CONNECTION=database
```

- **AI key nahi hai?** Koi masla nahi — terminal commands khud **offline analyser** use karti hain
  (English / Roman Urdu keywords: service, budget, timeline, "kal 4 baje call kar lein" waghera).
  Asal AI ke liye Settings → AI Analysis mein key dalein. Wahan "Offline rules" provider bhi select kar sakte hain.
- **WhatsApp API configure nahi?** WhatsApp messages "failed" dikhenge (wajah ke sath), baqi flow email se chalta rahega.
- Terminal commands queued jobs (proposal / invoice / notifications) foran chala deti hain. Server par ye kaam cron (`schedule:run`) karta hai.

---

## 1. Poora flow ek command mein

```bash
php artisan crm:flow-demo
```

Ye step by step dikhata hai:

1. Landing-page lead banti hai → **Hot** + agent ko assign + follow-ups schedule
2. Jo follow-ups abhi due hain bheje jate hain (instant thank-you **email**, WhatsApp template)
3. Customer ke 3 WhatsApp messages (webhook ke through) → AI lead ko segregate karta hai →
   chat mein "Kal 4 baje call kar lein" → **meeting khud book** + customer ko **confirmation** (WhatsApp + email)
4. Meeting **Completed** → lead "Meeting Done" → **proposal PDF** (Service Package se) email + WhatsApp
5. Proposal **accepted** → lead Won → **invoice PDF** (50% advance) email + WhatsApp
6. Lead ki poori timeline print hoti hai

Options: `--phone=03001234567` (apna number), `--local-ai` (key hone ke bawajood offline analyser).

---

## 2. Dummy leads daalna

```bash
php artisan crm:dummy-leads              # 5 leads
php artisan crm:dummy-leads 20           # 20 leads
php artisan crm:dummy-leads 3 --service="SEO"
php artisan crm:dummy-leads 3 --no-email # sirf phone (email follow-ups nahi jayenge)
```

Ye leads bilkul landing-page form wale raste se banti hain: duplicate check, Hot, round-robin assignment,
WhatsApp + email follow-ups, "new lead" notification. Table mein ID, phone, owner aur follow-ups dikhte hain.

## 3. Customer ban kar WhatsApp message bhejna

```bash
php artisan crm:simulate-whatsapp 03001234567 "Assalam o alaikum, mujhe website chahiye"
php artisan crm:simulate-whatsapp 03001234567 "Budget 80k hai, next month start karna hai" "Kal 4 baje call kar lein"
```

- Message asal `/webhooks/whatsapp` endpoint se guzarta hai (App Secret ho to signature bhi lagta hai).
- Naya number ho to nayi WhatsApp lead ban jati hai (`--name="Ali Khan"`).
- Phir AI poori chat parhta hai aur result dikhata hai: temperature, qualification, service, budget, timeline,
  agreed meeting, aur meeting book hui ya nahi. `--no-analyze` se sirf message save hoga.

Time jo samajh aate hain: `aaj / kal / parson / today / tomorrow / Monday… / peer, mangal, budh, jumerat, juma, hafta, itwar`
+ `4 baje / 11 am / 3:30 pm` (+ `subah / shaam`). "zoom / google meet / online" → online meeting, "office / visit" → in-person, warna phone call.

## 4. Meeting success → proposal

```bash
php artisan crm:meeting-done 12 --outcome="Wants 200 products, COD needed"
php artisan crm:meeting-done 03001234567
```

Lead ID ya phone. Agar meeting future mein hai to testing ke liye usay "abhi" par shift kar deti hai.
Meeting Completed → lead "Meeting Done" → matching **Service Package** se proposal PDF ban kar email + WhatsApp hota hai,
aur lead "Proposal Sent" ho jati hai. Command PDF ka link bhi deti hai.

## 5. Proposal accept → invoice

```bash
php artisan crm:accept-proposal 12
```

Proposal Accepted → lead Won → invoice PDF (Settings → Sales Automation ke advance % aur due days ke sath) email + WhatsApp.

---

## 6. Landing-page API ko curl se test karna

Linux / Mac / Git Bash:

```bash
curl -X POST http://localhost:8000/api/v1/public/leads \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test Lead","phone":"03001234567","email":"test@example.com","interested_service":"Website Development","utm_source":"facebook","utm_campaign":"oct-test"}'
```

Windows PowerShell:

```powershell
Invoke-RestMethod -Method Post -Uri http://localhost:8000/api/v1/public/leads -ContentType "application/json" `
  -Body '{"name":"Test Lead","phone":"03001234567","email":"test@example.com","interested_service":"Website Development"}'
```

Jawab: `{"ok":true,"message":"Thank you! ...","reference":"..."}`. (Ek IP se 10 per minute ki limit hai.)
Agar Lead Sources mein form key on hai to header `X-Form-Key: pf_...` bhi bhejein.

---

## 7. Kahan dekhein

| Kya | Kahan |
|---|---|
| Lead, Hot badge, timeline, AI result, meeting, proposal, invoice | Leads → lead kholen |
| Chat + AI segregation | WhatsApp & AI |
| Meetings (AI wali par "booked by AI" badge) | Meetings Calendar |
| Proposals / Invoices (PDF preview, send again, mark paid) | Proposals / Invoices |
| Bheji gayi emails (MAIL_MAILER=log) | `storage/logs/laravel.log` |
| Prices jo proposal mein jati hain | Management → Service Packages |
| Har automation on/off, confirmation message, invoice % | Management → Sales Automation |
| WhatsApp + email follow-up steps | Management → Follow-up Automation |

## 8. Test data saaf karna (sirf local)

```bash
php artisan migrate:fresh --seed        # ⚠️ SAARA data delete — live server par kabhi nahi
```

Local demo data (8 leads, chats, meetings): `php artisan db:seed --class=DemoDataSeeder` (production mein chalne se inkaar karta hai).

## 9. Automated tests

```bash
php artisan test
```

55 tests (flow, permissions, Meta webhook, landing page, AI, reports, meetings, invoices).
