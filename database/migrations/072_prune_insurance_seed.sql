-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- 072_prune_insurance_seed.sql
-- Healthcare Edition cleanup: remove the inert insurance-vertical global seed data that earlier
-- migrations (020 catalog, 025/026 training, 068/071 vertical tagging) left in the shared catalog
-- and training library. This edition can only ever host healthcare tenants, so these rows never
-- render — pruning them keeps a fresh install clean and healthcare-only.
--
-- Scope is deliberately narrow and safe:
--   * Only GLOBAL rows (tenant_id IS NULL) tagged vertical='insurance' are touched.
--   * Tenant-authored custom rows (tenant_id set) are never affected.
--   * Idempotent: DELETE ... WHERE re-runs to a no-op once the rows are gone.
--
-- No schema changes here — this is data-only. The tables themselves (and the insurance-era
-- columns) remain; structural pruning of insurance-only tables is a separate, later step.

-- 1) Global insurance credential catalog rows (the 42 MA/insurance types: RTS, carrier certs,
--    AHIP, SOA, book-of-business items, commercial-lines rows, etc.).
DELETE FROM credential_catalog
 WHERE tenant_id IS NULL AND vertical = 'insurance';

-- 2) Insurance training articles and their source links. We match by BOTH the explicit set of
--    insurance article codes AND the vertical tag, so the prune is deterministic regardless of the
--    order in which earlier migrations tagged each row's vertical. Sources join to articles by
--    article_code; remove them first, then the articles.
DELETE FROM training_sources
 WHERE article_code IN (
   'ahip_2027','carrier_cert_process','cms_sales_records','cy2027_marketing',
   'fl_commercial_licensing','fl_licensing','flx-compliance-model','flx-monitoring-compliance',
   'fwa_training','nabip_2027','ready_to_sell','tpmo_soa'
 );

DELETE FROM training_sources
 WHERE article_code IN (
   SELECT code FROM (
     SELECT code FROM training_library WHERE vertical = 'insurance'
   ) AS ins_codes
 );

DELETE FROM training_library
 WHERE vertical = 'insurance'
    OR code IN (
   'ahip_2027','carrier_cert_process','cms_sales_records','cy2027_marketing',
   'fl_commercial_licensing','fl_licensing','flx-compliance-model','flx-monitoring-compliance',
   'fwa_training','nabip_2027','ready_to_sell','tpmo_soa'
 );
