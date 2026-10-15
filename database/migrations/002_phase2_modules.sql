-- =====================================================================
-- Fameweb NGO Management SaaS - Phase 2 schema
-- Migration: 002_phase2_modules
-- Members / volunteers / interns, identity documents, salary slips,
-- certificate templates and certificates, 80G donation receipts and the
-- email outbox (delivery log with attempts and retries).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Per-tenant counters for human-readable numbers (member IDs, certificate
-- and receipt numbers). Rows are locked with SELECT ... FOR UPDATE inside
-- the transaction that uses the number, so two requests never get the same.
-- ---------------------------------------------------------------------
CREATE TABLE tenant_sequences (
    tenant_id   INT UNSIGNED NOT NULL,
    seq_key     VARCHAR(40)  NOT NULL COMMENT 'member, volunteer, intern, certificate, salary_slip, receipt:2026-27',
    last_value  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, seq_key),
    CONSTRAINT fk_sequences_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Members, volunteers and interns share one table.
-- Aadhaar: only the last 4 digits are stored. The full number is checked
-- (Verhoeff checksum) when entered and then discarded.
-- ---------------------------------------------------------------------
CREATE TABLE person_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    applies_to  ENUM('all','member','volunteer','intern') NOT NULL DEFAULT 'all',
    description VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_person_categories (tenant_id, name),
    CONSTRAINT fk_person_categories_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE people (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             INT UNSIGNED NOT NULL,
    person_type           ENUM('member','volunteer','intern') NOT NULL,
    member_code           VARCHAR(30)  NOT NULL COMMENT 'Member ID shown on ID cards, unique per NGO',
    name                  VARCHAR(120) NOT NULL,
    guardian_name         VARCHAR(120) NULL,
    gender                ENUM('female','male','other','undisclosed') NULL,
    date_of_birth         DATE         NULL,
    blood_group           VARCHAR(5)   NULL,
    email                 VARCHAR(190) NULL,
    phone                 VARCHAR(30)  NULL,
    address               TEXT         NULL,
    city                  VARCHAR(80)  NULL,
    state                 VARCHAR(80)  NULL,
    pincode               VARCHAR(10)  NULL,
    emergency_contact     VARCHAR(120) NULL,
    category_id           INT UNSIGNED NULL,
    designation           VARCHAR(120) NULL,
    department            VARCHAR(120) NULL,
    status                ENUM('pending','active','inactive','completed') NOT NULL DEFAULT 'active'
                          COMMENT 'pending = registered, not yet approved; completed = membership / internship finished',
    joined_on             DATE         NULL,
    ends_on               DATE         NULL COMMENT 'Membership validity or internship end date',
    monthly_amount        DECIMAL(12,2) NULL COMMENT 'Salary / stipend used to pre-fill salary slips',
    bank_name             VARCHAR(120) NULL,
    bank_account_last4    CHAR(4)      NULL COMMENT 'Only the last 4 digits are kept',
    bank_ifsc             VARCHAR(15)  NULL,
    aadhaar_last4         CHAR(4)      NULL,
    verification_status   ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted',
    verification_note     VARCHAR(255) NULL,
    verified_by           INT UNSIGNED NULL,
    verified_at           DATETIME     NULL,
    photo_object_id       BIGINT UNSIGNED NULL,
    notes                 TEXT         NULL,
    created_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_people_code (tenant_id, member_code),
    KEY idx_people_tenant_type (tenant_id, person_type, status),
    KEY idx_people_name (tenant_id, name),
    KEY idx_people_category (category_id),
    CONSTRAINT fk_people_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_people_category FOREIGN KEY (category_id) REFERENCES person_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_people_photo FOREIGN KEY (photo_object_id) REFERENCES storage_objects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline of everything that happened to a person (shown on their profile).
CREATE TABLE person_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     INT UNSIGNED    NOT NULL,
    person_id     INT UNSIGNED    NOT NULL,
    event         VARCHAR(50)     NOT NULL,
    summary       VARCHAR(255)    NOT NULL,
    actor_user_id INT UNSIGNED    NULL,
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_person_history (tenant_id, person_id, id),
    CONSTRAINT fk_person_history_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity and other documents. The file itself is a storage_objects row
-- (encrypted at rest, counted against the storage limit).
CREATE TABLE person_documents (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         INT UNSIGNED    NOT NULL,
    person_id         INT UNSIGNED    NOT NULL,
    doc_type          ENUM('aadhaar','pan','address_proof','photo_id','education','agreement','other') NOT NULL,
    title             VARCHAR(120)    NULL,
    storage_object_id BIGINT UNSIGNED NOT NULL,
    uploaded_by       INT UNSIGNED    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at        DATETIME        NULL,
    deleted_by        INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    KEY idx_person_documents (tenant_id, person_id, deleted_at),
    CONSTRAINT fk_person_documents_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT,
    CONSTRAINT fk_person_documents_object FOREIGN KEY (storage_object_id) REFERENCES storage_objects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE salary_slips (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED    NOT NULL,
    person_id        INT UNSIGNED    NOT NULL,
    slip_no          VARCHAR(30)     NOT NULL,
    period_month     CHAR(7)         NOT NULL COMMENT 'YYYY-MM',
    pay_type         ENUM('salary','stipend','honorarium') NOT NULL DEFAULT 'salary',
    earnings         TEXT            NOT NULL COMMENT 'JSON list of {label, amount}',
    deductions       TEXT            NOT NULL COMMENT 'JSON list of {label, amount}',
    gross_amount     DECIMAL(12,2)   NOT NULL,
    total_deductions DECIMAL(12,2)   NOT NULL,
    net_amount       DECIMAL(12,2)   NOT NULL,
    working_days     DECIMAL(5,1)    NULL,
    paid_days        DECIMAL(5,1)    NULL,
    payment_date     DATE            NULL,
    payment_mode     VARCHAR(40)     NULL,
    notes            VARCHAR(255)    NULL,
    created_by       INT UNSIGNED    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salary_slip_period (tenant_id, person_id, period_month),
    UNIQUE KEY uq_salary_slip_no (tenant_id, slip_no),
    KEY idx_salary_slips_month (tenant_id, period_month),
    CONSTRAINT fk_salary_slips_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Certificates. Templates are designed by Fameweb and assigned to NGOs.
-- An issued certificate keeps a copy of the layout and the filled-in
-- values, so later template edits never change certificates already issued.
-- ---------------------------------------------------------------------
CREATE TABLE certificate_templates (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    description  VARCHAR(255) NULL,
    orientation  ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
    layout       MEDIUMTEXT   NOT NULL COMMENT 'JSON: page style and positioned elements',
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_templates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificate_template_tenants (
    template_id INT UNSIGNED NOT NULL,
    tenant_id   INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id, tenant_id),
    KEY idx_ctt_tenant (tenant_id),
    CONSTRAINT fk_ctt_template FOREIGN KEY (template_id) REFERENCES certificate_templates (id) ON DELETE CASCADE,
    CONSTRAINT fk_ctt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED    NOT NULL,
    person_id        INT UNSIGNED    NOT NULL,
    template_id      INT UNSIGNED    NULL,
    certificate_no   VARCHAR(40)     NOT NULL,
    title            VARCHAR(150)    NOT NULL,
    layout_snapshot  MEDIUMTEXT      NOT NULL,
    data_snapshot    TEXT            NOT NULL COMMENT 'JSON placeholder values at issue time',
    issued_on        DATE            NOT NULL,
    status           ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
    revoked_reason   VARCHAR(255)    NULL,
    revoked_at       DATETIME        NULL,
    revoked_by       INT UNSIGNED    NULL,
    issued_by        INT UNSIGNED    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificates_no (tenant_id, certificate_no),
    KEY idx_certificates_person (tenant_id, person_id),
    CONSTRAINT fk_certificates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificates_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT,
    CONSTRAINT fk_certificates_template FOREIGN KEY (template_id) REFERENCES certificate_templates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 80G donation receipts. Numbered per NGO per financial year, immutable
-- once issued (cancellation keeps the row and the number). The NGO and
-- donor details are copied in, so a receipt always reprints identically.
-- ---------------------------------------------------------------------
CREATE TABLE donation_receipts (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          INT UNSIGNED    NOT NULL,
    receipt_no         VARCHAR(40)     NOT NULL,
    financial_year     CHAR(7)         NOT NULL COMMENT 'e.g. 2026-27',
    sequence_no        INT UNSIGNED    NOT NULL,
    donation_date      DATE            NOT NULL,
    donor_name         VARCHAR(150)    NOT NULL,
    donor_address      TEXT            NOT NULL,
    donor_email        VARCHAR(190)    NULL,
    donor_phone        VARCHAR(30)     NULL,
    donor_id_type      ENUM('pan','passport','voter_id','driving_licence','ration_card','tax_id','none') NOT NULL DEFAULT 'pan',
    donor_id_number    VARCHAR(30)     NULL,
    amount             DECIMAL(14,2)   NOT NULL,
    payment_mode       ENUM('cash','cheque','demand_draft','bank_transfer','upi','card','kind') NOT NULL,
    payment_reference  VARCHAR(100)    NULL COMMENT 'Cheque / DD / UTR / transaction number',
    bank_name          VARCHAR(120)    NULL,
    donation_type      ENUM('corpus','specific_grant','others') NOT NULL DEFAULT 'others',
    purpose            VARCHAR(255)    NULL,
    eligible_80g       TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 for cash above the limit or donations in kind',
    ngo_snapshot       TEXT            NOT NULL COMMENT 'JSON: name, address, PAN, 80G number and validity, logo, template',
    status             ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
    cancelled_reason   VARCHAR(255)    NULL,
    cancelled_at       DATETIME        NULL,
    cancelled_by       INT UNSIGNED    NULL,
    emailed_at         DATETIME        NULL,
    idempotency_key    VARCHAR(64)     NULL,
    issued_by          INT UNSIGNED    NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_no (tenant_id, receipt_no),
    UNIQUE KEY uq_receipts_seq (tenant_id, financial_year, sequence_no),
    UNIQUE KEY uq_receipts_idempotency (tenant_id, idempotency_key),
    KEY idx_receipts_date (tenant_id, donation_date),
    KEY idx_receipts_donor (tenant_id, donor_name),
    CONSTRAINT fk_receipts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Email outbox. Every email is stored before sending, sent at once when
-- possible, and retried with back-off by bin/cron.php when delivery fails.
-- Bodies are encrypted at rest; single-use links are scrubbed once the
-- message is delivered or finally fails.
-- ---------------------------------------------------------------------
CREATE TABLE email_outbox (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED    NULL COMMENT 'NULL = platform email',
    category         VARCHAR(40)     NOT NULL COMMENT 'invite, password_reset, receipt, certificate, test, ...',
    to_email         VARCHAR(190)    NOT NULL,
    to_name          VARCHAR(150)    NULL,
    subject          VARCHAR(255)    NOT NULL,
    body_encrypted   MEDIUMTEXT      NOT NULL,
    attachments      TEXT            NULL COMMENT 'JSON list of {type, id}; PDFs are regenerated at send time',
    status           ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at  DATETIME        NULL,
    locked_until     DATETIME        NULL,
    last_error       VARCHAR(500)    NULL,
    transport        VARCHAR(20)     NULL COMMENT 'log, mail, smtp',
    sent_at          DATETIME        NULL,
    idempotency_key  VARCHAR(120)    NULL,
    created_by       INT UNSIGNED    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_outbox_idempotency (idempotency_key),
    KEY idx_outbox_due (status, next_attempt_at),
    KEY idx_outbox_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outbox_id    BIGINT UNSIGNED NOT NULL,
    attempt_no   TINYINT UNSIGNED NOT NULL,
    transport    VARCHAR(20)     NOT NULL,
    success      TINYINT(1)      NOT NULL,
    response     VARCHAR(500)    NULL COMMENT 'SMTP reply or error, credentials never included',
    duration_ms  INT UNSIGNED    NULL,
    attempted_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_outbox (outbox_id, attempt_no),
    CONSTRAINT fk_attempts_outbox FOREIGN KEY (outbox_id) REFERENCES email_outbox (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
