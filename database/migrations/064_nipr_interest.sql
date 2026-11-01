-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Demand signal for NIPR / state-DOI licence verification.
--
-- NIPR verification is the most credible remaining gap against AgentSync, but committing to it
-- means an FCRA indemnity, a redistribution restriction on the data, and per-transaction pricing —
-- none of which should be signed before knowing whether customers actually want the feature.
-- This records that interest with no external call, no data leaving the system, and no cost.
CREATE TABLE IF NOT EXISTS nipr_interest (
  id         CHAR(36)    NOT NULL,
  tenant_id  CHAR(36)    NOT NULL,
  user_id    CHAR(36)    NULL,
  holder_id  CHAR(36)    NULL,          -- the producer they were looking at when they asked
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_nipr_interest_tenant (tenant_id),
  KEY idx_nipr_interest_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
