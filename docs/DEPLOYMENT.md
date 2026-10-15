# Deployment — Hostinger shared hosting

This guide installs Fameweb NGO Cloud on a subdomain such as `ngo.fameweb.in`. Nothing needs to be compiled: upload the files, create a database, fill in `.env`, run the installer.

## 1. Requirements

| Item | Needed |
|---|---|
| PHP | 8.2 or newer (hPanel → Advanced → PHP Configuration) |
| PHP extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json`, `session` (all on by default at Hostinger) |
| Database | MySQL 8 or MariaDB 10.6+ |
| Web server | Apache or LiteSpeed with `.htaccess` and mod_rewrite (Hostinger default) |
| PHP settings | `upload_max_filesize` ≥ 4M, `post_max_size` ≥ 8M, `memory_limit` ≥ 128M |
| Optional | SSH access (for `bin/*.php` scripts), Cron Jobs |

The web installer shows a requirements checklist, so any missing item appears there before anything is written.

## 2. Create the subdomain, SSL and database

1. **Subdomain**: hPanel → Domains → Subdomains → create `ngo`. Note its folder, e.g. `/home/u123456789/domains/fameweb.in/public_html/ngo`.
2. **SSL**: hPanel → Security → SSL → install the free certificate for `ngo.fameweb.in`, then enable **Force HTTPS**.
3. **Database**: hPanel → Databases → MySQL Databases.
   * Create a database such as `u123456789_ngo` and a user such as `u123456789_ngoapp` with a long random password.
   * Use this database only for this app: one database and one user per environment (production and staging).
   * Hostinger grants the user full rights on its own database and on no other database. The app needs SELECT, INSERT, UPDATE, DELETE, plus CREATE, ALTER, INDEX, DROP and REFERENCES while migrations run.
   * On a VPS or your own MySQL you can be stricter: use one account with the DDL rights only for `bin/migrate.php`, and a runtime account with only SELECT, INSERT, UPDATE and DELETE.

## 3. Upload the files

### Option A (recommended): application outside `public_html`

```
/home/u123456789/domains/fameweb.in/
├── ngo-saas/                 ← upload the whole project here (app/, config/, storage/, .env …)
└── public_html/ngo/          ← subdomain folder: upload ONLY the contents of ngo-saas/public/
    ├── index.php
    ├── .htaccess
    ├── assets/
    └── app_path.php          ← create this file
```

`public_html/ngo/app_path.php`:

```php
<?php return '/home/u123456789/domains/fameweb.in/ngo-saas';
```

With this layout, `.env`, source code, logs, sessions and uploaded files cannot be reached from the web at all. (`app_path.php` itself is blocked by `.htaccess` and only returns a path.)

### Option B (fallback): everything inside the subdomain folder

Upload the whole project into `public_html/ngo/`. The root `.htaccess` forwards every request into `public/`. It also returns 403 for `app/`, `config/`, `storage/`, `database/`, `bin/`, `tests/`, `docs/`, dotfiles and `.env`, and `storage/` additionally has its own deny-all `.htaccess`. Use option A whenever possible. After deploying, run the verification checklist (section 8), which includes requests that must be refused.

Upload with hPanel File Manager (upload a zip and extract it) or SFTP. Do not upload `.env` from your computer or the `tests/` and `docs/` folders unless you need them.

## 4. Configure `.env`

Copy `.env.example` to `.env` in the application folder (`ngo-saas/.env`) and set:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ngo.fameweb.in
INSTALL_TOKEN=<output of: php -r "echo bin2hex(random_bytes(24)), PHP_EOL;">
DB_HOST=localhost
DB_DATABASE=u123456789_ngo
DB_USERNAME=u123456789_ngoapp
DB_PASSWORD=<the database password>
SESSION_SECURE_COOKIE=true
APP_KEY=<output of: php bin/generate-key.php>
MAIL_DRIVER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=no-reply@fameweb.in
MAIL_PASSWORD=<the mailbox password>
MAIL_FROM_ADDRESS=no-reply@fameweb.in
```

* Without SSH, generate the token on any computer with PHP, or use any 48-character random hex string from a password manager.
* `APP_KEY` encrypts identity documents, member photos, SMTP passwords and queued email bodies. **Back it up together with the database**: encrypted files cannot be read without it, and changing it later makes existing encrypted files unreadable.
* Email: create the `no-reply@` mailbox in hPanel → Emails. The SMTP values can stay out of `.env` and be entered instead in Admin → Email → Settings (the password is then stored encrypted). Send a test email from that page. `MAIL_DRIVER=mail` uses PHP `mail()`; `MAIL_DRIVER=log` writes emails to `storage/logs/mail.log` instead (staging).
* No secret is ever stored in the code or the database seeds.

## 5. File permissions

| Path | Permission |
|---|---|
| Folders | `755` |
| Files | `644` |
| `.env` | `600` (or `640`) |
| `storage/`, `storage/logs`, `storage/sessions`, `storage/private`, `storage/cache` | `755` or `775`; must be writable by PHP (Hostinger runs PHP as your account, so `755` works) |

With SSH:

```bash
cd ~/domains/fameweb.in/ngo-saas
find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
chmod 600 .env
```

## 6. Install

1. Open `https://ngo.fameweb.in/install`.
2. Check that every requirement is green.
3. Enter the `INSTALL_TOKEN` and the first Fameweb Super Admin's name, email and password (at least 10 characters, with a letter and a digit).
4. The installer creates the tables, the permission catalogue and the administrator, then writes `storage/installed.lock`. From then on `/install` returns 404.
5. Delete the `INSTALL_TOKEN` line from `.env`.

With SSH you can install from the command line instead: `php bin/install.php "Full Name" admin@fameweb.in`. The password is typed at a hidden prompt.

**Demo data** (staging only): `php bin/seed-demo.php` adds demo plans and NGOs flagged as demo and prints their one-time passwords. `php bin/seed-demo.php --purge` removes them. Both refuse to run when `APP_ENV=production`.

## 7. Cron (recommended)

Every email is attempted immediately, but failed deliveries are retried by `bin/cron.php`. Each run delivers emails whose retry time has come; once a day it also refreshes subscription statuses, removes old sessions, tokens and login attempts, and scrubs old email bodies. Without cron the app still works, but automatic email retries do not happen (they can be retried by hand from the email log). Open hPanel → Advanced → Cron Jobs → Custom and use:

```
*/10 * * * *   /usr/bin/php /home/u123456789/domains/fameweb.in/ngo-saas/bin/cron.php
```

## 8. Verification checklist (after every deployment)

- [ ] `https://ngo.fameweb.in` redirects to the login page over HTTPS with a valid certificate.
- [ ] `/install` returns 404.
- [ ] These all return 403 or 404, never file contents:
  - `/.env`
  - `/storage/logs/`
  - `/app/Core/Auth.php`
  - `/config/database.php`
  - `/composer.json`
  - (Option B only) `/storage/installed.lock`
