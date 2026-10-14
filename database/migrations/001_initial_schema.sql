-- =====================================================================
-- Fameweb NGO Management SaaS - Phase 1 schema
-- Migration: 001_initial_schema
-- Compatible with MySQL 8.0.16+ and MariaDB 10.6+ (InnoDB, utf8mb4).
-- Shared database, shared schema. Every NGO-owned row carries tenant_id.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Tenants (NGOs)
-- ---------------------------------------------------------------------
CREATE TABLE tenants (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)     NOT NULL COMMENT 'Internal tenant ID, never reused',
    reference_code      VARCHAR(20)  NOT NULL COMMENT 'Human readable NGO reference, e.g. FW-NGO-00001',
    name                VARCHAR(190) NOT NULL,
    address             TEXT         NOT NULL,
    contact_name        VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    phone               VARCHAR(30)  NOT NULL,
    website             VARCHAR(255) NULL,
    registration_number VARCHAR(100) NULL,
    logo_object_id      BIGINT UNSIGNED NULL,
    notes               TEXT         NULL COMMENT 'Internal Fameweb notes, never shown to the NGO',
    status              ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
    status_reason       VARCHAR(255) NULL,
    status_changed_at   DATETIME     NULL,
    is_demo             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = demonstration record created by the demo seeder',
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_uuid (uuid),
    UNIQUE KEY uq_tenants_reference (reference_code),
    KEY idx_tenants_status (status),
    KEY idx_tenants_name (name),
    KEY idx_tenants_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     INT UNSIGNED NOT NULL,
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT         NULL,
    updated_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_settings (tenant_id, setting_key),
    CONSTRAINT fk_tenant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users. Platform (Fameweb) users have tenant_id NULL; NGO users must
