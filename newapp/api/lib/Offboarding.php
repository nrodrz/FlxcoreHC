<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the "Verify offboarding" completion check (also used by the monthly access recertification).
 * 0001-A004: Microsoft 365 only (the reference app also checked vTiger, equipment loans and company systems).
 *
 * Verification re-reads Microsoft 365 — it does not trust what the run recorded — so it
 * catches a half-finished departure however it happened (a step that failed, a licence
 * re-added by hand, an account re-enabled). Every verification is written to the audit log.
 *
 * Checks (status pass | fail | skip):
 *   Microsoft 365  · Sign-in blocked      accountEnabled = false (a deleted account also passes)
 *                  · Licenses removed     no assigned licences
 * Microsoft 365 not connected, or no account to look at, is "skip".
 * clean = at least one pass and no fail.
 */

if (!function_exists('itops_vt_call')) {












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
