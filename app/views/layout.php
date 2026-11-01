<?php /** @var string $viewFile */
$brand = $GLOBALS['flx_brand'] ?? [];          // {accent, accent2, logo, name, themeCss, mode}
// Colour scheme: the person's own choice wins; otherwise fall back to the tenant's default.
// 'auto' defers to the OS preference, applied client-side before paint to avoid a flash.
$__mode = $brand['mode'] ?? 'light';
if (isset($_COOKIE['flx_theme'])) {
    $theme = $_COOKIE['flx_theme'] === 'dark' ? 'dark' : 'light';
} else {
    $theme = $__mode === 'dark' ? 'dark' : 'light';
}
?>
<?php if (!empty($standalone)) { require $viewFile; return; } ?>
<!doctype html>
<html lang="<?= e(FlxCoreHC\I18n::locale()) ?>" data-theme="<?= $theme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e(FLXCOREHC_APP_NAME) ?><?= isset($title) ? ' — ' . e($title) : '' ?></title>
<?php $__fonts = (string) config('fonts', 'self'); ?>
<?php if ($__fonts === 'google'): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php elseif ($__fonts === 'self'): ?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/fonts/fonts.css?v=<?= e(FLXCOREHC_VERSION) ?>">
<?php endif; /* 'system' loads nothing — the stack falls back to system-ui */ ?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/app.css?v=<?= e(FLXCOREHC_VERSION) ?>">
<?php if (!empty($brand['themeCss'])): ?>
<style <?= nonce_attr() ?>><?= $brand['themeCss'] ?></style>
<?php endif; ?>
<?php if (($__mode ?? '') === 'auto' && !isset($_COOKIE['flx_theme'])): ?>
<script <?= nonce_attr() ?>>
/* Tenant default is "match the device". Applied before first paint so there's no flash. */
try{if(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches){
document.documentElement.setAttribute('data-theme','dark');}}catch(e){}
</script>
<?php endif; ?>
</head>
<body>
<?php if (!empty($bare)): ?>
  <div class="auth-wrap"><?php require $viewFile; ?></div>
