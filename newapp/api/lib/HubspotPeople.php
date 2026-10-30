<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT133 — a person's HubSpot user, on their page under People (like Microsoft 365 and vTiger).
 *
 *   Find    GET    /settings/v3/users/{email}?idProperty=EMAIL      (settings.users.read)
 *   Roles   GET    /settings/v3/users/roles                         (settings.users.read)
 *   Teams   GET    /settings/v3/users/teams                         (settings.users.teams.read; optional)
 *   Change  PUT    /settings/v3/users/{id}?idProperty=USER_ID       (settings.users.write) firstName, lastName, roleId,
 *                                                                    primaryTeamId, secondaryTeamIds
 *   Remove  DELETE /settings/v3/users/{id}?idProperty=USER_ID       (settings.users.write) 204 = removed
 *
 * Adding a user to HubSpot stays manual (not everybody has HubSpot). When a person is found, the account is recorded
 * on their Company systems at once (a fresh reading of HubSpot), so it is identified without waiting for the daily read.
 * A Super Admin of HubSpot is never removed from here. A role that holds a paid seat needs HubSpot's billing-write
 * scope; HubSpot refuses it otherwise and the answer is shown as it is.
 */
require_once __DIR__ . '/SaasUsers.php';

if (!function_exists('opsh_hsp_client')) {
    function opsh_hsp_client(PDO $db): OpshSaasUsers {
        require_once __DIR__ . '/SystemConnect.php';
        $sys = function_exists('opsh_sys_get') ? opsh_sys_get($db, 'hubspot') : null;
        if (!$sys || $sys['mode'] !== 'connected') throw new OpshSaasException('HubSpot is not connected. Company systems → HubSpot → Set up… → Connection.', 409);
        $pub = opsh_conn_public($db, 'hubspot');
        if (!$pub || !$pub['configured']) throw new OpshSaasException('No HubSpot key is saved yet (Company systems → HubSpot → Set up… → Connection).', 409);
        return new OpshSaasUsers('hubspot', opsh_conn_creds($db, 'hubspot'));
    }

    /** HubSpot's answer to a change or a removal, in words. */
    function opsh_hsp_err(OpshSaasUsers $c, int $st, array $d, string $doing): string {
        $m = $d['message'] ?? ''; $m = is_string($m) ? trim(mb_substr($m, 0, 240)) : '';
        if ($st === 403 || $st === 401) return 'HubSpot refused to ' . $doing . ' (HTTP ' . $st . '). The key needs the scope settings.users.write'
            . (stripos($m, 'billing') !== false ? ', and a role with a paid seat also needs billing-write' : '') . '.' . ($m !== '' ? ' HubSpot said: ' . $m : '');
        if ($st === 404) return 'HubSpot no longer has this user. Reload the page.';
        return $c->errText($st, $d);
    }

    /** A HubSpot user in the shape the page uses. */
    function opsh_hsp_shape(array $r, array $roles = [], array $teams = []): array {
        $s = fn($v) => is_string($v) || is_int($v) ? trim((string)$v) : '';
        $list = fn($v) => array_values(array_filter(array_map($s, is_array($v) ? $v : []), fn($x) => $x !== ''));
        $roleId = $s($r['roleId'] ?? ''); $prim = $s($r['primaryTeamId'] ?? '');
        return ['id' => $s($r['id'] ?? ''), 'email' => mb_strtolower($s($r['email'] ?? '')), 'first_name' => $s($r['firstName'] ?? ''), 'last_name' => $s($r['lastName'] ?? ''),
                'super_admin' => !empty($r['superAdmin']), 'role_id' => $roleId, 'role' => $roleId !== '' ? ($roles[$roleId] ?? ('role ' . $roleId)) : '',
                'role_ids' => $list($r['roleIds'] ?? []), 'primary_team_id' => $prim, 'primary_team' => $prim !== '' ? ($teams[$prim] ?? ('team ' . $prim)) : '',
                'secondary_team_ids' => $list($r['secondaryTeamIds'] ?? []), 'secondary_teams' => array_map(fn($t) => $teams[$t] ?? ('team ' . $t), $list($r['secondaryTeamIds'] ?? [])),
                'seats' => $list($r['seatNames'] ?? [])];
    }

    /** [roles id=>name, paid role ids, teams id=>name, teams readable?] */
    function opsh_hsp_lists(OpshSaasUsers $c): array {
        $roles = []; $paid = []; $teams = []; $teamsOk = false;
        [$st, $d] = $c->call('GET', '/settings/v3/users/roles');
        if ($st >= 200 && $st < 300) foreach ((array)($d['results'] ?? []) as $r) if (is_array($r) && isset($r['id'], $r['name'])) {
            $roles[(string)$r['id']] = mb_substr((string)$r['name'], 0, 120); if (!empty($r['requiresBillingWrite'])) $paid[] = (string)$r['id']; }
        [$st, $d] = $c->call('GET', '/settings/v3/users/teams');
        if ($st >= 200 && $st < 300) { $teamsOk = true; foreach ((array)($d['results'] ?? []) as $t) if (is_array($t) && isset($t['id'], $t['name'])) $teams[(string)$t['id']] = mb_substr((string)$t['name'], 0, 120); }
        return [$roles, $paid, $teams, $teamsOk];
    }

    /** Look a person up by each email given. Returns the raw HubSpot user or null (not found). Throws when HubSpot cannot be asked. */
    function opsh_hsp_find(OpshSaasUsers $c, array $emails): ?array {
        foreach ($emails as $e) {
            [$st, $d] = $c->call('GET', '/settings/v3/users/' . rawurlencode($e), ['idProperty' => 'EMAIL']);
            if ($st >= 200 && $st < 300 && isset($d['id'])) return $d;
            if ($st !== 404) throw new OpshSaasException($c->errText($st, $d), $st);
        }
        return null;
    }

    function opsh_hsp_get(OpshSaasUsers $c, string $id): ?array {
        [$st, $d] = $c->call('GET', '/settings/v3/users/' . rawurlencode($id), ['idProperty' => 'USER_ID']);
        if ($st === 404) return null;
        if ($st < 200 || $st >= 300 || !isset($d['id'])) throw new OpshSaasException($c->errText($st, $d), $st);
        return $d;
    }

    /** What the Audit Log shows of a user. */
    function opsh_hsp_audit_view(array $u): array {
        return ['Name' => trim($u['first_name'] . ' ' . $u['last_name']), 'Role' => $u['super_admin'] ? 'Super Admin' : ($u['role'] ?: '(none)'),
                'Primary team' => $u['primary_team'] ?: '(none)', 'Other teams' => $u['secondary_teams'] ? implode(', ', $u['secondary_teams']) : '(none)'];
    }

    /**
     * The change asked for, checked. $in = first_name, last_name, role_id, primary_team_id, secondary_team_ids (only the
     * fields that change). Returns HubSpot's body. Throws InvalidArgumentException with the reason.
     */
    function opsh_hsp_clean_change(array $in, array $now, array $roles, array $teams, bool $teamsOk): array {
        $out = [];
        foreach (['first_name' => 'firstName', 'last_name' => 'lastName'] as $k => $hk) {
            if (!array_key_exists($k, $in)) continue;
            if (!is_string($in[$k])) throw new InvalidArgumentException('The ' . str_replace('_', ' ', $k) . ' is not text. Nothing was changed.');
            $v = trim(preg_replace('/\s+/u', ' ', $in[$k]));
            if ($v === '' || mb_strlen($v) > 80 || preg_match('/[\x00-\x1F\x7F<>]/u', $v)) throw new InvalidArgumentException('Enter a ' . str_replace('_', ' ', $k) . ' of 1 to 80 characters, without < or >. Nothing was changed.');
            if ($v !== $now[$k]) $out[$hk] = $v;
        }
        if (array_key_exists('role_id', $in)) {
            $v = is_string($in['role_id']) ? trim($in['role_id']) : '';
            if ($now['super_admin']) throw new InvalidArgumentException('This user is a Super Admin in HubSpot; their role is changed in HubSpot itself. Nothing was changed.');
            if (!isset($roles[$v])) throw new InvalidArgumentException('Choose one of the roles HubSpot lists. Nothing was changed.');
            if ($v !== $now['role_id']) $out['roleId'] = $v;
        }
        $teamKeys = array_intersect(['primary_team_id', 'secondary_team_ids'], array_keys($in));
        if ($teamKeys && !$teamsOk) throw new InvalidArgumentException('The teams cannot be read with this key (it needs settings.users.teams.read), so they cannot be changed here. Nothing was changed.');
        if (array_key_exists('primary_team_id', $in)) {
            $v = is_string($in['primary_team_id']) ? trim($in['primary_team_id']) : '';
            if ($v === '' && $now['primary_team_id'] !== '') throw new InvalidArgumentException('HubSpot keeps a primary team once one is set; choose another team instead. Nothing was changed.');
            if ($v !== '' && !isset($teams[$v])) throw new InvalidArgumentException('Choose one of the teams HubSpot lists. Nothing was changed.');
            if ($v !== $now['primary_team_id']) $out['primaryTeamId'] = $v;
        }
        if (array_key_exists('secondary_team_ids', $in)) {
            if (!is_array($in['secondary_team_ids']) || count($in['secondary_team_ids']) > 50) throw new InvalidArgumentException('The other teams are not a list. Nothing was changed.');
            $v = [];
            foreach ($in['secondary_team_ids'] as $t) { $t = is_string($t) ? trim($t) : ''; if (!isset($teams[$t])) throw new InvalidArgumentException('Choose other teams from the teams HubSpot lists. Nothing was changed.'); $v[$t] = 1; }
            $prim = $out['primaryTeamId'] ?? $now['primary_team_id'];
            unset($v[$prim]); $v = array_keys($v); $a = $v; $b = $now['secondary_team_ids']; sort($a); sort($b);
            if ($a !== $b) $out['secondaryTeamIds'] = array_map('strval', $v);
        }
        return $out;
    }
}
