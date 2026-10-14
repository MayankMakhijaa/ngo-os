-- =====================================================================
-- Fameweb NGO Management SaaS
-- Migration: 003_admin_support_access
-- Fameweb admins can reset an NGO user's password with a temporary
-- password; the user must then choose a new one at the next sign-in.
-- (Audited "sign in as NGO Super Admin" sessions need no schema change:
-- they are recorded in audit_logs.)
-- =====================================================================

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Set when a Fameweb admin issues a temporary password'
        AFTER password_changed_at;
