<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT21 — vTiger helpers for the Microsoft 365 area (provisioning endpoints).
 *
 * vTiger is read through a read-only DB connection and written through the bridge. ITOps has
 * both too: the optional read-only DB ('vtiger_db') and the provisioning bridge (itops-bridge.php,
 * which can also look users up). Reads use the DB when it is configured, otherwise the bridge, so
 * linkage works with either. Results keep one shape so the screens stay the same.
 */
require_once __DIR__ . '/VTigerProvision.php';
require_once __DIR__ . '/Offboarding.php';

if (!function_exists('itops_hub_vtiger_available')) {

    function itops_hub_vt_cfg(): array { static $c = null; if ($c === null) $c = itops_vtiger_cfg(getDB()); return $c; }
    function itops_hub_vt_db(): ?ItopsVTigerDb {
        $c = itops_hub_vt_cfg();
        if (empty($c['db']) || empty($c['db']['db']) || empty($c['db']['user'])) return null;
        try { return new ItopsVTigerDb($c['db']); } catch (Throwable $e) { return null; }
    }
    function itops_hub_bridge_ready(): bool { return itops_vtiger_bridge_ready(itops_hub_vt_cfg()['prov']); }

    /** vTiger linkage is possible when either the read-only DB or the bridge is configured. */
    function itops_hub_vtiger_available(): bool {
        $c = itops_hub_vt_cfg();
        return (!empty($c['db']['db']) && !empty($c['db']['user'])) || itops_hub_bridge_ready();
    }

    /** Every non-deleted vTiger user: [{id,user_name,first_name,last_name,email,status,is_admin,roleid}]. */
    function itops_hub_vtiger_all_users(): array {
        static $cache = null; if ($cache !== null) return $cache;
        $db = itops_hub_vt_db();
        if ($db) { try { return $cache = $db->allUsers(); } catch (Throwable $e) { /* fall back to the bridge */ } }
        if (itops_hub_bridge_ready()) {
            $r = itops_vt_call(getDB(), 'list_users', ['status' => 'all']);
            if (!empty($r['ok'])) return $cache = array_map(fn($u) => [
                'id' => (string)$u['id'], 'user_name' => (string)$u['user_name'], 'first_name' => (string)($u['first_name'] ?? ''),
                'last_name' => (string)($u['last_name'] ?? ''), 'email' => (string)($u['email'] ?? ''), 'status' => (string)($u['status'] ?? ''),
                'is_admin' => !empty($u['is_admin']), 'roleid' => (string)($u['roleid'] ?? '')], $r['users'] ?? []);
            throw new RuntimeException($r['error'] ?? 'vTiger bridge error');
        }
        throw new RuntimeException('vTiger is not connected (Integrations → vTiger provisioning).');
    }

    /**
     * BT36 — the vTiger user of a Microsoft 365 person, or null. $emails = their mail and sign-in name; $signIn = the
     * sign-in name (UPN), whose first part is tried as vTiger user name when no email matches. See itops_vt_match_user().
     */
    function itops_hub_vtiger_match(array $emails, string $signIn = ''): ?array {
        return itops_vt_match_user(itops_hub_vtiger_all_users(), $emails, $signIn);
    }

    /** email => {username,status,is_admin} (Active first, then admin, then lowest id — the matching rule). Email matches only. */
    function itops_hub_vtiger_by_emails(array $emails): array {
        $map = [];
        foreach (array_filter(array_map(fn($e) => strtolower(trim((string)$e)), $emails)) as $e) {
            if (isset($map[$e])) continue;
            $u = itops_hub_vtiger_match([$e], '#');   // '#' = never fall back to the user name here
            if ($u) $map[$e] = ['username' => $u['user_name'], 'status' => $u['status'], 'is_admin' => (bool)$u['is_admin']];
        }
        return $map;
    }

    /** One vTiger user by email, else by user name (the part before the "@") — same preference order — or null.
     *  For showing and checking only. Anything that changes vTiger goes through itops_hub_vtiger_resolve(). */
    function itops_hub_vtiger_find_by_email(string $email): ?array {
        $email = strtolower(trim($email)); if ($email === '') return null;
        return itops_hub_vtiger_match([$email], $email);
    }

    /** Active vTiger users sorted by user_name — the reassignment target list. */
    function itops_hub_vtiger_active_users(): array {
        $a = array_values(array_filter(itops_hub_vtiger_all_users(), fn($u) => strcasecmp((string)$u['status'], 'Active') === 0));
        usort($a, fn($x, $y) => strcasecmp($x['user_name'], $y['user_name']));
        return array_map(fn($u) => ['id' => $u['id'], 'user_name' => $u['user_name'], 'email' => $u['email'], 'status' => $u['status'], 'is_admin' => $u['is_admin']], $a);
    }

    /** vTiger roles [{id,name}] — BT24: the bridge first (the same signed connection that creates users), then the
     *  optional read-only DB. The bridge answers from vTiger itself, so no database credentials are needed and an
     *  unreachable DB host can't slow the pickers down. */
    function itops_hub_vtiger_roles(?string &$via = null): array {
        $via = ''; $err = null;
        if (itops_hub_bridge_ready()) {
            $r = itops_vt_call(getDB(), 'list_roles');
            if (!empty($r['ok'])) { $via = 'bridge'; return array_map(fn($x) => ['id' => (string)$x['roleid'], 'name' => (string)$x['rolename']], $r['roles'] ?? []); }
            $err = (string)($r['error'] ?? 'vTiger bridge error');
        }
        $db = itops_hub_vt_db();
        if ($db) { $r = $db->roles(); if ($r) { $via = 'db'; return array_map(fn($x) => ['id' => (string)$x['roleid'], 'name' => (string)$x['rolename']], $r); } }
        if ($err !== null) throw new RuntimeException($err);
        return [];
    }

    /** Records owned by a vTiger user: ['from_id', 'modules' => [{module,count}] sorted by count desc]. */
    function itops_hub_vtiger_preview(string $fromId): array {
        $r = itops_vt_call(getDB(), 'reassign_preview', ['from' => $fromId]);
        if (empty($r['ok'])) throw new RuntimeException((string)($r['error'] ?? 'preview failed.'));
        $mods = [];
        foreach ((array)($r['modules'] ?? []) as $m => $c) $mods[] = ['module' => (string)$m, 'count' => (int)$c];
        usort($mods, fn($a, $b) => $b['count'] <=> $a['count']);
        return ['from_id' => $fromId, 'modules' => $mods];
    }

    /** Move ownership. $modules [] = all. Returns ['reassigned' => {module|"(all)": n}, 'total' => n]. */
    function itops_hub_vtiger_reassign(string $fromId, string $toId, array $modules): array {
        $r = itops_vt_call(getDB(), 'reassign', ['from' => $fromId, 'to' => $toId, 'modules' => $modules]);
        if (empty($r['ok'])) throw new RuntimeException((string)($r['error'] ?? 'reassign failed.'));
        $moved = (array)($r['moved'] ?? []);
        return ['reassigned' => $modules ? $moved : ($moved ?: ['(all)' => (int)($r['moved_total'] ?? 0)]), 'total' => (int)($r['moved_total'] ?? array_sum($moved))];
    }

    /** Resolve a vTiger id from an id or an email ("No vTiger user matches that email."). */
    function itops_hub_vtiger_resolve(string $id, string $email): string {
        if ($id !== '') return $id;
        if ($email === '') throw new InvalidArgumentException('An email or vTiger id is required.');
        // BT40: by email only. A user found by user name is a guess (a person on another domain can have the same
        // user name): the screens show it and send its id once a person has looked; the server never acts on it by itself.
        $u = itops_hub_vtiger_match([$email], '#');
        if ($u) return (string)$u['id'];
        $guess = itops_hub_vtiger_find_by_email($email);
        if ($guess) throw new DomainException('No vTiger user matches that email. A vTiger user named "' . $guess['user_name'] . '" exists with another email (' . (($guess['email'] ?? '') !== '' ? $guess['email'] : 'none')
            . '). If it is the same person, reload the page and use the vTiger user shown there.');
        throw new DomainException('No vTiger user matches that email.');
    }
}
