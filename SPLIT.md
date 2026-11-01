# FlxCoreHC — Healthcare Edition

This app is the **healthcare credentialing** fork of FlxCoreHC, split from the shared codebase
at v1.87.0. Its sibling is the Insurance Edition; the two are now developed independently.

## How the edition lock works
- `app/bootstrap.php` defines `FLXCRED_EDITION = 'healthcare'`, and `install.php` mirrors it in
  `FLX_INSTALL_EDITION`. Keep the two in sync.
- `edition_shapes()` (bootstrap) returns only this edition's organization shapes (billing_company, medical_group,
  solo_practice, plus custom/platform). Registration, the installer, and `AuthService::registerTenant()`
  all validate against it, so an insurance tenant can never be created here.
- The insurance demo builder is hidden and its admin action blocked.
- Everything downstream (nav, training library, templates, vocabulary) follows the tenant's
  vertical, so with only healthcare tenants possible, only healthcare UI/content ever renders.

## Insurance code still present (inert)
Insurance/MA services, views, migrations, and seeded content (tagged `vertical='insurance'`)
still exist in the tree but are unreachable — no tenant can activate them. Prune them
incrementally as this fork diverges; nothing depends on removing them for correct behavior.
