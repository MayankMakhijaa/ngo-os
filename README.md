# Fameweb NGO Cloud — Phases 1 and 2

Fameweb NGO Cloud is a multi-tenant NGO management SaaS for Fameweb.

Fameweb administrators can:
* register NGOs and send each one a single-use onboarding link
* sell plans with user, member and storage limits
* record payments
* watch usage and the audit trail

Each NGO gets its own portal with users, roles, per-user permissions, organization settings, subscription and usage pages.

Phase 2 adds, each switched on per plan as a module:
* **Members, volunteers and interns**: registration with automatic member IDs (MEM-00001, VOL-00001, INT-00001), categories, status and history, encrypted identity documents, Aadhaar verification (only the last 4 digits are ever stored), salary/stipend slips and ID cards as PDFs.
* **Certificates**: Fameweb designs templates in a visual builder and assigns them to specific NGOs; the NGO issues, emails, downloads (PDF) and revokes certificates.
* **80G donation receipts**: generated from the NGO's name, logo and 80G details, numbered per financial year, emailed to donors, with a Form 10BD export. The wording is editable per NGO and is **not** presented as legally certified.
* **Email automation**: an outbox with delivery attempts, automatic retries with back-off and an email log; SMTP for the platform and optionally per NGO. Phase 1 invitations and password resets now send real emails.

PDFs are produced by a small pure-PHP generator (`app/Pdf`), so nothing extra is needed on shared hosting.

The stack is plain PHP 8.2+, MySQL/MariaDB, Bootstrap 5 and Chart.js. There is no framework, no Composer packages and no Node.js. It runs on Hostinger shared hosting.

* **Architecture, ERD, security model:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
* **Hostinger deployment, backups, verification:** [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
* **What is done and what is next:** [CHECKLIST.md](CHECKLIST.md)

## Run it locally

You need PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` and `gd` (`sodium` and `zlib` are used when present), plus MySQL 8 or MariaDB 10.6+. Hostinger's PHP 8.2+ has all of these.

```bash
# 1. Database and user (any names; these are examples)
mysql -u root -e "CREATE DATABASE ngo_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER 'ngo_app'@'localhost' IDENTIFIED BY 'choose-a-password';
                  GRANT ALL ON ngo_saas.* TO 'ngo_app'@'localhost';"

# 2. Configuration
cp .env.example .env
#    set APP_ENV=development, DB_* values, and INSTALL_TOKEN:
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
#    and the encryption key (documents, SMTP passwords, queued emails):
php bin/generate-key.php        # paste the APP_KEY=... line into .env

# 3. Serve
php -S 127.0.0.1:8080 -t public public/index.php
```

Open http://127.0.0.1:8080/install. Enter the install token and create the first Fameweb Super Admin, then remove `INSTALL_TOKEN` from `.env`.

There is no default administrator and no built-in password. To install from the command line instead, run `php bin/install.php "Full Name" you@example.com`.

Optional demo data (refused when `APP_ENV=production`):

```bash
php bin/seed-demo.php          # demo plans + NGOs, prints one-time passwords
php bin/seed-demo.php --purge  # remove all demo data
```

## Everyday commands

| Command | Purpose |
|---|---|
| `php bin/migrate.php` | Apply new database migrations and re-sync permissions (`--status` to list) |
| `php bin/cron.php` | Email retries every run; daily housekeeping (subscription expiry, old sessions/tokens). Schedule every 5 to 15 minutes |
| `php bin/generate-key.php` | Print a new `APP_KEY` for `.env` (keep a backup: encrypted documents cannot be read without it) |
| `php tests/run.php` | Automated test suite (needs `.env.testing`, see below) |

## Tests

```bash
cp .env.testing.example .env.testing   # an EMPTY database whose name ends in _test
php tests/run.php
```

The suite wipes and installs the test database, starts PHP's built-in server on a free port, and runs 216 checks over real HTTP. They cover:

* sign-in, lockout, invitations and password reset without account enumeration
* CSRF
* validation
* **cross-tenant attacks** (NGO A against NGO B's users, roles, files and settings, including tampered `tenant_id`)
* role and permission escalation attempts
* plan limits and read-only mode for expired subscriptions
* payment idempotency
* transaction rollback
* session expiry and logout
* audit-log redaction and hash-chain tamper detection
* members: ID series, Aadhaar check digit, only last 4 digits stored (database, session, logs), member limit, encrypted documents, verification, slips and ID cards, cross-tenant 404s
* certificates: templates visible only to assigned NGOs, issue, limit, revoke
* 80G receipts: financial-year numbering, double-submit idempotency, cash/in-kind ineligibility, cancellation, Form 10BD export
* email: a fake SMTP server checks real delivery, a 451 retry through cron, manual retry, attachments, token redaction and that SMTP passwords never leak
* Fameweb support access: permission gating, single-use reset links, temporary passwords that must be changed and never reach email or logs, and audited Super Admin support sessions (banner, return, expiry, sign-out)
* every generated PDF is checked structurally (xref offsets)

An optional browser walkthrough of the full acceptance workflow uses Playwright (`tests/browser/acceptance.mjs`). It is a development tool and is not needed on the server.

## Project layout

```
app/          Core kernel, Middleware, Controllers (Platform/, Ngo/), Services, Views, Helpers
config/       app, database, security, mail, modules, permissions (catalogue + role templates)
database/     migrations/*.sql, seeds/
public/       web root: index.php, .htaccess, assets/
routes/       web.php — every route with its permission
storage/      logs, sessions, private uploads (never web-served)
bin/          CLI scripts
tests/        run.php, browser/
```

## Default roles

| Role | Area | Summary |
|---|---|---|
| Fameweb Super Admin | `/admin` | Everything on the platform, including resetting NGO users' passwords and audited sign-in as an NGO's Super Admin |
| Fameweb Support | `/admin` | Read-only: NGOs, plans, subscriptions, usage, logs |
| NGO Super Admin | `/ngo` | Everything inside their own NGO (locked role) |
| NGO Manager | `/ngo` | Users (no permission overrides), view roles, organization, subscription, usage, audit; people, slips (view), certificates, receipts (not settings), email log |
| NGO Operations | `/ngo` | Dashboard, organization view and the people list; more by explicit grant |

NGO Manager and Operations permissions are copied into each NGO, so each NGO can tune them, add custom roles, and grant or deny single permissions per user. Nobody can hand out a permission they do not hold.

NGOs created before Phase 2 keep their existing Manager and Operations roles unchanged: their NGO Super Admin gets the new permissions automatically and can add them to other roles under Roles & permissions.
