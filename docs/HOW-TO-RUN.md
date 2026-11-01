# Digikon CRM — project kaise chalayein

## A. Apne computer par (Windows: Laragon ya XAMPP)

**Zaroorat:** PHP 8.2 ya us se naya, aur MySQL. Laragon sab se aasan hai, XAMPP 8.2+ bhi chalega.
Check karne ke liye terminal mein: `php -v`. `composer install` ki zaroorat nahi, kyun ke `vendor/` zip ke andar hai.

1. **Zip nikalein**, jaise `C:\laragon\www\digikon-crm`.
2. **Database banayein:** phpMyAdmin / HeidiSQL mein `digikon_crm` naam ka database (collation `utf8mb4_unicode_ci`).
3. **`.env` file kholein** aur ye lines check karein:
   ```
   APP_URL=http://localhost:8000
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=digikon_crm
   DB_USERNAME=root
   DB_PASSWORD=          # XAMPP / Laragon mein aam tor par khali
   MAIL_MAILER=log       # testing: emails storage/logs/laravel.log mein jayengi
   QUEUE_CONNECTION=database
   ```
4. **Project folder mein terminal kholein** (Laragon: Terminal button. XAMPP: folder mein Shift + Right-click → "Open PowerShell here"):
   ```bash
   php artisan migrate --seed
   php artisan storage:link
   php artisan serve
   ```
5. Browser mein **http://localhost:8000** kholein. Login:

   | Role | Email | Password |
   |---|---|---|
   | Admin | admin@crm.test | password |
   | Sales Manager | manager@crm.test | password |
   | Sales Agent | ali@crm.test / sara@crm.test | password |

   Login ke baad Sales Agents page se passwords badal dein.
6. **Doosra terminal** khol kar ye chalayein, aur isay khula rehne dein:
   ```bash
   php artisan schedule:work
   ```
   Ye follow-ups, meeting reminders, background jobs (proposal / invoice / Meta leads) aur raat ki reports chalata hai.
   Ye band ho to automatic kaam ruk jate hain.
7. **Test:** `php artisan crm:flow-demo` chalayein. Poora flow terminal mein dikhega, aur lead CRM mein nazar aayegi.
   Mazeed commands: `docs/TESTING-DUMMY-LEADS.md`.

### Landing page form ko local par test karna
- `docs/talk-to-digikon-form.html` mein `CRM_URL = 'http://localhost:8000'` rakhein.
- File ko double-click se na kholein (`file://` par browser CORS block karta hai). Kisi local server se kholein,
  jaise VS Code ka "Live Server" → `http://127.0.0.1:5500/talk-to-digikon-form.html`.
- CRM → Lead Sources → Allowed website origins mein `http://127.0.0.1:5500` likhein.

---

## B. Live server par (cPanel / VPS)

1. Files upload karein. **Domain / subdomain ka document root `public/` folder par set karein**,
   jaise `crm.yourdomain.com` → `/home/user/digikon-crm/public`.
2. MySQL database aur user banayein, aur `.env` mein dalein:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://crm.yourdomain.com
   MAIL_MAILER=smtp       # ya CRM → Settings → Email (SMTP) mein details dalein
   ```
3. Terminal / SSH (cPanel → Terminal) mein:
   ```bash
   php artisan migrate --seed --force
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Pehle se chalta hua CRM update kar rahe hain? Pehle **database backup** lein. `migrate --seed` purana data delete nahi karta,
   aur seeder mojooda users ke passwords nahi badalta.
4. **Cron job** (cPanel → Cron Jobs → Every minute):
   ```
   * * * * * cd /home/user/digikon-crm && php artisan schedule:run >> /dev/null 2>&1
   ```
5. `storage/` aur `bootstrap/cache/` folders writable hon (permission 775).
6. CRM mein jaa kar ye settings karein:
   - **Settings → Email (SMTP)**
   - **WhatsApp API**
   - **Meta Lead Ads** (App Secret ke sath)
   - **AI Analysis** (key)
   - **Sales Automation** (bank details)
   - **Service Packages** (asal prices)
   - **Lead Sources** (landing page domain)

`.env` mein kuch badlein to `php artisan config:cache` dobara chalayein.

---

## C. Aam masle

| Masla | Hal |
|---|---|
| `could not find driver` | php.ini mein `extension=pdo_mysql` on karein |
| `SQLSTATE[HY000] [1045] Access denied` | `.env` mein DB_USERNAME / DB_PASSWORD theek karein |
| `Unknown database` | Step A-2 wala database banayein |
| Page par sirf "500 Server Error" | `storage/logs/laravel.log` dekhein. Local par `.env` mein `APP_DEBUG=true` |
| CSS ke baghair page | APP_URL theek karein. Server par document root `public/` ho |
| Proposal / invoice / follow-ups nahi ja rahe | `php artisan schedule:work` (local) ya cron (server) chal raha ho |
| Emails nahi pohnch rahi | Local par `MAIL_MAILER=log` hai, emails `storage/logs/laravel.log` mein hain. Asal emails ke liye SMTP dalein |
| Port 8000 busy | `php artisan serve --port=8080` aur APP_URL bhi wahi port |