-- have a tenant. The CHECK constraint makes it impossible at database
-- level for an NGO user row to be a platform user.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED NULL,
    user_type           ENUM('platform','tenant') NOT NULL,
    name                VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    phone               VARCHAR(30)  NULL,
    password_hash       VARCHAR(255) NULL COMMENT 'NULL until an invitation is accepted',
    status              ENUM('invited','active','inactive') NOT NULL DEFAULT 'invited',
    session_version     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bumped to invalidate all sessions of the user',
    last_login_at       DATETIME     NULL,
    last_login_ip       VARCHAR(45)  NULL,
    password_changed_at DATETIME     NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_tenant_status (tenant_id, status),
    KEY idx_users_type (user_type),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT chk_users_type CHECK (
        (user_type = 'platform' AND tenant_id IS NULL) OR (user_type = 'tenant' AND tenant_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- RBAC
-- ---------------------------------------------------------------------
CREATE TABLE permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    perm_key    VARCHAR(100) NOT NULL,
    scope       ENUM('platform','tenant') NOT NULL,
    module      VARCHAR(50)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (perm_key),
    KEY idx_permissions_scope (scope, module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NULL COMMENT 'NULL for platform roles; NGO roles always belong to one tenant',
    scope       ENUM('platform','tenant') NOT NULL,
    slug        VARCHAR(60)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Default role, cannot be deleted or renamed',
    is_locked   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Permissions cannot be edited (e.g. Super Admin)',
    tenant_key  INT UNSIGNED AS (IFNULL(tenant_id, 0)) STORED,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_tenant_slug (tenant_key, slug),
    KEY idx_roles_tenant (tenant_id),
    CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT chk_roles_scope CHECK (
        (scope = 'platform' AND tenant_id IS NULL) OR (scope = 'tenant' AND tenant_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_perm (permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 1 uses one primary role per user; the table already supports many.
CREATE TABLE user_roles (
    user_id     INT UNSIGNED NOT NULL,
    role_id     INT UNSIGNED NOT NULL,
    is_primary  TINYINT(1)   NOT NULL DEFAULT 1,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user overrides on top of the role: explicit grant or explicit deny.
CREATE TABLE user_permissions (
    user_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    effect        ENUM('grant','deny') NOT NULL DEFAULT 'grant',
    granted_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    KEY idx_user_permissions_perm (permission_id),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Plans and subscriptions
-- ---------------------------------------------------------------------
CREATE TABLE subscription_plans (
    id                       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name                     VARCHAR(100)  NOT NULL,
    slug                     VARCHAR(100)  NOT NULL,
    description              TEXT          NULL,
    monthly_price            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    annual_price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency                 CHAR(3)       NOT NULL DEFAULT 'INR',
    trial_days               SMALLINT UNSIGNED NULL,
    max_users                INT UNSIGNED  NULL COMMENT 'NULL = unlimited',
    max_members              INT UNSIGNED  NULL COMMENT 'Members + volunteers + interns. NULL = unlimited',
    max_storage_mb           INT UNSIGNED  NULL COMMENT 'NULL = unlimited',
    max_certificates         INT UNSIGNED  NULL COMMENT 'Reserved for the certificate module',
    status                   ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_demo                  TINYINT(1)    NOT NULL DEFAULT 0,
    gateway_plan_id_monthly  VARCHAR(100)  NULL COMMENT 'Future Razorpay plan id',
    gateway_plan_id_annual   VARCHAR(100)  NULL COMMENT 'Future Razorpay plan id',
    created_by               INT UNSIGNED  NULL,
    created_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_name (name),
    UNIQUE KEY uq_plans_slug (slug),
    KEY idx_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plan_modules (
    plan_id    INT UNSIGNED NOT NULL,
    module_key VARCHAR(50)  NOT NULL,
    PRIMARY KEY (plan_id, module_key),
    CONSTRAINT fk_plan_modules_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every plan assignment is a new row; old rows stay as history (is_current = 0).
-- current_tenant_id guarantees at most one current subscription per tenant.
CREATE TABLE subscriptions (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               INT UNSIGNED    NOT NULL,
    plan_id                 INT UNSIGNED    NOT NULL,
    status                  ENUM('trial','active','past_due','expired','suspended','cancelled') NOT NULL,
    billing_cycle           ENUM('monthly','annual','custom') NOT NULL DEFAULT 'monthly',
    price                   DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'Price agreed for this subscription (snapshot)',
    currency                CHAR(3)         NOT NULL DEFAULT 'INR',
    start_date              DATE            NOT NULL,
    end_date                DATE            NULL COMMENT 'Expiry / renewal date. NULL = open ended',
    trial_ends_at           DATE            NULL,
    is_current              TINYINT(1)      NOT NULL DEFAULT 1,
    current_tenant_id       INT UNSIGNED AS (IF(is_current = 1, tenant_id, NULL)) STORED,
    ended_at                DATETIME        NULL,
    end_reason              VARCHAR(50)     NULL,
    gateway                 VARCHAR(30)     NOT NULL DEFAULT 'manual',
    gateway_subscription_id VARCHAR(100)    NULL,
    notes                   TEXT            NULL,
    created_by              INT UNSIGNED    NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscriptions_current (current_tenant_id),
    UNIQUE KEY uq_subscriptions_gateway (gateway, gateway_subscription_id),
    KEY idx_subscriptions_tenant (tenant_id, created_at),
    KEY idx_subscriptions_status (status, is_current),
    KEY idx_subscriptions_end (end_date),
    CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id BIGINT UNSIGNED NOT NULL,
    tenant_id       INT UNSIGNED    NOT NULL,
    from_status     VARCHAR(20)     NULL,
    to_status       VARCHAR(20)     NOT NULL,
    note            VARCHAR(255)    NULL,
    actor_user_id   INT UNSIGNED    NULL COMMENT 'NULL = system (automatic expiry)',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sub_events_sub (subscription_id, created_at),
    KEY idx_sub_events_tenant (tenant_id, created_at),
    CONSTRAINT fk_sub_events_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_payments (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          INT UNSIGNED    NOT NULL,
    subscription_id    BIGINT UNSIGNED NOT NULL,
    amount             DECIMAL(12,2)   NOT NULL,
    currency           CHAR(3)         NOT NULL DEFAULT 'INR',
    status             ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'paid',
    method             ENUM('cash','bank_transfer','upi','cheque','card','razorpay','other') NOT NULL DEFAULT 'bank_transfer',
    reference          VARCHAR(120)    NULL COMMENT 'Bank / UPI / cheque reference',
    paid_at            DATETIME        NULL,
    period_start       DATE            NULL,
    period_end         DATE            NULL,
    gateway            VARCHAR(30)     NOT NULL DEFAULT 'manual',
    gateway_order_id   VARCHAR(100)    NULL,
    gateway_payment_id VARCHAR(100)    NULL,
    idempotency_key    VARCHAR(64)     NULL COMMENT 'Prevents duplicate recording on double submit / webhook retry',
    notes              TEXT            NULL,
    recorded_by        INT UNSIGNED    NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    UNIQUE KEY uq_payments_gateway (gateway, gateway_payment_id),
    KEY idx_payments_tenant (tenant_id, paid_at),
    KEY idx_payments_sub (subscription_id),
    KEY idx_payments_status (status, paid_at),
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Usage metering and file metadata
-- ---------------------------------------------------------------------
CREATE TABLE usage_records (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    metric          VARCHAR(40)     NOT NULL COMMENT 'emails, certificates, invoices, ...',
    quantity        BIGINT          NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(120)    NOT NULL COMMENT 'Same key = same event; retries are ignored',
    source_type     VARCHAR(50)     NULL,
    source_id       VARCHAR(64)     NULL,
    occurred_at     DATETIME        NOT NULL,
    recorded_by     INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usage_idempotency (tenant_id, idempotency_key),
    KEY idx_usage_metric (tenant_id, metric, occurred_at),
    CONSTRAINT fk_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_objects (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    tenant_id       INT UNSIGNED    NOT NULL,
    category        VARCHAR(40)     NOT NULL COMMENT 'logo, document, certificate, ...',
    original_name   VARCHAR(255)    NOT NULL,
    stored_path     VARCHAR(500)    NOT NULL COMMENT 'Relative to storage/private, never web accessible',
    mime_type       VARCHAR(100)    NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64)        NOT NULL,
    uploaded_by     INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL,
    deleted_by      INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_storage_uuid (uuid),
    KEY idx_storage_tenant (tenant_id, deleted_at, category),
    CONSTRAINT fk_storage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tenants
    ADD CONSTRAINT fk_tenants_logo FOREIGN KEY (logo_object_id) REFERENCES storage_objects (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Authentication support
-- ---------------------------------------------------------------------
-- Used for both password resets and invitations. Only a SHA-256 hash of
-- the token is stored; the plaintext exists only in the link.
CREATE TABLE password_reset_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NOT NULL,
    purpose     ENUM('reset','invite') NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_by  INT UNSIGNED    NULL,
    created_ip  VARCHAR(45)     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_hash (token_hash),
    KEY idx_tokens_user (user_id, purpose, used_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action       ENUM('login','password_reset') NOT NULL DEFAULT 'login',
    email        VARCHAR(190)    NOT NULL,
    ip           VARCHAR(45)     NOT NULL,
    success      TINYINT(1)      NOT NULL DEFAULT 0,
    user_id      INT UNSIGNED    NULL,
    attempted_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_email (email, action, attempted_at),
    KEY idx_attempts_ip (ip, action, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log. Append only: the application has no update/delete path, and
-- each row carries a SHA-256 hash chained to the previous row so that
-- tampering at database level is detectable (Admin > Audit > Verify).
-- tenant_id deliberately has no foreign key so logs outlive tenants.
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     INT UNSIGNED    NULL,
    actor_user_id INT UNSIGNED    NULL,
    actor_type    ENUM('platform','tenant','system','guest') NOT NULL,
    actor_role    VARCHAR(60)     NULL,
    action        VARCHAR(80)     NOT NULL,
    entity_type   VARCHAR(50)     NULL,
    entity_id     VARCHAR(64)     NULL,
    ip            VARCHAR(45)     NULL,
    user_agent    VARCHAR(255)    NULL,
    metadata      TEXT            NULL COMMENT 'JSON, sanitised: no passwords, tokens or ID numbers',
    prev_hash     CHAR(64)        NULL,
    row_hash      CHAR(64)        NOT NULL,
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_tenant (tenant_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Platform settings
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT         NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign keys to users that had to wait until users existed.
ALTER TABLE tenants ADD CONSTRAINT fk_tenants_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;
