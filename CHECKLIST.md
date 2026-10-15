# Checklist (Phase 1 and Phase 2)

Status as of 14 Oct 2026. ✅ done · ⏳ remaining / needs you · 🔭 future phase (architecture only).

## Implementation order

| Step | Status |
|---|---|
| 1. Architecture and ERD | ✅ [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (Mermaid ERD) |
| 2. Database and configuration | ✅ 19 tables, migrations, production/demo seeds, `.env` config |
| 3. Installation and authentication | ✅ token-guarded installer, login, lockout, reset, invites, role redirect |
| 4. Fameweb Admin | ✅ dashboard, NGOs, plans, subscriptions, payments, usage, audit, settings, admins |
| 5. NGO portal | ✅ three role layouts, users, roles, permissions, organization, subscription, usage, audit |
| 6. Testing | ✅ `tests/run.php` (103 checks) and the Playwright walkthrough (33 checks), both passing |
| 7. Deployment documentation | ✅ [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |

## Modules

- ✅ **Authentication**
  - Secure login and logout, `password_hash`, session regeneration.
  - Idle and absolute timeouts.
  - DB-backed throttling and lockout.
  - Password reset with no account enumeration.
  - Single-use hashed tokens.
  - Role-based redirect.
- ✅ **Fameweb SaaS dashboard**
  - Real counts: NGOs by status, users, subscriptions by status.
  - Expiring trials and renewals.
  - MRR from active subscriptions.
  - Usage alerts.
  - Charts from DB data.
  - Recent activity.
- ✅ **NGO management**
  - Create, edit and view NGOs with a logo.
  - Unique reference code.
  - Single-use onboarding link.
  - Activate, suspend or deactivate with a reason and a confirmation dialog.
  - Suspension blocks access immediately and keeps the data.
- ✅ **Subscription plans**
  - Plan CRUD with limits and modules; activate and deactivate.
  - Assign or change a plan as a new history row.
  - Statuses trial, active, past_due, expired, suspended and cancelled, with lazy expiry and a grace period.
  - Read-only workspace when lapsed.
  - Manual payments with idempotency.
  - Razorpay columns reserved.
- ✅ **NGO users and RBAC**
  - Default roles per NGO and custom roles.
  - Per-user grant and deny.
  - Anti-escalation rules.
  - Last-Super-Admin protection.
  - Deny by default on every route.
- ✅ **Usage and storage tracking**
  - Users and storage computed from real data.
  - Idempotent usage records.
  - Percent used, warnings at the configured threshold, limit-reached messages.
  - Limits enforced in services.
- ✅ **Audit logs**
  - Append-only, SHA-256 hash chain with verification.
  - Sensitive data redacted and Aadhaar masked.
  - Filters and CSV export.
- ✅ **Security**
  - CSRF on all POSTs.
  - Strict CSP with no inline scripts.
  - Security headers.
  - Private file storage.
  - No secrets in code.
  - Friendly errors with reference ids in production.

## Acceptance workflow (15 steps)

All verified by `tests/browser/acceptance.mjs` and/or `tests/run.php`:

1. ✅ Install with token, create the Fameweb Super Admin; installer then disappears
2. ✅ Admin signs in and sees the SaaS dashboard
3. ✅ Admin creates a subscription plan
4. ✅ Admin registers an NGO (with logo) and gets a single-use onboarding link
5. ✅ NGO Super Admin activates the account through the link and sets their own password
6. ✅ NGO Super Admin lands on the NGO dashboard
7. ✅ NGO Super Admin creates Manager and Operations users
8. ✅ User limit enforced with an explanation
9. ✅ Per-user grant and deny take effect
10. ✅ NGO users are blocked from the Fameweb admin area
11. ✅ Operations and Manager see only what their permissions allow
12. ✅ Dashboard numbers match the database
13. ✅ Admin suspends the NGO; its users are signed out and cannot sign in; audited
14. ✅ Admin reactivates; access returns with all data intact
15. ✅ Audit log shows every step and the hash chain verifies

## Phase 2 (14 Oct 2026)

- ✅ **Members, volunteers, interns** (modules `members`, `volunteers`, `interns`)
  - Registration with automatic IDs per NGO and type (prefixes editable in Organization), categories, status (pending, active, inactive, completed) and a history timeline.
  - Aadhaar: check digit validated, only the last 4 digits stored; the card itself is uploaded as an identity document. Same for bank account numbers.
  - Identity documents (module `documents`): PDF/JPG/PNG/WebP up to 5 MB, encrypted at rest with APP_KEY, downloads audited, never reachable via `/files/`.
  - Verification: verified or rejected (with reason) once an Aadhaar document exists.
  - Salary / stipend / honorarium slips (one per person per month, earnings and deductions, amounts in words) and ID cards (CR80 card size, front and back, with photo) as PDFs.
  - Member limit (`max_members`) counts pending + active people, enforced under a tenant row lock.
- ✅ **Certificates** (module `certificates`)
  - Fameweb builder: drag-and-drop live preview, text with `{{placeholders}}`, logo, lines, boxes, frames, three presets; assign templates to specific NGOs; PDF preview with an NGO's logo.
  - NGO: issue (CERT-YYYY-00001), email with PDF, download, revoke (PDF marked REVOKED). Layout and values are snapshotted at issue. Counts against `max_certificates`.
- ✅ **80G donation receipts** (module `certificates_80g`)
  - Built from the NGO name, logo and address plus PAN, 80G registration number and validity, signatory and optional signature image.
  - Numbered per financial year (RCPT/2026-27/00001), immutable, cancel with reason, double-submit safe, emailed to donors automatically or on demand.
  - Cash above Rs. 2,000 (configurable) and donations in kind are marked not eligible on the receipt.
  - Form 10BD data export (CSV) per financial year.
- ✅ **Email automation** (module `email_automation`)
  - Outbox with encrypted bodies, delivery attempts, retries at 2 min, 10 min, 1 h, 6 h (5 attempts), single-use links removed from stored copies after delivery.
  - Pure-PHP SMTP client (SSL 465 / STARTTLS 587 / AUTH LOGIN and PLAIN). Platform SMTP in Admin > Email; optional own SMTP per NGO; NGOs otherwise send through the platform with their own sender name and Reply-To.
  - Automatic emails per NGO: welcome with member ID, certificate issued, receipt to donor.
  - Email logs for Fameweb (all) and each NGO (its own), with manual retry and a test email.
- ✅ Tests: `tests/run.php` had 183 checks (80 new) at this point, including a fake SMTP server.
- ✅ **Fameweb support access** (requested 14 Oct 2026, migration `003_admin_support_access.sql`)
  - Reset password for any NGO user from Admin > NGOs > Users: a single-use link (emailed and shown once), or a temporary password shown once to the admin, never emailed, which the user must change at the next sign-in. Permission `tenants.users.reset`.
  - "Sign in as Super Admin": an audited support session as the NGO's active Super Admin that never uses or changes their password. It needs a reason, shows a banner with "Return to Fameweb", ends after 60 minutes, on sign-out or if the admin loses access, and cannot change the NGO user's own profile or password. Start, end and every action are recorded in the NGO's audit log (visible in the Fameweb audit log too). Permission `tenants.impersonate`.
  - Both permissions belong to Fameweb Super Admin only; Fameweb Support does not get them. Tests: 216 checks (33 new).

## Remaining / needs you

- ⏳ **Review and merge** the draft PR (`claude/phase-1` into `main`). The older `phase-1` branch holds the same code and can be deleted. Set `main` as the default branch in the GitHub settings.
- ⏳ **Deploy to Hostinger** following [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). The app has only been run locally so far, on PHP 8.3 with MariaDB 10.11.
- ⏳ **Set APP_KEY** (`php bin/generate-key.php`) on the server before anyone uploads identity documents, and back it up with the database backups.
- ⏳ **Configure SMTP** in Admin > Email > Settings (for example the Hostinger mailbox `no-reply@`) and send a test email; schedule `bin/cron.php` every 10 minutes for retries.
- ⏳ **80G wording review.** The receipt fields follow section 80G and rule 18AB as we understand them. Uncertain points to confirm with a CA: the exact declaration wording, whether the 80G approval date and validity must both appear, how corpus and specific-grant donations should be worded, and the Form 10BD CSV column mapping before uploading anything to the income-tax portal.
- ⏳ **Existing NGOs' Manager roles** do not receive the new permissions automatically (only NGO Super Admins do); add them under Roles & permissions where wanted.
- ⏳ **Apache `.htaccess` rules** were written for Hostinger but were not exercised here, because the test environment uses PHP's built-in server. Run the "must return 403/404" items in the deployment checklist after the first upload.

## Future phases (designed, not built)

- 🔭 WordPress sync (registrations from the NGO's website into members)
- 🔭 Razorpay payments and webhooks (could also create 80G receipts for online donations)

See section 9 of [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
