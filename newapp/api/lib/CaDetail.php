<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT33 — a Conditional Access policy in plain words.
 *
 *   opsh_ca_ids($policy)              the directory ids a policy refers to (users, groups, roles, apps, locations)
 *   opsh_ca_describe($policy, $names) who it applies to, to which apps, under which conditions, what it requires,
 *                                     session limits, and warnings — as lists of short sentences
 *
 * Pure functions: no Graph calls here. The route (api/routes/m365.php, case 'ca-policy') reads the policy and
 * resolves the ids to names. An id that could not be resolved is shown as "unknown (deleted?)" and counted in a warning.
 * Read-only: nothing here changes a policy.
 */

/** Collect the ids to resolve, by kind. Special values such as "All" or "Office365" are not ids. */
function opsh_ca_ids(array $p): array
{
    $c = $p['conditions'] ?? []; $u = $c['users'] ?? []; $a = $c['applications'] ?? []; $l = $c['locations'] ?? [];
    // Mock and legacy ids are not always GUIDs: anything that is not one of Microsoft's keywords counts as an id.
    $ids = fn(array $v, array $kw): array => array_values(array_unique(array_filter($v, fn($x) => is_string($x) && $x !== '' && !in_array($x, $kw, true))));
    return [
        'users'     => $ids(array_merge($u['includeUsers'] ?? [], $u['excludeUsers'] ?? []), ['All', 'None', 'GuestsOrExternalUsers']),
        'groups'    => $ids(array_merge($u['includeGroups'] ?? [], $u['excludeGroups'] ?? []), []),
        'roles'     => $ids(array_merge($u['includeRoles'] ?? [], $u['excludeRoles'] ?? []), []),
        'apps'      => $ids(array_merge($a['includeApplications'] ?? [], $a['excludeApplications'] ?? []), ['All', 'None', 'Office365', 'MicrosoftAdminPortals']),
        'locations' => $ids(array_merge($l['includeLocations'] ?? [], $l['excludeLocations'] ?? []), ['All', 'AllTrusted']),
    ];
}

