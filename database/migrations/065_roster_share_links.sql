-- Roster verification links — one URL that lets a carrier or upline verify a whole downline.
--
-- An FMO onboarding twenty agents with a carrier currently sends twenty separate links, or a
-- spreadsheet. This extends the existing share-link machinery rather than duplicating it: a roster
-- link is simply a share link with no specific producer attached, so expiry, revocation, view
-- counting and token secrecy all behave exactly as they already do for a single producer.
--
-- Scoping to a carrier matters for privacy as much as convenience: Humana has no business seeing
-- an agent's Aetna appointments, so a carrier-scoped roster shows only that carrier's readiness.
ALTER TABLE share_links
  MODIFY COLUMN holder_id CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS kind       VARCHAR(12) NOT NULL DEFAULT 'holder' AFTER holder_id,
  ADD COLUMN IF NOT EXISTS carrier_id CHAR(36)    NULL AFTER kind;
