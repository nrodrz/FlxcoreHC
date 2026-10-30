<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Offboarding helpers: vTiger ownership / deactivation through the bridge, and
 * the "Verify offboarding" completion check (also used by the monthly access recertification).
 *
 * Verification re-reads BOTH systems — it does not trust what the run recorded — so it
 * catches a half-finished departure however it happened (a step that failed, a licence
 * re-added by hand, an account re-enabled). Every verification is written to the audit log.
 *
 * Checks (status pass | fail | skip):
 *   Microsoft 365  · Sign-in blocked      accountEnabled = false (a deleted account also passes)
 *                  · Licenses removed     no assigned licences
 *   vTiger         · User deactivated     status = Inactive
 *                  · Records reassigned   0 live records still owned
 *   ITOps         · Equipment returned   no open equipment loans for the person
 * A system that isn't configured, or where the person never had an account, is "skip".
 * clean = at least one pass and no fail.
 */
require_once __DIR__ . '/VTigerProvision.php';

if (!function_exists('itops_vt_call')) {

    /** Bridge call with a friendly message when the server still runs an older bridge build. */
    function itops_vt_call(PDO $db, string $action, array $payload = []): array {
        $prov = itops_vtiger_cfg($db)['prov'];
        if (!itops_vtiger_bridge_ready($prov)) return ['ok' => false, 'error' => 'The vTiger bridge is not configured (Integrations → vTiger provisioning).', 'not_configured' => true];
        $r = itops_vtiger_bridge_call($prov, $action, $payload);
        if (empty($r['ok']) && ($r['error'] ?? '') === 'Unknown action.') {
            $r['error'] = 'The vTiger bridge on the server is an older build (' . ($r['build'] ?? '?') . '). Upload the new vtiger-bridge/itops-bridge.php (build o5) to the vTiger web root — same secret, nothing else to change.';
            $r['outdated'] = true;
        }
        return $r;
    }

    /** "Jane.Doe@example.org" → "jane.doe". '' when there is no "@" or the address is a guest sign-in name (…#EXT#@…). */
    function itops_vt_local_part(string $addr): string {
        $a = strtolower(trim($addr)); $p = strrpos($a, '@');
        if ($p === false || $p === 0 || strpos($a, '#') !== false) return '';
        return substr($a, 0, $p);
    }

    /**
     * BT36 — pick the vTiger user of a person out of $users (rows of list_users / allUsers()).
     *   1. email     vTiger's email field equals one of $emails (the person's mail and sign-in name);
     *   2. username  no email matched: vTiger's user name equals the part of $signIn before the "@" (vTiger installs
     *                usually name the user "jdoe", not "jdoe@company.org"), or the whole $signIn.
     * Among several candidates: Active first, then admin, then lowest id — the rule used everywhere else.
     * Returns the row + ['matched_by' => 'email' | 'username', 'matches' => n] or null. Pure: no I/O.
     */
    function itops_vt_match_user(array $users, array $emails, string $signIn = ''): ?array {
        $want = [];
        foreach ($emails as $e) { $e = strtolower(trim((string)$e)); if ($e !== '') $want[$e] = true; }
        if ($signIn === '') $signIn = (string)(array_key_first($want) ?? '');
        $rank = fn($u) => [strcasecmp((string)($u['status'] ?? ''), 'Active') !== 0, empty($u['is_admin']), (int)($u['id'] ?? 0)];
        $pick = function (array $c, string $by) use ($rank): ?array {
            if (!$c) return null;
            usort($c, fn($a, $b) => $rank($a) <=> $rank($b));
            return $c[0] + ['matched_by' => $by, 'matches' => count($c)];
        };
        $hit = $pick(array_values(array_filter($users, fn($u) => ($e = strtolower(trim((string)($u['email'] ?? '')))) !== '' && isset($want[$e]))), 'email');
        if ($hit) return $hit;
        $names = array_filter([itops_vt_local_part($signIn), strpos($signIn, '#') === false ? strtolower(trim($signIn)) : '']);
        if (!$names) return null;
        return $pick(array_values(array_filter($users, fn($u) => in_array(strtolower(trim((string)($u['user_name'] ?? ''))), $names, true))), 'username');
    }

    /** "matched by user name" note for results and previews ('' when the email matched). */
    function itops_vt_match_note(?array $u): string {
        $by = (string)($u['matched_by'] ?? '');
        if (!$u || ($by !== 'username' && $by !== 'id')) return '';
        return ' (' . ($by === 'username' ? 'matched by vTiger user name "' . ($u['user_name'] ?? '') . '"' : 'linked by the id kept on this record') . '; the email on the vTiger record is ' . (($u['email'] ?? '') !== '' ? $u['email'] : 'empty')
             . (array_key_exists('confirmed', $u) ? (!empty($u['confirmed']) ? '; confirmed as the same person' : '; not confirmed as the same person') : '') . ')';
    }

    /**
     * BT40 — did a person confirm, for this run, that vTiger user $vtId is the person who is leaving?
     * The confirmation is the user's id stored on a vTiger step of the run (config.vtiger_id): the Offboard wizard
     * stores it for the user it showed when the steps were chosen, and a step run with "it is the same person" stores it.
     */
    function itops_vt_run_confirmed(PDO $db, int $runId, string $vtId): bool {
        if ($vtId === '') return false;
        try {
            $q = $db->prepare("SELECT config FROM itops_ob_run_tasks WHERE run_id=? AND task_type IN ('vtiger_reassign','vtiger_deactivate')"); $q->execute([$runId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) { $c = json_decode((string)$c, true); if (is_array($c) && (string)($c['vtiger_id'] ?? '') === $vtId) return true; }
        } catch (Throwable $e) {}
        return false;
    }

    /** Record that confirmation on every vTiger step of the run, and remember the id on the run. */
    function itops_vt_run_confirm(PDO $db, int $runId, string $vtId): void {
        $q = $db->prepare("SELECT id, config FROM itops_ob_run_tasks WHERE run_id=? AND task_type IN ('vtiger_reassign','vtiger_deactivate')"); $q->execute([$runId]);
        $u = $db->prepare('UPDATE itops_ob_run_tasks SET config=? WHERE id=?');
        foreach ($q->fetchAll() as $t) { $c = json_decode((string)$t['config'], true); $c = is_array($c) ? $c : []; $c['vtiger_id'] = $vtId; $u->execute([json_encode($c), (int)$t['id']]); }
        $db->prepare('UPDATE itops_ob_runs SET vtiger_id=? WHERE id=?')->execute([$vtId, $runId]);
    }

    /**
     * The vTiger user for a run: by the id remembered on the run, else by the person's email, else by user name
     * (BT36: the part of the sign-in name before the "@").
     *
     * BT40 — the user always carries how it was found and whether the server may act on it by itself:
     *   matched_by  'email'     its email is the person's mail or sign-in name
     *               'username'  only its user name fits (a namesake on another domain fits too)
     *               'id'        remembered on the run, but neither its email nor its user name is the person's
     *   confirmed   true for 'email'; otherwise only when a person confirmed this very user for this run
     *               (itops_vt_run_confirmed). Nothing that changes vTiger may run while this is false.
     * A user found by user name is no longer remembered on the run by the server itself: only an email match is.
     */
    function itops_vt_run_user(PDO $db, array $run): array {
        $email = trim((string)($run['subject_email'] ?? '')); $upn = trim((string)($run['subject_upn'] ?? ''));
        $mine = array_values(array_unique(array_filter([strtolower($email), strtolower($upn)])));
        $how = function (array $u) use ($mine, $upn, $email): string {
            $e = strtolower(trim((string)($u['email'] ?? '')));
            if ($e !== '' && in_array($e, $mine, true)) return 'email';
            $sign = $upn !== '' ? $upn : $email; $n = strtolower(trim((string)($u['user_name'] ?? '')));
            return $n !== '' && in_array($n, array_filter([itops_vt_local_part($sign), strpos($sign, '#') === false ? strtolower($sign) : '']), true) ? 'username' : 'id';
        };
        $done = function (array $user) use ($db, $run, $how): array {
            $user['matched_by'] = $how($user);
            $user['confirmed'] = $user['matched_by'] === 'email' || itops_vt_run_confirmed($db, (int)($run['id'] ?? 0), (string)$user['id']);
            return ['ok' => true, 'user' => $user, 'matches' => (int)($user['matches'] ?? 1)];
        };
        if (!empty($run['vtiger_id'])) {
            $r = itops_vt_call($db, 'find_user', ['id' => (string)$run['vtiger_id']]);
            if (!empty($r['ok']) && !empty($r['user'])) return $done($r['user']);
            if (empty($r['ok'])) return $r;
        }
        if ($email === '' && $upn === '') return ['ok' => false, 'error' => 'This process has no email address to find the vTiger user.'];
        $user = null;
        foreach (array_unique(array_filter([$email, $upn])) as $e) {
            $r = itops_vt_call($db, 'find_user', ['email' => $e]);
            if (empty($r['ok'])) return $r;
            if (!empty($r['user'])) { $user = $r['user'] + ['matches' => (int)($r['matches'] ?? 1)]; break; }
        }
        if (!$user) {   // no email match: try the user name
            $l = itops_vt_call($db, 'list_users', ['status' => 'all']);
            if (empty($l['ok'])) return $l;
            $user = itops_vt_match_user((array)($l['users'] ?? []), [], $upn !== '' ? $upn : $email);
        }
        if (!$user) return ['ok' => true, 'user' => null];
        $out = $done($user);
        if ($out['user']['matched_by'] === 'email') { try { $db->prepare('UPDATE itops_ob_runs SET vtiger_id=? WHERE id=?')->execute([(string)$user['id'], (int)$run['id']]); } catch (Throwable $e) {} }
        return $out;
    }

    /** One line that says who the user is, for a confirmation question or a refusal. */
    function itops_vt_user_brief(array $u): array {
        $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        return ['id' => (string)($u['id'] ?? ''), 'user_name' => (string)($u['user_name'] ?? ''), 'name' => $n, 'email' => (string)($u['email'] ?? ''),
                'status' => (string)($u['status'] ?? ''), 'matched_by' => (string)($u['matched_by'] ?? '')];
    }

    /**
     * BT40 — the guard in front of every step that changes vTiger. Returns the user to act on, or null when the person
     * has no vTiger user. Throws ItopsObError (409, needs_vtiger_confirm) when the user was not found by email and nobody
     * confirmed it. $sentId = the id a person confirmed with this very click ('' when none): it must be the user found,
     * and is then recorded on the run and in the Audit Log.
     */
    function itops_vt_run_user_to_change(PDO $db, array $run, string $sentId, string $what): ?array {
        $vu = itops_vt_run_user($db, $run);
        if (empty($vu['ok'])) throw new \RuntimeException($vu['error'] ?? 'Could not reach the vTiger bridge');
        if (empty($vu['user'])) return null;
        $u = $vu['user']; $rid = (int)($run['id'] ?? 0); $who = (string)($run['subject_name'] ?? '') ?: (string)($run['subject_email'] ?? '');
        // An id sent with the click is "the user I am looking at": when it is not the user found, stop — also for a user
        // found by email — so a step never runs on somebody the person did not see.
        if (!empty($u['confirmed']) && ($sentId === '' || $sentId === (string)$u['id'])) return $u;
        $brief = itops_vt_user_brief($u);
        $desc = '"' . $brief['user_name'] . '" (' . implode('; ', array_filter([$brief['name'], 'email on the vTiger record: ' . ($brief['email'] !== '' ? $brief['email'] : 'none'), $brief['status'] ?: 'status unknown'])) . ')';
        $foundBy = $brief['matched_by'] === 'username' ? 'user name only' : 'the id kept on this record';
        // Audit Log: who (the signed-in person), when, which departure, which vTiger user and why. Never throws.
        $log = function (string $action, string $name, array $more = []) use ($rid, $who, $brief, $foundBy, $what): void {
            if (!function_exists('audit_log')) return;
            try { audit_log($action, 'onboarding', $rid ?: null, mb_substr($name, 0, 250), null, ['Person' => $who, 'Step' => $what, 'vTiger user' => $brief['user_name'], 'vTiger id' => $brief['id'],
                'Name on the vTiger record' => $brief['name'], 'Email on the vTiger record' => $brief['email'], 'vTiger status' => $brief['status'], 'Found by' => $foundBy] + $more); } catch (Throwable $e) {}
        };
        if ($sentId !== '') {
            if ($sentId !== $brief['id']) {
                $log('offboarding_vtiger_refused', $who . ' - ' . $what . ' not run: the confirmed vTiger user is not the one found now', ['Confirmed id' => mb_substr($sentId, 0, 32), 'Result' => 'Nothing was changed']);
                throw new ItopsObError('The vTiger user for this person is not the one you confirmed. Reload the page and look again. Nothing was changed.', 409, ['needs_vtiger_confirm' => true, 'vtiger_user' => $brief]);
            }
            itops_vt_run_confirm($db, $rid, $brief['id']);
            $log('offboarding_vtiger_confirmed', $who . ' - vTiger user "' . $brief['user_name'] . '" confirmed as the same person', ['Result' => 'Confirmed; the step runs on this user']);
            $u['confirmed'] = true;
            return $u;
        }
        $log('offboarding_vtiger_refused', $who . ' - ' . $what . ' not run: vTiger user "' . $brief['user_name'] . '" not confirmed as the same person', ['Result' => 'Nothing was changed']);
        throw new ItopsObError('Not run. No vTiger user has this person\'s email. The vTiger user ' . $desc . ' was found by '
            . ($brief['matched_by'] === 'username' ? 'user name only, and a person on another domain can have the same user name' : 'an id kept on this record')
            . '. Confirm it is the same person to continue, or skip this step. Nothing was changed.', 409, ['needs_vtiger_confirm' => true, 'vtiger_user' => $brief]);
    }

    function itops_vt_user_label(?array $u): string {
        if (!$u) return '';
        $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        return ($n !== '' ? $n : ($u['user_name'] ?? '')) . (!empty($u['email']) ? ' <' . $u['email'] . '>' : '') . itops_vt_match_note($u);
    }

    /** "Contacts 40, Potentials 3" */
    function itops_vt_mods_text(array $mods): string {
        $p = []; foreach ($mods as $m => $c) $p[] = $m . ' ' . (int)$c;
        return $p ? implode(', ', $p) : 'none';
    }

    /** Microsoft 365 user id / UPN for a run. */
    function itops_ob_m365_key(array $run): string {
        return (string)(($run['m365_id'] ?? '') ?: (($run['subject_upn'] ?? '') ?: ($run['subject_email'] ?? '')));
    }

    /**
     * Verify an offboarding run. Returns ['clean'=>bool, 'checks'=>[...], 'issues'=>[...], 'at'=>datetime].
     * Stores the result on the run and writes an audit entry unless $record = false.
     */
    function itops_ob_verify(PDO $db, array $run, bool $record = true, string $source = 'manual'): array {
        $checks = [];
        $add = function ($system, $label, $status, $detail) use (&$checks) { $checks[] = ['system' => $system, 'label' => $label, 'status' => $status, 'detail' => $detail]; };

        // ── Microsoft 365 ──
        $key = itops_ob_m365_key($run);
        if (!is_file(__DIR__ . '/Graph.php')) { $add('Microsoft 365', 'Account', 'skip', 'Microsoft 365 module not installed'); }
        else {
            require_once __DIR__ . '/Graph.php';
            if (!function_exists('itops_graph_configured') || !itops_graph_configured()) {
                $add('Microsoft 365', 'Sign-in blocked', 'skip', 'Microsoft 365 is not connected');
                $add('Microsoft 365', 'Licenses removed', 'skip', 'Microsoft 365 is not connected');
            } elseif ($key === '') {
                $add('Microsoft 365', 'Sign-in blocked', 'skip', 'No email / user principal name on this process');
            } else {
                try {
                    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($key) . '?$select=id,userPrincipalName,accountEnabled,assignedLicenses';
                    [$code, $u] = itops_graph_http('GET', $url);
                    // BT124 (review SEC-5): "not found" proves a deletion only for the account id kept on the process. By an
                    // address, look the address up as a mail address first: it may not be the sign-in name.
                    if ($code === 404 && empty($run['m365_id']) && strpos($key, '@') !== false) {
                        $f = rawurlencode("mail eq '" . str_replace("'", "''", $key) . "'");
                        [$c2, $l2] = itops_graph_http('GET', 'https://graph.microsoft.com/v1.0/users?$filter=' . $f . '&$select=id,userPrincipalName,accountEnabled,assignedLicenses&$top=2');
                        if ($c2 === 200 && count($l2['value'] ?? []) === 1) { $code = 200; $u = $l2['value'][0]; }
                    }
                    if ($code === 404 && empty($run['m365_id'])) {
                        $add('Microsoft 365', 'Sign-in blocked', 'fail', 'No account was found as ' . $key . ' (neither as sign-in name nor as mail address). Check the address on this process: it may be wrong, so this cannot be confirmed.');
                    } elseif ($code === 404) {
                        $add('Microsoft 365', 'Sign-in blocked', 'pass', 'The account ' . $key . ' no longer exists (deleted) — no sign-in possible');
                        $add('Microsoft 365', 'Licenses removed', 'pass', 'Account deleted — no licenses');
                    } elseif ($code >= 400 || !is_array($u)) {
                        $msg = is_array($u) ? ($u['error']['message'] ?? '') : '';
                        $add('Microsoft 365', 'Sign-in blocked', 'fail', 'Could not read the account (HTTP ' . $code . ')' . ($msg ? ': ' . $msg : '') . ($code === 403 ? ' — the Azure app needs User.Read.All' : ''));
                    } else {
                        if (!empty($u['id']) && empty($run['m365_id'] ?? null)) { try { $db->prepare('UPDATE itops_ob_runs SET m365_id=? WHERE id=?')->execute([$u['id'], (int)$run['id']]); } catch (Throwable $e) {} }
                        $en = !empty($u['accountEnabled']);
                        $add('Microsoft 365', 'Sign-in blocked', $en ? 'fail' : 'pass', ($u['userPrincipalName'] ?? $key) . ($en ? ' can still sign in (account enabled)' : ' — sign-in blocked'));
                        $n = count($u['assignedLicenses'] ?? []);
                        $add('Microsoft 365', 'Licenses removed', $n ? 'fail' : 'pass', $n ? $n . ' license(s) still assigned' : 'No licenses assigned');
                    }
                } catch (Throwable $e) {
                    $add('Microsoft 365', 'Sign-in blocked', 'fail', 'Could not reach Microsoft 365: ' . $e->getMessage());
                }
            }
        }

        // ── vTiger ──
        $vu = itops_vt_run_user($db, $run);
        if (empty($vu['ok'])) {
            if (!empty($vu['not_configured'])) {
                $add('vTiger', 'User deactivated', 'skip', 'vTiger bridge not configured');
            } else {
                $add('vTiger', 'User deactivated', 'fail', 'Could not check vTiger: ' . ($vu['error'] ?? 'bridge error'));
            }
        } elseif (empty($vu['user'])) {
            $add('vTiger', 'User deactivated', 'skip', 'No vTiger user with ' . ($run['subject_email'] ?: 'this email') . ' — nothing to revoke');
        } else {
            $u = $vu['user'];
            $inactive = ($u['status'] ?? '') === 'Inactive';
            // BT40: a user that was not found by email and that nobody confirmed may be somebody else — say so, so a
            // "still Active" is not read as this person's access (an Inactive one passes either way).
            $guess = empty($u['confirmed']) && ($u['matched_by'] ?? 'email') !== 'email';
            $add('vTiger', 'User deactivated', $inactive ? 'pass' : 'fail', itops_vt_user_label($u) . ($inactive ? ' — Inactive' : ' is still ' . ($u['status'] ?? 'Active'))
                . (!$inactive && $guess ? '. It may be somebody else with the same user name: if it is this person, run "Deactivate vTiger user" on this record and confirm; if it is not, nothing needs to be done in vTiger' : ''));
            $pv = itops_vt_call($db, 'reassign_preview', ['from' => (string)$u['id']]);
            if (empty($pv['ok'])) $add('vTiger', 'Records reassigned', 'fail', 'Could not count records: ' . ($pv['error'] ?? 'bridge error'));
            else {
                $t = (int)($pv['total'] ?? 0);
                $add('vTiger', 'Records reassigned', $t ? 'fail' : 'pass', $t ? $t . ' record(s) still owned (' . itops_vt_mods_text($pv['modules'] ?? []) . ')' : 'No records owned');
            }
        }

        // ── ITOps equipment ──
        $em = trim((string)($run['subject_email'] ?? ''));
        if ($em !== '') {
            try {
                $lq = $db->prepare("SELECT a.asset_tag FROM itops_asset_loans l JOIN itops_assets a ON a.id=l.asset_id LEFT JOIN itops_users u ON u.id=l.user_id
                                    WHERE l.status='out' AND (LOWER(l.borrower_email)=LOWER(?) OR LOWER(u.email)=LOWER(?))");
                $lq->execute([$em, $em]); $tags = $lq->fetchAll(PDO::FETCH_COLUMN);
                $add(function_exists('itops_app_name') ? itops_app_name() : 'ITOps', 'Equipment returned', $tags ? 'fail' : 'pass', $tags ? count($tags) . ' item(s) still on loan: ' . implode(', ', $tags) : 'Nothing on loan');
            } catch (Throwable $e) { /* equipment module not installed */ }
        }

        // ── Company systems (BT44): what ITOps's records say about the other systems the person had an account in ──
        if (is_file(__DIR__ . '/Systems.php')) {
            try { require_once __DIR__ . '/Systems.php'; foreach (itops_sys_verify_checks($db, $run) as $c) $add($c['system'], $c['label'], $c['status'], $c['detail']); }
            catch (Throwable $e) { /* the table is not installed yet: nothing to report */ }
        }

        $issues = []; $pass = 0;
        foreach ($checks as $c) { if ($c['status'] === 'fail') $issues[] = $c['system'] . ': ' . $c['label'] . ' — ' . $c['detail']; if ($c['status'] === 'pass') $pass++; }
        // unchecked = nothing could be checked (no system connected / no accounts) — neither clean nor a failure
        $res = ['clean' => !$issues && $pass > 0, 'unchecked' => !$issues && $pass === 0, 'checks' => $checks, 'issues' => $issues, 'at' => date('Y-m-d H:i:s'), 'source' => $source];

        if ($record) {
            try { $db->prepare('UPDATE itops_ob_runs SET verify_json=?, verified_at=NOW(), verify_clean=? WHERE id=?')->execute([json_encode($res), $res['clean'] ? 1 : ($res['unchecked'] ? null : 0), (int)$run['id']]); } catch (Throwable $e) {}
            if (function_exists('audit_log')) {
                try { audit_log($res['clean'] ? 'offboarding_verified_clean' : 'offboarding_verify_failed', 'onboarding', (int)$run['id'],
                    (string)$run['subject_name'] . ' (' . $source . ')', null, ['clean' => $res['clean'], 'checks' => $checks]); } catch (Throwable $e) {}
            }
        }
        return $res;
    }
}