function opsh_ca_describe(array $p, array $names): array
{
    $c = $p['conditions'] ?? []; $u = $c['users'] ?? []; $a = $c['applications'] ?? [];
    $unknown = 0;
    $nm = function (string $kind, string $id) use ($names, &$unknown): string {
        $n = $names[$kind][$id] ?? ($names[$kind][strtolower($id)] ?? null);
        if ($n === null || $n === '') { $unknown++; return 'unknown (deleted?) ' . substr($id, 0, 8) . '…'; }
        return (string)$n;
    };
    $list = function (string $kind, array $ids) use ($nm): array { $o = []; foreach ($ids as $i) if (is_string($i) && $i !== '') $o[] = $nm($kind, $i); sort($o, SORT_NATURAL | SORT_FLAG_CASE); return $o; };
    $join = fn(array $v, int $max = 12): string => count($v) > $max ? implode(', ', array_slice($v, 0, $max)) . ' and ' . (count($v) - $max) . ' more' : implode(', ', $v);

    // ── who ──
    $who = []; $except = [];
    $incU = $u['includeUsers'] ?? []; $excU = $u['excludeUsers'] ?? [];
    $allUsers = in_array('All', $incU, true);
    if ($allUsers) $who[] = 'Everyone';
    if (in_array('GuestsOrExternalUsers', $incU, true) || !empty($u['includeGuestsOrExternalUsers'])) $who[] = 'Guests and external users';
    $pu = $list('users', array_values(array_diff($incU, ['All', 'None', 'GuestsOrExternalUsers'])));
    if ($pu) $who[] = (count($pu) === 1 ? 'Person: ' : 'People: ') . $join($pu);
    $pg = $list('groups', $u['includeGroups'] ?? []);
    if ($pg) $who[] = (count($pg) === 1 ? 'Group: ' : 'Groups: ') . $join($pg);
    $pr = $list('roles', $u['includeRoles'] ?? []);
    if ($pr) $who[] = 'Anyone holding ' . (count($pr) === 1 ? 'the admin role ' : 'one of these admin roles: ') . $join($pr);
    if (!$who) $who[] = 'Nobody (the rule names no one, so it has no effect)';
    if (in_array('GuestsOrExternalUsers', $excU, true) || !empty($u['excludeGuestsOrExternalUsers'])) $except[] = 'Guests and external users';
    $xu = $list('users', array_values(array_diff($excU, ['All', 'None', 'GuestsOrExternalUsers'])));
    if ($xu) $except[] = (count($xu) === 1 ? 'Person: ' : 'People: ') . $join($xu);
    $xg = $list('groups', $u['excludeGroups'] ?? []);
    if ($xg) $except[] = (count($xg) === 1 ? 'Group: ' : 'Groups: ') . $join($xg);
    $xr = $list('roles', $u['excludeRoles'] ?? []);
    if ($xr) $except[] = 'Admin roles: ' . $join($xr);

    // ── what they are doing ──
    $appKw = ['All' => 'All apps and services', 'Office365' => 'Office 365 (Outlook, Teams, SharePoint, OneDrive and the Office apps)', 'MicrosoftAdminPortals' => 'Microsoft admin portals', 'None' => 'No app'];
    $apps = []; $appsExcept = [];
    $incA = $a['includeApplications'] ?? [];
    foreach ($incA as $x) if (isset($appKw[$x])) $apps[] = $appKw[$x];
    $pa = $list('apps', array_values(array_diff($incA, array_keys($appKw))));
    if ($pa) $apps[] = (count($pa) === 1 ? 'App: ' : 'Apps: ') . $join($pa);
    $actKw = ['urn:user:registersecurityinfo' => 'Registering security information (setting up MFA)', 'urn:user:registerdevice' => 'Registering or joining a device'];
    foreach (($a['includeUserActions'] ?? []) as $x) $apps[] = $actKw[strtolower((string)$x)] ?? ('Action: ' . $x);
    if (!$apps) $apps[] = 'No app is named (the rule has no effect)';
    foreach (($a['excludeApplications'] ?? []) as $x) if (isset($appKw[$x])) $appsExcept[] = $appKw[$x];
    $xa = $list('apps', array_values(array_diff($a['excludeApplications'] ?? [], array_keys($appKw))));
    if ($xa) $appsExcept[] = (count($xa) === 1 ? 'App: ' : 'Apps: ') . $join($xa);

    // ── only when ──
    $cond = [];
    $ct = array_values(array_diff($c['clientAppTypes'] ?? [], ['all']));
    if ($ct) {
        $ck = ['browser' => 'a browser', 'mobileAppsAndDesktopClients' => 'mobile and desktop apps', 'exchangeActiveSync' => 'Exchange ActiveSync (older mail apps)', 'other' => 'other legacy clients (POP, IMAP, SMTP, old Office)'];
        $cond[] = 'Signing in with ' . $join(array_map(fn($x) => $ck[$x] ?? $x, $ct));
    }
    $pl = $c['platforms'] ?? null;
    if ($pl) {
        $pk = ['all' => 'any device platform', 'android' => 'Android', 'iOS' => 'iOS / iPadOS', 'windows' => 'Windows', 'windowsPhone' => 'Windows Phone', 'macOS' => 'macOS', 'linux' => 'Linux'];
        $inc = array_map(fn($x) => $pk[$x] ?? $x, $pl['includePlatforms'] ?? []); $exc = array_map(fn($x) => $pk[$x] ?? $x, $pl['excludePlatforms'] ?? []);
        if ($inc) $cond[] = 'On ' . $join($inc) . ($exc ? ', except ' . $join($exc) : '');
    }
    $lo = $c['locations'] ?? null;
    if ($lo) {
        $lk = ['All' => 'any location', 'AllTrusted' => 'all trusted locations'];
        $inc = []; foreach (($lo['includeLocations'] ?? []) as $x) $inc[] = $lk[$x] ?? $nm('locations', (string)$x);
        $exc = []; foreach (($lo['excludeLocations'] ?? []) as $x) $exc[] = $lk[$x] ?? $nm('locations', (string)$x);
        if ($inc) $cond[] = 'From ' . $join($inc) . ($exc ? ', except ' . $join($exc) : '');
    }
    $df = $c['devices']['deviceFilter'] ?? null;
    if ($df && !empty($df['rule'])) $cond[] = (strcasecmp((string)($df['mode'] ?? ''), 'exclude') === 0 ? 'Not on devices matching: ' : 'Only on devices matching: ') . mb_substr((string)$df['rule'], 0, 300);
    $rk = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'none' => 'no'];
    if (!empty($c['signInRiskLevels'])) $cond[] = 'When Microsoft rates the sign-in risk as ' . $join(array_map(fn($x) => $rk[$x] ?? $x, $c['signInRiskLevels']));
    if (!empty($c['userRiskLevels'])) $cond[] = 'When Microsoft rates the user risk as ' . $join(array_map(fn($x) => $rk[$x] ?? $x, $c['userRiskLevels']));
    if (!$cond) $cond[] = 'Always (no extra condition)';

    // ── then ──
    $g = $p['grantControls'] ?? null; $then = []; $blocks = false;
    if ($g) {
        $gk = ['block' => 'Block access', 'mfa' => 'Require multifactor authentication', 'compliantDevice' => 'Require a device marked compliant (Intune)',
               'domainJoinedDevice' => 'Require a hybrid-joined device', 'approvedApplication' => 'Require an approved client app',
               'compliantApplication' => 'Require an app protection policy', 'passwordChange' => 'Require a password change'];
        foreach (($g['builtInControls'] ?? []) as $x) { $then[] = $gk[$x] ?? ('Require: ' . $x); if ($x === 'block') $blocks = true; }
        if (!empty($g['authenticationStrength']['displayName'])) $then[] = 'Require authentication strength: ' . $g['authenticationStrength']['displayName'];
        if (!empty($g['termsOfUse'])) $then[] = 'Require accepting the terms of use';
        if (!empty($g['customAuthenticationFactors'])) $then[] = 'Require a custom control';
    }
    $op = ($g && count($then) > 1) ? (strcasecmp((string)($g['operator'] ?? 'OR'), 'AND') === 0 ? 'all of these' : 'one of these') : '';
    $s = $p['sessionControls'] ?? null; $sess = [];
    if ($s) {
        if (!empty($s['applicationEnforcedRestrictions']['isEnabled'])) $sess[] = 'Limited access enforced by the app (browser only, no download on unmanaged devices)';
        $sf = $s['signInFrequency'] ?? null;
        if ($sf && !empty($sf['isEnabled'])) $sess[] = strcasecmp((string)($sf['frequencyInterval'] ?? ''), 'everyTime') === 0 ? 'Sign in again every time'
            : 'Sign in again every ' . (int)($sf['value'] ?? 0) . ' ' . (strcasecmp((string)($sf['type'] ?? ''), 'days') === 0 ? 'day' : 'hour') . ((int)($sf['value'] ?? 0) === 1 ? '' : 's');
        $pb = $s['persistentBrowser'] ?? null;
        if ($pb && !empty($pb['isEnabled'])) $sess[] = strcasecmp((string)($pb['mode'] ?? ''), 'never') === 0 ? 'Never keep the browser signed in' : 'Keep the browser signed in';
        if (!empty($s['cloudAppSecurity']['isEnabled'])) $sess[] = 'Session watched by Defender for Cloud Apps';
        if (!empty($s['disableResilienceDefaults'])) $sess[] = 'Resilience defaults switched off';
    }
    if (!$then && !$sess) $then[] = 'Nothing (the rule sets no requirement)';

    // ── warnings ──
    $state = (string)($p['state'] ?? '');
    $warn = [];
    if ($state === 'enabledForReportingButNotEnforced') $warn[] = ['info', 'Report-only: Microsoft records what the rule would have done, but nothing is enforced yet.'];
    if ($state === 'disabled') $warn[] = ['info', 'Off: the rule does nothing.'];
    $noExcept = !$except;
    $allApps = in_array('All', $incA, true);
    if ($allUsers && $noExcept && ($then && $then[0] !== 'Nothing (the rule sets no requirement)') && $state !== 'disabled')
        $warn[] = [$blocks ? 'high' : 'warn', 'No account is excluded. Keep at least one emergency (break-glass) account out of every rule that applies to everyone, or a mistake or an outage of the MFA service can lock all administrators out.'];
    if ($allUsers && $allApps && $blocks && $cond === ['Always (no extra condition)'] && $state === 'enabled')
        $warn[] = ['high', 'This rule blocks everyone from everything, at all times.'];
    if ($unknown > 0) $warn[] = ['warn', $unknown . ' ' . ($unknown === 1 ? 'entry refers' : 'entries refer') . ' to something that no longer exists (or that ' . (function_exists('opsh_app_name') ? opsh_app_name() : 'the app') . ' may not read). Tidy the rule in the Entra admin center.'];

    return [
        'id' => (string)($p['id'] ?? ''), 'name' => (string)($p['displayName'] ?? ''), 'state' => $state,
        'created' => $p['createdDateTime'] ?? null, 'modified' => $p['modifiedDateTime'] ?? null,
        'who' => $who, 'except' => $except, 'apps' => $apps, 'apps_except' => $appsExcept, 'when' => $cond,
        'then' => $then, 'then_operator' => $op, 'session' => $sess,
        'warnings' => array_map(fn($w) => ['level' => $w[0], 'text' => $w[1]], $warn),
    ];
}
