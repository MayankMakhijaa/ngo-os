# Phase 1 checklist

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

## Remaining / needs you

- ⏳ **Review and merge** the draft PR (`claude/phase-1` into `main`). The older `phase-1` branch holds the same code and can be deleted. Set `main` as the default branch in the GitHub settings.
- ⏳ **Deploy to Hostinger** following [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). The app has only been run locally so far, on PHP 8.3 with MariaDB 10.11.
- ⏳ **Real email delivery.** `MAIL_DRIVER=mail` uses PHP `mail()`. Add SMTP (for example Hostinger email or a transactional provider) if deliverability matters.
- ⏳ **Apache `.htaccess` rules** were written for Hostinger but were not exercised here, because the test environment uses PHP's built-in server. Run the "must return 403/404" items in the deployment checklist after the first upload.

## Future phases (designed, not built)

- 🔭 Members, volunteers and interns (limits already on plans and shown as "not available yet")
- 🔭 Documents (reuses private storage and the storage limit)
- 🔭 Certificates
- 🔭 80G receipts: the format must come from the NGO's approval and its CA. The software will not claim legal validity.
- 🔭 WordPress sync
- 🔭 Email automation through a cron-driven outbox
- 🔭 Razorpay payments and webhooks

See section 9 of [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