<?php else: ?>
  <?php
    $nav = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (BASE_PATH !== '' && str_starts_with($nav, BASE_PATH)) { $nav = substr($nav, strlen(BASE_PATH)) ?: '/'; }
    $hubPaths = ['/organization', '/settings', '/team', '/billing', '/audit'];
    $on = function (string $p) use ($nav, $hubPaths): string {
        if ($p === '/organization') {
            foreach ($hubPaths as $hp) { if ($nav === $hp || str_starts_with($nav, $hp . '/')) return 'on'; }
            return '';
        }
        return ($p === '/' ? $nav === '/' : ($nav === $p || str_starts_with($nav, $p . '/'))) ? 'on' : '';
    };
    $canTeam = isset($ctx) && in_array($ctx->role, ['tenant_owner','manager'], true);
    $canBill = isset($ctx) && FlxCoreHC\Authz::can($ctx, 'billing.view');
    // Icons resolve through IconService so an agency's per-tenant overrides apply everywhere.
    $__navIcons = FlxCoreHC\IconService::overrides($brand['navIcons'] ?? null);
    $ic = function(string $n) use ($__navIcons): string {
        return FlxCoreHC\IconService::svg($n, $__navIcons);
    };
    $item = function(string $href, string $label, string $icon) use ($ic, $on) {
        $label = t($label);
        return '<a href="' . BASE_PATH . $href . '" class="nav-i ' . $on($href) . '" data-tip="' . e($label) . '">'
            . '<span class="ni-ic">' . $ic($icon) . '</span><span class="ni-l">' . e($label) . '</span></a>';
    };
    $initial = strtoupper(substr((string)($ctx->userName ?? '?'), 0, 1));
  ?>
  <div class="shell" id="shell">
    <div class="scrim"></div>
    <aside class="side">
      <div class="side-top">
        <div class="brand">
          <?php if (!empty($brand['logo'])): ?><img class="brand-logo" src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name'] ?? 'Logo') ?>">
          <?php else: ?><span class="mark">FC</span><?php endif; ?>
        </div>
      </div>
      <nav id="navacc">
        <?php if (isset($ctx) && FlxCoreHC\RoleService::isPortalContext($ctx)): ?>
        <div class="nav-solo"><?= $item('/me', 'My compliance', 'grid') ?></div>
        <div class="nav-solo"><?= $item('/training', 'Training Center', 'book') ?></div>
        <?php else: ?>
        <div class="nav-solo"><?= $item('/', 'Dashboard', 'nav.dashboard') ?></div>
        <div class="nav-group" data-group="schedule">
          <button type="button" class="ng-title" data-navtoggle="schedule"><span class="ng-ic"><?= $ic('nav.schedule') ?></span><span class="ng-label"><?= e(t('Schedule')) ?></span><svg class="ng-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
          <div class="ng-items">
          <?= $item('/calendar', 'Calendar', 'nav.calendar') ?>
          </div>
        </div>
        <div class="nav-group" data-group="clients">
          <button type="button" class="ng-title" data-navtoggle="clients"><span class="ng-ic"><?= $ic('nav.clients') ?></span><span class="ng-label"><?= e(t('Clients')) ?></span><svg class="ng-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
          <div class="ng-items">
          <?= $item('/holders', vocab('holders'), 'nav.producers') ?>
          <?= $item('/credentials', 'Credentials', 'nav.credentials') ?>
          <?php if (isset($ctx) && $ctx->orgShape !== 'solo_practice' && FlxCoreHC\Authz::can($ctx, 'org.manage')) echo $item('/groups', FlxCoreHC\GroupService::label($ctx->orgShape, true), 'nav.clients'); ?>
          <?php if (isset($ctx) && FlxCoreHC\Authz::can($ctx, 'org.manage')) echo $item('/locations', 'Locations', 'flag'); ?>
          <?php if (isset($ctx) && FlxCoreHC\TeamService::canManage($ctx)) echo $item('/applications', 'Applications', 'clock'); ?>
          </div>
        </div>
        <div class="nav-group" data-group="compliance">
          <button type="button" class="ng-title" data-navtoggle="compliance"><span class="ng-ic"><?= $ic('nav.compliance') ?></span><span class="ng-label"><?= e(t('Compliance')) ?></span><svg class="ng-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
          <div class="ng-items">
          <?= $item('/compliance', 'Compliance', 'check') ?>
          <?= $item('/entity', 'Entity compliance', 'building') ?>
          <?php if (isset($ctx) && FlxCoreHC\TeamService::canManage($ctx)) echo $item('/regulators', 'Regulators', 'flag'); ?>
          <?= $item('/templates', 'Templates', 'nav.documents') ?>
          <?= $item('/training', 'Training', 'nav.training') ?>
          <?php if (feature_on('analytics')) echo $item('/reports', 'Reports', 'nav.reports'); ?>
          </div>
        </div>
        <?php if ($canTeam || (isset($ctx) && $ctx->isPlatformAdmin)): ?>
        <div class="nav-group" data-group="org">
          <button type="button" class="ng-title" data-navtoggle="org"><span class="ng-ic"><?= $ic('nav.organization') ?></span><span class="ng-label"><?= e(t('Organization')) ?></span><svg class="ng-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></button>
          <div class="ng-items">
          <?php if ($canTeam) { echo $item('/organization', 'Organization', 'gear'); echo $item('/organization/email', 'Email delivery', 'nav.mail'); } ?>
          <?php if ($canBill) echo $item('/billing/invoices', 'Billing', 'nav.billing'); ?>
          <?php if (isset($ctx) && $ctx->isPlatformAdmin): ?>
            <a href="<?= BASE_PATH ?>/admin" class="nav-i <?= $on('/admin') ?>" data-tip="Platform admin"><span class="ni-ic" style="color:var(--gold)"><?= $ic('nav.platform') ?></span><span class="ni-l">Platform admin</span></a>
          <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </nav>
    </aside>

    <div class="content">
      <header class="topbar">
        <button class="hamburger" id="hamburger" aria-label="Open menu">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button class="tb-btn collapse-btn" id="collapseBtn" title="Collapse menu" aria-label="Toggle menu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h16"/></svg>
        </button>
        <div class="tb-title"><?= isset($title) ? e($title) : e($brand['name'] ?? FLXCOREHC_APP_NAME) ?></div>
        <div class="spacer"></div>
        <?php if (isset($ctx) && !in_array($ctx->role, ['producer', 'provider'], true) && !FlxCoreHC\RoleService::isPortalContext($ctx)):
          $__unread = FlxCoreHC\NotificationService::unreadCount($ctx); ?>
        <a class="tb-btn" href="<?= BASE_PATH ?>/notifications" title="<?= e(t('Notifications')) ?>" aria-label="<?= e(t('Notifications')) ?>" style="position:relative;text-decoration:none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          <?php if ($__unread > 0): ?><span style="position:absolute;top:2px;right:2px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:var(--danger,#be4b42);color:#fff;font-size:.62rem;font-weight:700;line-height:16px;text-align:center"><?= $__unread > 99 ? '99+' : (int)$__unread ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <button class="tb-btn" data-action="theme-toggle" title="Toggle theme" aria-label="Toggle light/dark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
        <div class="menu" id="profileMenu">
          <button class="tb-profile" data-menu-btn aria-haspopup="true">
            <span class="tb-avatar"><?= e($initial) ?></span>
            <span class="who"><b><?= e($ctx->userName ?? '') ?></b><i><?= e($ctx->tenantName ?? '') ?></i></span>
            <svg class="caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="menu-pop">
            <div class="mlabel"><?= e($ctx->userName ?? '') ?></div>
            <a href="<?= BASE_PATH ?>/profile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg> Your profile</a>
            <?php if ($canTeam): ?><a href="<?= BASE_PATH ?>/settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg> Organization settings</a><?php endif; ?>
            <button data-action="theme-toggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg> <span data-theme-label>Dark mode</span></button>
            <?php $__curloc = FlxCoreHC\I18n::locale(); $__other = $__curloc === 'es' ? 'en' : 'es'; ?>
            <a href="<?= BASE_PATH ?>/?setlang=<?= $__other ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg> <?= e(FlxCoreHC\I18n::LOCALES[$__other]) ?></a>
            <a href="<?= BASE_PATH ?>/help"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/></svg> <?= e(t('Help')) ?></a>
            <div class="sep"></div>
            <form method="post" action="<?= BASE_PATH ?>/logout" data-novalidate style="margin:0">
              <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
              <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12H3M9 6l-6 6 6 6M13 4h6v16h-6"/></svg> Sign out</button>
            </form>
          </div>
        </div>
      </header>

      <main>
<?php
// Return-to-dashboard bar: when a user drills in from the dashboard (?from=dashboard) or from the
// work queue, keep a persistent way back so they never need the side panel to return.
$__from = $_GET['from'] ?? null;
if ($__from === 'dashboard') { $_SESSION['nav_from_dashboard'] = time(); }
elseif (isset($_SESSION['nav_from_dashboard']) && (time() - (int)$_SESSION['nav_from_dashboard']) > 1800) { unset($_SESSION['nav_from_dashboard']); }
$__onDash = (parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === (BASE_PATH ?: '') . '/' ) || str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/dashboard');
if (!empty($_SESSION['nav_from_dashboard']) && !$__onDash && isset($ctx)): ?>
<div class="return-bar">
  <a href="<?= BASE_PATH ?>/" class="return-link">← <?= e(t('Back to dashboard')) ?></a>
  <span class="hint"><?= e(t('Working from the queue')) ?></span>
  <a href="<?= BASE_PATH ?>/#workqueue" class="hint" style="margin-left:auto"><?= e(t('Next item')) ?> →</a>
</div>
<?php endif; ?>
        <?php if (!empty($_SESSION['auth']['impersonated_by'])): ?>
        <div style="background:color-mix(in srgb, var(--accent) 10%, transparent);border:1px solid color-mix(in srgb, var(--accent) 40%, transparent);border-radius:10px;padding:.55rem .9rem;margin-bottom:1rem;font-size:.85rem;display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
          <span style="font-weight:600;color:var(--accent)">Platform console — you are viewing <?= e($ctx->tenantName ?? '') ?> as its owner.</span>
          <form method="post" action="<?= BASE_PATH ?>/impersonate/return" style="margin:0">
            <?= FlxCoreHC\Auth\CsrfGuard::field() ?>
            <button class="btn sm line">Return to console</button>
          </form>
        </div>
        <?php endif; ?>
        <?php if (($GLOBALS['flx_tenant_status'] ?? 'active') === 'pending'): ?>
        <div style="background:color-mix(in srgb, var(--warn, #b6862c) 12%, transparent);border:1px solid color-mix(in srgb, var(--warn, #b6862c) 40%, transparent);color:var(--warn,#b6862c);border-radius:10px;padding:.6rem .9rem;margin-bottom:1rem;font-size:.85rem;font-weight:600">
          Your organization is under review — you have read-only access until it's approved.
        </div>
        <?php endif; ?>
        <?php $bill = $GLOBALS['flx_billing'] ?? null; if ($bill && !empty($bill['notice'])):
          $cls = ($bill['level']==='locked'||$bill['level']==='warn') ? 'error' : 'flash'; ?>
          <div class="<?= $cls ?>"><?= e($bill['notice']) ?>
            <?php if (in_array($ctx->role,['tenant_owner','manager'],true)): ?> <a href="<?= BASE_PATH ?>/billing" style="color:inherit;font-weight:700;text-decoration:underline">Billing</a><?php endif; ?></div>
        <?php endif; ?>
        <?php if (isset($ctx) && $ctx && $ctx->isPlatformAdmin && empty($_SESSION['auth']['impersonated_by']) && (string) config('crypto_data_key', '') !== '' && !FlxCoreHC\SettingsService::get('keys_backup_confirmed_at')): ?>
          <div class="error" style="margin-bottom:1rem"><strong>Back up your encryption keys.</strong> If <code>app/config.php</code> is lost, encrypted provider data cannot be recovered.
            <a href="<?= BASE_PATH ?>/admin/system#keys" style="color:inherit;font-weight:700;text-decoration:underline">Download the passphrase-protected backup</a></div>
        <?php endif; ?>
        <?php /* v1.172.0: while two-factor is not required for the platform console, remind the operator inside the console (not a blocker). */
        if (isset($ctx) && $ctx && $ctx->isPlatformAdmin && empty($_SESSION['auth']['impersonated_by']) && ($nav === '/admin' || str_starts_with($nav, '/admin/')) && !FlxCoreHC\Auth\AuthService::platformRequires2fa()): ?>
          <div class="flash" data-notice="platform-2fa-optional" style="margin-bottom:1rem"><strong>Two-factor is not required for platform administrators.</strong> Fine while you set the installation up; turn it on before adding customer organizations.
            <a href="<?= BASE_PATH ?>/admin/security#signin-policy" style="color:inherit;font-weight:700;text-decoration:underline">Sign-in policy</a><?php if (!$ctx->twoFactor): ?> · <a href="<?= BASE_PATH ?>/profile#two-factor" style="color:inherit;font-weight:700;text-decoration:underline">Set up two-factor for my account</a><?php endif; ?></div>
        <?php endif; ?>
        <?php /* v1.172.5: Microsoft 365 client secret expiry warning (platform console only). */
        if (isset($ctx) && $ctx && $ctx->isPlatformAdmin && empty($_SESSION['auth']['impersonated_by']) && ($nav === '/admin' || str_starts_with($nav, '/admin/')) && ($__msn = FlxCoreHC\MicrosoftOAuth::secretNotice())): ?>
          <div class="<?= $__msn['level'] === 'error' ? 'error' : 'flash' ?>" data-notice="ms-secret-expiry" style="margin-bottom:1rem"><?= e($__msn['text']) ?>
            <a href="<?= BASE_PATH ?>/admin/email" style="color:inherit;font-weight:700;text-decoration:underline">Email</a></div>
        <?php endif; ?>
        <?php if ($m = flash()): ?><span data-toast="<?= e(t($m)) ?>" data-toast-type="<?= e($GLOBALS['flx_flash_last_type'] ?? 'ok') ?>" hidden></span><?php endif; ?>
        <?php require $viewFile; ?>
      </main>
    </div>
  </div>
  <div id="toasts"></div>
  <script <?= nonce_attr() ?> src="<?= BASE_PATH ?>/assets/app.js?v=<?= e(FLXCOREHC_VERSION) ?>" defer></script>
<?php endif; ?>
</body>
</html>
