-- © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
-- NPI: drop any cached negative results. Earlier builds cached "not found" for 30 days, so a
-- transient failure could make a valid NPI keep reporting "no record". Negatives are no longer
-- cached at all; this clears any already-poisoned rows so valid NPIs resolve on next lookup.
DELETE FROM npi_cache WHERE found = 0;
