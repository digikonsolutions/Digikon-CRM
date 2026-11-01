# DIGIKON "Talk to us" form → CRM API (Hot lead)

**API:** `POST https://YOUR-CRM-DOMAIN/api/v1/public/leads`

Form submit hote hi CRM mein lead banti hai jo:
- **Hot** mark hoti hai,
- round-robin se agent ko assign hoti hai,
- us par email follow-ups lagte hain (aur WhatsApp follow-ups bhi, agar form mein number ho),
- managers ko "new lead" notification jati hai.

Duplicate check bhi hota hai: same email ya phone ki open lead pehle se ho to nayi lead nahi banti.
Saath mein UTM aur landing page URL bhi save hote hain.

---

## A. CRM (Laravel project) mein — kahan kya hai

| File | Kya karti hai | Naya / badla |
|---|---|---|
| `routes/api.php` | Route: `POST /api/v1/public/leads` (rate limit 10 per minute) | naya |
| `bootstrap/app.php` | `api: __DIR__.'/../routes/api.php',` wali line (api routes on) | badla |
| `app/Http/Controllers/PublicLeadController.php` | API ka controller: spam trap, form key, thank-you redirect, JSON jawab | naya, **is baar update** |
| `app/Http/Requests/StorePublicLeadRequest.php` | Validation + **aap ke form ke field names** (work_email, business, automate...) | naya, **is baar update** |
| `app/Services/LeadIngestionService.php` | Lead banana: duplicate check → Hot → assign → follow-ups | naya |
| `app/Models/LeadSourceEvent.php` | Har form submission ka log (CRM → Lead Sources) | naya |
| `config/cors.php` | Sirf aap ki website ko API call ki ijazat | naya |
| `app/Providers/AppServiceProvider.php` | Rate limiter + allowed websites, jo Settings se aate hain | badla |
| `resources/views/lead-sources/index.blade.php` | Lead Sources page (allowed websites, log) | update |
| `tests/Feature/PublicLeadApiTest.php` | API ke tests | update |

**Ahem:** ye files baqi CRM par depend karti hain (Lead, assignment, follow-ups, `lead_source_events` table).
Agar aap ne pichli **v2 full zip** lagayi hui hai, to API pehle se maujood hai. Sirf `crm-files/` wali files copy kar dein (overwrite).
Purane (upgrade se pehle wale) project mein sirf ye files akeli kaam nahi karengi. Wahan pehle v2 full zip lagayein.

Copy ke baad server par:

```bash
php artisan migrate --force      # agar v2 pehle se migrate ho chuka hai to "Nothing to migrate" aayega
php artisan optimize:clear
php artisan route:list --path=api  # yahan "POST api/v1/public/leads" nazar aana chahiye
```

### CRM settings (2 minute)
1. **CRM → Management → Lead Sources → Allowed website origins** mein apni landing page ki website likhein,
   jaise `https://digikonsolutions.com` aur `https://www.digikonsolutions.com` (har ek alag line par, aakhir mein `/` na lagayein).
   Iske baghair browser form ko CRM tak nahi jane dega (CORS).
2. **Management → Sales Automation → "Landing-page leads are marked Hot"** ON rahe. Ye by default ON hai.
3. `.env` mein `APP_URL` aap ke CRM ka asal address ho.

---

## B. Landing page (form) mein — kya badalna hai

Ready file: **`landing-page/talk-to-digikon-form.html`**. Design aap ke screenshot jaisa hi hai. Ya to ye poora form use karein, ya apne form mein sirf ye 4 cheezein karein:

**1) Har field ka `name` ye rakhein** (yehi API ko chahiye):

| Aap ka field | `name="..."` |
|---|---|
| Your name | `name` |
| Work email | `work_email` (ya `email`) |
| Business / website (optional) | `business` (ya `website` / `company`) |
| What would you like to automate? | `automate` (ya `interested_service`) |
| *(mashwara)* WhatsApp number | `whatsapp` (ya `phone`) |

Form ka `id="digikon-lead-form"` rakhein.

**2) Spam trap** (chhupa hua khali field) form ke andar add karein:
```html
<input type="text" name="hp_field" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;height:0;width:0;opacity:0">
```

**3) `<script>` block** (file ke aakhir wala) apne page par paste karein, aur sirf ye line badlein:
```js
var CRM_URL = 'https://crm.yourdomain.com';   // aap ke CRM ka address, aakhir mein / nahi
```
Optional: `THANK_YOU_URL = 'https://yoursite.com/thank-you'` (submit ke baad us page par bhejna ho to).

**4) Neeche wali line** "This demo form does not send data yet…" hata dein. Script us jagah success / error message dikhati hai (`id="dk-note"`).

Agar submit button ka text ya arrow koi ajeeb nishan dikhaye (jaise `â†’`), to page ke `<head>` mein `<meta charset="utf-8">` add karein.

> **Mashwara:** form mein abhi phone / WhatsApp field nahi hai, to in leads par sirf **email** follow-ups jayenge.
> WhatsApp number field add karein (file mein comment ki shakal mein maujood hai). Phir WhatsApp follow-ups, AI chat analysis aur automatic meeting booking bhi in leads par chalenge.

### WordPress / Elementor par
Elementor ka "HTML" widget (ya Gutenberg "Custom HTML" block) lein aur poori `talk-to-digikon-form.html` file paste kar dein. `CRM_URL` badal dein.

---

## C. API reference

**Request** (JSON ya normal form post):
```json
{
  "name": "Alex Morgan",
  "work_email": "alex@company.com",
  "business": "company.com",
  "automate": "Lead capture & follow-ups",
  "whatsapp": "03001234567",
  "utm_source": "facebook", "utm_campaign": "oct-automation",
  "landing_page_url": "https://yoursite.com/automation",
  "submission_id": "any-unique-id-per-form-load",
  "hp_field": ""
}
```
Kam az kam **email ya phone** mein se ek zaroori hai. Baqi sab optional.

**Responses**
- `201 {"ok":true,"message":"Thank you! Our team will contact you shortly.","reference":"..."}` — lead ban gayi (ya pehle wali open lead se jod di gayi)
- `422 {"ok":false,"message":"Please check the form and try again.","errors":{...}}` — ghalat / adhuri details
- `429` — ek IP se ek minute mein 10 se zyada submissions
- `403` — form key on hai aur ghalat / missing hai

Internal lead ID kabhi wapas nahi bheji jati.

**Terminal se test:**
```bash
curl -X POST https://YOUR-CRM-DOMAIN/api/v1/public/leads -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test","work_email":"test@example.com","business":"test.com","automate":"Lead capture & follow-ups"}'
```
Phir CRM → Leads mein lead **Hot** badge ke sath nazar aayegi. CRM → Lead Sources mein submission ka log hai.

## D. Kuch kaam na kare to

| Masla | Hal |
|---|---|
| Browser console: "blocked by CORS policy" | Lead Sources mein website ka address bilkul wahi likhein jo browser mein hai (`https://` + `www` ho to `www` ke sath) |
| 404 | `php artisan optimize:clear`. CRM_URL mein `/public` ki zaroorat to nahi? (domain ka document root CRM ke `public/` folder par ho) |
| 422 | Field names table B-1 jaisay hon. Email ya phone zaroor bhejein |
| Lead bani lekin Hot nahi | Sales Automation → "Landing-page leads are marked Hot" ON karein. Duplicate lead (pehle se open) apna temperature rakhti hai |
| Lead nazar nahi aa rahi | Agent sirf apni leads dekhta hai. Admin se check karein, ya Lead Sources ka log dekhein |
