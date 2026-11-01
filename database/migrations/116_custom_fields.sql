-- 116_custom_fields.sql
-- Custom fields (definitions + values). Each organization defines its own extra fields for providers
-- or for the organization itself — label, type, required, options, order — with NO schema change per
-- field. Values are stored per definition per record. Forms render the definitions dynamically;
-- exports, packets and the provider CSV include them.

CREATE TABLE IF NOT EXISTS custom_field_definitions (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  applies_to  VARCHAR(12)  NOT NULL,           -- provider | tenant
  code        VARCHAR(40)  NOT NULL,           -- stable key, e.g. 'pr_license_board'
  label       VARCHAR(120) NOT NULL,
  help_text   VARCHAR(240) NULL,
  field_type  VARCHAR(12)  NOT NULL DEFAULT 'text',   -- text|textarea|number|date|select|checkbox|email|phone|url
  options     JSON         NULL,               -- for select: ["A","B",...]
  is_required TINYINT(1)   NOT NULL DEFAULT 0,
  show_in_packet TINYINT(1) NOT NULL DEFAULT 1,
  show_in_list   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_cfd (tenant_id, applies_to, code),
  KEY idx_cfd_tenant (tenant_id, applies_to, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_values (
  id            CHAR(36)   NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)   NOT NULL,
  definition_id CHAR(36)   NOT NULL,
  record_id     CHAR(36)   NOT NULL,           -- credential_holders.id or tenants.id
  value_text    TEXT       NULL,
  updated_by    CHAR(36)   NULL,
  updated_at    DATETIME   NOT NULL,
  UNIQUE KEY uq_cfv (definition_id, record_id),
  KEY idx_cfv_record (tenant_id, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
