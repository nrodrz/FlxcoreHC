-- FlxCoreHC — compliance-aware scheduling (booking/appointment engine).
-- Booking types expose an unguessable public slug; bookings capture invitee contact
-- (encrypted), TCPA consent, and — for marketing appointments — a Scope of Appointment
-- acknowledgment BEFORE the meeting, as CMS requires. Data-minimized: no health/PHI
-- fields, constrained reason list, neutral confirmations.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS booking_types (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NOT NULL,
  holder_id         CHAR(36)     NOT NULL,            -- the agent this books time with
  slug              VARCHAR(40)  NOT NULL,            -- unguessable public identifier
  name              VARCHAR(120) NOT NULL,
  description       VARCHAR(400) NULL,
  duration_min      SMALLINT     NOT NULL DEFAULT 30,
  location_type     VARCHAR(12)  NOT NULL DEFAULT 'phone',  -- phone|video|in_person
  location_detail   VARCHAR(300) NULL,
  category          VARCHAR(12)  NOT NULL DEFAULT 'general', -- marketing|service|general
  requires_soa      TINYINT(1)   NOT NULL DEFAULT 0,        -- Scope of Appointment gate
  buffer_before_min SMALLINT     NOT NULL DEFAULT 0,
  buffer_after_min  SMALLINT     NOT NULL DEFAULT 0,
  min_notice_hours  SMALLINT     NOT NULL DEFAULT 12,
  daily_cap         SMALLINT     NOT NULL DEFAULT 8,
  avail_days        VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5', -- weekday nums 0=Sun..6=Sat
  avail_start       CHAR(5)      NOT NULL DEFAULT '09:00',
  avail_end         CHAR(5)      NOT NULL DEFAULT '17:00',
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME     NULL DEFAULT NULL,
  UNIQUE KEY uq_bt_slug (slug),
  UNIQUE KEY uq_bt_tenant (tenant_id, id),
  INDEX idx_bt_tenant (tenant_id, active),
  CONSTRAINT fk_bt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_bt_holder FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id),
  CONSTRAINT chk_bt_loc  CHECK (location_type IN ('phone','video','in_person')),
  CONSTRAINT chk_bt_cat  CHECK (category IN ('marketing','service','general'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NOT NULL,
  booking_type_id   CHAR(36)     NOT NULL,
  holder_id         CHAR(36)     NOT NULL,
  start_at          DATETIME     NOT NULL,
  end_at            DATETIME     NOT NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'confirmed', -- confirmed|cancelled|completed|no_show
  -- invitee contact (encrypted at rest; email blind-indexed for dedup/lookup)
  invitee_name_enc  VARBINARY(512) NULL,
  invitee_email_enc VARBINARY(512) NULL,
  invitee_email_bidx CHAR(64)    NULL,
  invitee_phone_enc VARBINARY(255) NULL,
  -- TCPA consent (data-minimized, explicit)
  consent_call      TINYINT(1)   NOT NULL DEFAULT 0,
  consent_text      TINYINT(1)   NOT NULL DEFAULT 0,
  consent_email     TINYINT(1)   NOT NULL DEFAULT 0,
  -- Scope of Appointment (CMS)
  soa_required      TINYINT(1)   NOT NULL DEFAULT 0,
  soa_ack           TINYINT(1)   NOT NULL DEFAULT 0,
  soa_products      VARCHAR(200) NULL,               -- constrained product categories, not PHI
  soa_ack_at        DATETIME     NULL,
  reason            VARCHAR(60)  NULL,               -- constrained list, no free-text PHI
  manage_token      CHAR(40)     NOT NULL,           -- unguessable reschedule/cancel token
  source            VARCHAR(10)  NOT NULL DEFAULT 'public', -- public|manual
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME     NULL DEFAULT NULL,
  UNIQUE KEY uq_bk_token (manage_token),
  INDEX idx_bk_tenant (tenant_id, start_at),
  INDEX idx_bk_slot (holder_id, start_at, status),
  INDEX idx_bk_type (tenant_id, booking_type_id),
  CONSTRAINT fk_bk_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_bk_type   FOREIGN KEY (tenant_id, booking_type_id) REFERENCES booking_types(tenant_id, id),
  CONSTRAINT fk_bk_holder FOREIGN KEY (tenant_id, holder_id) REFERENCES credential_holders(tenant_id, id),
  CONSTRAINT chk_bk_status CHECK (status IN ('confirmed','cancelled','completed','no_show'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
