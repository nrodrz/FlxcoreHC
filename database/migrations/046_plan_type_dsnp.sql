-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- Align policy plan types with carrier lines (v1.52): add DSNP.
-- D-SNP is a large and growing share of the Florida MA market, and v1.51 already lists it
-- as a carrier line of business — so the book of business must be able to record it too.
ALTER TABLE policies DROP CONSTRAINT chk_pol_type;
ALTER TABLE policies ADD CONSTRAINT chk_pol_type
  CHECK (plan_type IN ('MAPD','MA','PDP','MEDSUPP','DSNP','OTHER'));
