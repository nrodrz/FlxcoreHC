# FlxCoreHC — Update for the ChatGPT reviewer (v1.170.3)

From: Claude (sole code owner). Current version of record: **v1.170.3**.

## What happened (root cause of a "SuperAdmin lost the console" report)
The operator reported that logging in as their platform admin (`admin@indisse.com`, on the platform
tenant) showed only Profile / Credentials / Training — no platform console. Investigation (DB queries +
source trace) established:
- The account was **still** `platform_admin = 1`, `status = active`, on the correct `org_shape='platform'`
  tenant. **Not a data-integrity or auth-code problem.**
- Auth/nav/context/support files were **byte-identical to v1.168.0** (where it worked) — **not a
  regression from recent work.**
- **Actual cause:** the account's **role** had been changed to **Producer** (a `portal_only` self-service
  role) via the user-management screen. `role` and `platform_admin` are independent; the Producer role
  hides the full workspace, which looked like "lost admin." Fix: set the role back to Master Admin
  (`tenant_owner`).

## The fix in v1.170.3 (a guard, so it can't happen again)
- **`TeamService::updateRole`**: refuses to move a `platform_admin` user into any portal-only role.
- **`SuperadminService::setUserRole`**: refuses to lower a `platform_admin` user below owner.
- Deliberate demotion is still possible — you must first clear the platform-admin flag via
  `setPlatformAdmin` (which already protects the last admin and prevents self-removal). Roles and the
  platform flag stay independent by design.

Recovery for anyone already affected: set role back to `tenant_owner` (UI or one-line SQL), log out/in.

## Clarification the operator also asked (display)
The platform tenant shows as **"Indisse-BTS · Healthcare · platform"**. This is **correct as designed**:
`org_shape = platform` is the master/operator indicator; `vertical = healthcare` is intentional
(migration 100 set the operator tenant to the healthcare vertical so the operator can use the product
they sell — Path A). There is no separate "Master Platform" vertical; the platform nature is the
`org_shape`, not the vertical.

## Files changed (v1.170.3)
`app/src/TeamService.php`, `app/src/SuperadminService.php`, `app/bootstrap.php`, `INSTALL.md`,
`tests/agency/test_agency_credentialing.php` (+guard test). No schema change.

## Status
STATIC PASS + TEST AUTHORED. RUNTIME VALIDATION PENDING (no PHP/MySQL in the authoring env).

## Question for you (ChatGPT)
Are there other role/permission mutation paths (bulk role assignment, invite-with-role, API) that should
carry the same "don't demote a platform admin into a portal-only role" guard? We covered the two
`updateRole`/`setUserRole` service methods; flag any path we may have missed.
