-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Manual invoicing + payment ledger (v1.56). For operators collecting payment OUTSIDE the app
-- (check, ACH, wire, card-on-file elsewhere): issue an invoice, email it, and record the
-- payment when it arrives. Independent of the Stripe subscription path — an operator can use
-- either or both. All amounts are stored in whole cents to avoid float drift.

CREATE TABLE IF NOT EXISTS invoices (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,               -- the client organization being billed
  number        VARCHAR(20)  NOT NULL,               -- human-readable, e.g. INV-000123
  status        VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft|sent|paid|partial|void
  currency      CHAR(3)      NOT NULL DEFAULT 'USD',
  subtotal_cents INT         NOT NULL DEFAULT 0,
  total_cents   INT          NOT NULL DEFAULT 0,      -- subtotal (+ tax later if added)
  paid_cents    INT          NOT NULL DEFAULT 0,      -- running sum of applied payments
  issue_date    DATE         NOT NULL,
  due_date      DATE         NULL,
  notes         VARCHAR(500) NULL,
  created_by    CHAR(36)     NULL,
  created_at    DATETIME     NOT NULL,
  sent_at       DATETIME     NULL,
  paid_at       DATETIME     NULL,
  voided_at     DATETIME     NULL,
  UNIQUE KEY uq_invoice_number (number),
  INDEX idx_invoice_tenant (tenant_id, status),
  CONSTRAINT fk_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    CHAR(36)     NOT NULL,
  invoice_id   CHAR(36)     NOT NULL,
  description  VARCHAR(255) NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_cents   INT          NOT NULL DEFAULT 0,
  amount_cents INT          NOT NULL DEFAULT 0,       -- quantity * unit
  sort_order   INT          NOT NULL DEFAULT 0,
  INDEX idx_item_invoice (invoice_id),
  CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The payment ledger: every payment received, optionally applied to an invoice.
CREATE TABLE IF NOT EXISTS payments (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id     CHAR(36)     NOT NULL,
  invoice_id    CHAR(36)     NULL,                    -- nullable: a credit/on-account payment
  amount_cents  INT          NOT NULL,
  currency      CHAR(3)      NOT NULL DEFAULT 'USD',
  method        VARCHAR(20)  NOT NULL DEFAULT 'other', -- check|ach|wire|card|cash|other
  reference     VARCHAR(120) NULL,                     -- check no., confirmation code, etc.
  received_on   DATE         NOT NULL,
  notes         VARCHAR(500) NULL,
  recorded_by   CHAR(36)     NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_payment_tenant (tenant_id, received_on),
  INDEX idx_payment_invoice (invoice_id),
  CONSTRAINT fk_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Simple per-operator invoice counter for sequential numbering.
CREATE TABLE IF NOT EXISTS invoice_counter (
  id       TINYINT     NOT NULL PRIMARY KEY DEFAULT 1,
  next_seq INT         NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO invoice_counter (id, next_seq) VALUES (1, 1);
