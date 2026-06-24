# Deploying to Railway

Quick guide to spin up the gym software on [Railway](https://railway.app) as an evaluation/comparison against Hostinger.

> **Read the "Known limitations" section at the bottom first.** This codebase was built for shared hosting (Hostinger), not PaaS. A few things are awkward on Railway. Nothing is a showstopper, but you should know before you migrate real customers.

---

## What you'll end up with

Three Railway services in one project:

| Service | What it runs | Source |
|---|---|---|
| `web` | PHP web app | repo root |
| `whatsapp-bot` | Node.js WhatsApp reminders | `whatsapp-bot/` |
| `MySQL` | Database (Railway plugin) | — |

---

## Step 1 — Create the project

1. Log in to [railway.app](https://railway.app).
2. **New Project** → **Deploy from GitHub repo** → pick `Darkened-Z/Gym-software`.
3. Railway will create a first service from the repo. Rename it to **`web`**.

## Step 2 — Add MySQL

1. In the project, **+ New** → **Database** → **Add MySQL**.
2. Wait for it to provision. It exposes these variables: `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`.

## Step 3 — Configure the `web` service

In the `web` service → **Variables**, add:

```env
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_NAME=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Karachi

SESSION_SECURE_COOKIE=true
SESSION_HTTPONLY=true
SESSION_SAMESITE=Strict
```

Then **Settings → Networking → Generate Domain**. You'll get a URL like `gym-web-production.up.railway.app`.

## Step 4 — Mount a Volume for uploads (optional but recommended)

Without this, member profile images get wiped on every redeploy.

1. `web` service → **Settings → Volumes → + New Volume**.
2. Mount path: `/app/uploads`.
3. Size: 1 GB is plenty to start.

## Step 5 — Import the database schema

The MySQL plugin starts empty. Load the schema:

**Option A — From your local machine (easiest):**

In the MySQL service → **Connect** → copy the public connection URL, then:

```bash
mysql -h <host> -P <port> -u <user> -p<password> <database> < database/01_schema.sql
mysql -h <host> -P <port> -u <user> -p<password> <database> < database/add_nfc_columns.sql
mysql -h <host> -P <port> -u <user> -p<password> <database> < database/add_system_license.sql
mysql -h <host> -P <port> -u <user> -p<password> <database> < database/05_whatsapp_automation.sql
```

(Pick whichever schema files are canonical for your install — check `database/` and `sql/` and use the most complete one.)

**Option B — From Railway's MySQL plugin:**

Use the **Data** tab on the MySQL service to paste SQL.

## Step 6 — Activate the system

1. Open your `web` service URL → `/setup.php`.
2. It will:
   - Generate a license key tied to the Railway container's fingerprint
   - Create the default admin (`admin` / `admin123`)
3. **Log in immediately and change the admin password.**

## Step 7 — Deploy the WhatsApp bot (optional)

1. In the project → **+ New → GitHub Repo** → pick the same repo again.
2. Name the new service **`whatsapp-bot`**.
3. **Settings → Source → Root Directory:** `whatsapp-bot`.
4. **Variables**, add:

   ```env
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

   WHATSAPP_SESSION_DIR=/app/session
   WHATSAPP_HEADLESS=true
   WHATSAPP_QR=true
   WHATSAPP_TIMEZONE=Asia/Karachi
   WHATSAPP_COUNTRY_CODE=92
   WHATSAPP_DEFAULT_GYM_NAME=Your Gym
   WHATSAPP_DRY_RUN=true
   ```

   Keep `WHATSAPP_DRY_RUN=true` for the first run so it doesn't actually send messages while you're testing.

5. **Settings → Volumes → + New Volume** mounted at `/app/session`. Without this the bot will ask for a fresh QR scan on every redeploy.
6. Deploy. Open the **Logs** tab.
7. The bot prints a QR code as ASCII in the logs. Scan it with the gym's WhatsApp account.
8. Once logged in, flip `WHATSAPP_DRY_RUN=false` in env vars and redeploy.

---

## Known limitations (read this)

### 1. License fingerprint may regenerate on redeploy

`LicenseHelper.php` builds the license key from server hostname, IP, document root, and PHP version. Railway containers get new IPs/hostnames when you redeploy. **Result:** every redeploy may invalidate your license, forcing you to re-run `setup.php` and possibly losing the admin account.

**Workaround for evaluation:** just rerun `setup.php` after redeploy. For production: this needs a code change — derive the fingerprint from a stable env var (e.g. `RAILWAY_PROJECT_ID`) instead of runtime server identity.

### 2. Many `.php` scripts in the repo root are publicly reachable

`setup.php`, `fix-admin-password.php`, `dbupdatesv1.php`, `dbcheck.php`, `fixesindb.php`, `updatesv3.php` — all hit-able at `yourdomain.up.railway.app/<filename>.php`. The included `.htaccess` does **not** apply because Railway's PHP runtime doesn't use Apache.

**Before going public:** delete the ones you don't need, or wrap each with a token check. For evaluation it's fine.

### 3. ESP32 gate hardware

The ESP32 NFC reader code is configured with a Hostinger URL. To point it at Railway, edit the ESP32 sketch:

```cpp
const char* serverURL = "https://gym-web-production.up.railway.app";
```

And re-flash. The rest works the same — Railway gives HTTPS by default.

### 4. WhatsApp QR scan

The QR appears in the Railway **Logs** tab the first time the bot starts (and on any redeploy that lost its session volume). It's an ASCII QR — readable on most phones if you zoom in. Keep the volume mounted to avoid re-scanning.

### 5. Built-in PHP server, not nginx + PHP-FPM

`nixpacks.toml` uses `php -S` for predictability (no nginx config to fight). It's single-threaded — fine for evaluation and small gyms (under ~50 concurrent users). For production traffic switch to nginx + PHP-FPM.

---

## Cost expectation

For evaluation:
- Web service: ~$5/mo (sleeps idle if Hobby plan)
- MySQL plugin: ~$5/mo
- WhatsApp bot: ~$5/mo (Chromium is memory-heavy; this one won't sleep gracefully)
- Volumes: $0.25/GB/mo

Roughly **$15–20/mo** for the full stack. Compare to Hostinger shared (~$3–10/mo) — Railway is more expensive but gives you separate services, autodeploys from GitHub, and a real Postgres/MySQL plugin.

---

## Quick troubleshooting

| Problem | Check |
|---|---|
| `Database connection error.` on first load | MySQL plugin running? `DB_*` env vars set on `web`? |
| 500 error on `/setup.php` | Check `web` service logs. Often a missing PHP extension — extend `nixpacks.toml` setup phase. |
| Profile images disappear | Volume not mounted at `/app/uploads` |
| WhatsApp QR doesn't appear | Bot logs say "Browser launch error"? Chromium nixpkg failed to install. Check Build Logs. |
| Bot loops asking for QR | Session volume at `/app/session` not mounted |
| ESP32 can't reach server | Old Hostinger URL still in firmware. Reflash with new Railway URL. |