- [ ] Sign in as the Fameweb admin and check that the dashboard shows real numbers.
- [ ] Create a plan, then register a test NGO. Open the onboarding link in a private window and set a password.
- [ ] Sign in as that NGO and check that `/admin` returns "Access denied".
- [ ] Add users up to the plan limit and check that the next one is refused with an explanation.
- [ ] Suspend the NGO and check that its user cannot sign in. Reactivate it and check that the data is still there.
- [ ] Audit log → **Verify integrity** reports the chain is intact.
- [ ] Trigger an error page (open a missing URL) and check that it shows no paths or SQL.
- [ ] Response headers include `Content-Security-Policy`, `X-Frame-Options: DENY` and `Strict-Transport-Security`.
- [ ] Remove the test NGO's users or mark the NGO inactive.

Automated checks before deploying, on a computer or staging server with PHP and MySQL:

```bash
cp .env.testing.example .env.testing     # point at an empty database ending in _test
php tests/run.php                          # 100+ checks: auth, CSRF, tenant isolation, escalation, limits …
```

## 9. Upgrades

1. Back up the database and `storage/private` (section 10).
2. Upload the new files, keeping `.env`, `storage/` and `public_html/ngo/app_path.php`.
3. Apply migrations, using either:
   * SSH: `php bin/migrate.php`, or
   * the web panel: Settings → Database schema → **Apply upgrades** (Fameweb Super Admin only).
4. Run the verification checklist.

## 10. Backup and restore

What to back up:

| What | Where | Why |
|---|---|---|
| Database | MySQL | All data |
| Uploaded files | `storage/private/` | Logos and future documents |
| Configuration | `.env` | Keep it in a password manager, not with the other backups |

Hostinger keeps automatic backups (hPanel → Files → Backups) but on a short retention, so also keep your own.

Backups over SSH:

```bash
cd ~/domains/fameweb.in/ngo-saas
mysqldump --single-transaction --routines -u u123456789_ngoapp -p u123456789_ngo | gzip > ~/backups/ngo-$(date +%F).sql.gz
tar czf ~/backups/ngo-files-$(date +%F).tar.gz storage/private
```

Without SSH, use phpMyAdmin → Export (SQL), and File Manager → compress `storage/private`. Download both and keep copies off the server. Backups contain personal data, so encrypt them at rest.

Restore:

1. Put the site in maintenance by renaming `public_html/ngo/index.php` temporarily, or block it with a password in hPanel.
2. Restore the database:
   ```bash
   gunzip < ngo-YYYY-MM-DD.sql.gz | mysql -u u123456789_ngoapp -p u123456789_ngo
   ```
   Or use phpMyAdmin → Import.
3. Extract the files backup over `storage/private/`.
4. Make sure `storage/installed.lock` exists, so the installer stays closed.
5. Run `php bin/migrate.php` in case the backup is older than the code.
6. Re-enable the site, sign in, and run Audit log → **Verify integrity**. A restore from a consistent dump keeps the chain intact.

Test a restore on a staging subdomain at least once.

## 11. Production hardening reminders

* Keep `APP_DEBUG=false`. Error details go to `storage/logs/app-YYYY-MM.log` with a reference id shown to the user.
* Keep `SESSION_SECURE_COOKIE=true` once HTTPS is forced.
* Set `TRUST_PROXY_HEADERS=true` only behind a proxy you control, such as Cloudflare. Otherwise the client IP used for throttling could be spoofed.
* Rotate the database password if it was ever shared, and update `.env`.
* Review **Admins** regularly. Each Fameweb staff member needs their own account, and Fameweb Support is read-only.
