<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Risky people and their follow-up (BT106). Part of the Security assessment module.
 *
 * 1. Risky people: every enabled member account scored from what the assessment reads anyway — multifactor registration,
 *    admin roles, last sign-in, the person's managed devices and, where the tenant has it (Entra ID P2), Microsoft's own
 *    Identity Protection risk. One row per person with a score (0–100), a level (high ≥ 50, medium ≥ 25, low) and the
 *    reasons. Kept per assessment run.
 * 2. Follow-up (when switched on): a person newly at a followed level gets an email with their own reasons and steps and a
 *    deadline; a reminder; at the deadline, their manager is told, IT gets a ticket, and the changes ITOps could make
 *    (add to the MFA-required group, sign out everywhere, block sign-in) are PROPOSED. Nothing in the tenant changes until
 *    an administrator approves a proposal (decision, 10/23/2026). Someone who fixes it is marked fixed, their ticket is
 *    resolved with a note and their open proposals are withdrawn.
 * Every message, ticket, proposal, approval and refusal is in the Audit Log.
 */
require_once __DIR__ . '/SecAssess.php';

if (!function_exists('itops_rk_factors')) {

    /** code => [label, points, proposal (action key or ''), steps for the person (en), steps (es), reason for IT] */
    function itops_rk_factors(): array {
        return [
            'compromised' => ['Microsoft marked the account as compromised', 60, 'revoke_sessions',
                'Change your password now and sign out of every device; IT will contact you.', 'Cambie su contraseña ahora y cierre la sesión en todos sus dispositivos; TI se comunicará con usted.',
                'Identity Protection: confirmed compromised'],
            'identity_high' => ['Microsoft sees a high sign-in risk', 50, 'revoke_sessions',
                'Change your password and check your recent sign-ins at https://mysignins.microsoft.com; tell IT about anything you do not recognise.', 'Cambie su contraseña y revise sus inicios de sesión recientes en https://mysignins.microsoft.com; avise a TI de cualquier cosa que no reconozca.',
                'Identity Protection: risk level high'],
            'identity_medium' => ['Microsoft sees a medium sign-in risk', 30, '',
                'Check your recent sign-ins at https://mysignins.microsoft.com and tell IT about anything you do not recognise.', 'Revise sus inicios de sesión recientes en https://mysignins.microsoft.com y avise a TI de cualquier cosa que no reconozca.',
                'Identity Protection: risk level medium'],
            'identity_low' => ['Microsoft sees a low sign-in risk', 10, '',
                'Check your recent sign-ins at https://mysignins.microsoft.com.', 'Revise sus inicios de sesión recientes en https://mysignins.microsoft.com.',
                'Identity Protection: risk level low'],
            'admin_no_mfa' => ['Admin account without multifactor sign-in', 40, 'mfa_group',
                'Set up multifactor sign-in today at https://aka.ms/mfasetup (the Microsoft Authenticator app or a security key).', 'Configure hoy el inicio de sesión multifactor en https://aka.ms/mfasetup (la aplicación Microsoft Authenticator o una llave de seguridad).',
                'Holds an admin role and has no multifactor method'],
            'no_mfa' => ['No multifactor sign-in', 25, 'mfa_group',
                'Set up multifactor sign-in at https://aka.ms/mfasetup (about 10 minutes; the Microsoft Authenticator app is recommended).', 'Configure el inicio de sesión multifactor en https://aka.ms/mfasetup (unos 10 minutos; se recomienda la aplicación Microsoft Authenticator).',
                'No multifactor method registered'],
            'phone_only' => ['Only a text message or call as second step', 10, '',
                'Add the Microsoft Authenticator app at https://aka.ms/mfasetup; codes by text message can be intercepted.', 'Agregue la aplicación Microsoft Authenticator en https://aka.ms/mfasetup; los códigos por mensaje de texto pueden interceptarse.',
                'Only phone (text / call) methods registered'],
            'admin_daily' => ['Admin role on the everyday account', 15, '',
                'IT will give you a separate account for admin work; keep using this one for mail and everyday work.', 'TI le dará una cuenta separada para el trabajo de administración; siga usando esta para el correo y el trabajo diario.',
                'Admin role on a licensed (daily-use) account'],
            'stale' => ['Not used for more than 90 days', 15, 'block_signin',
                'If you still need this account, sign in once and tell IT; otherwise it will be closed.', 'Si todavía necesita esta cuenta, inicie sesión una vez y avise a TI; de lo contrario se cerrará.',
                'No sign-in for more than 90 days'],
            'never' => ['Never signed in', 10, 'block_signin',
                'If this account is yours, sign in once and set up multifactor sign-in; otherwise tell IT.', 'Si esta cuenta es suya, inicie sesión una vez y configure el inicio de sesión multifactor; de lo contrario avise a TI.',
                'Created over 30 days ago, never signed in'],
            'device_unencrypted' => ['A computer that is not encrypted', 20, '',
                'Leave your computer on and connected today so encryption can finish, or contact IT.', 'Deje su computadora encendida y conectada hoy para que termine el cifrado, o comuníquese con TI.',
                'A Windows or Mac computer is not encrypted'],
            'device_noncompliant' => ['A device that does not meet the security rules', 15, '',
                'Open the Company Portal app on the device and follow what it asks, or contact IT.', 'Abra la aplicación Portal de empresa en el dispositivo y siga lo que le pida, o comuníquese con TI.',
                'A managed device is not compliant'],
        ];
    }
    /** Proposals: key => [label, permission needed, what it does]. */
    function itops_rk_actions(): array {
        return ['mfa_group' => ['Add to the MFA-required group', 'm365_groups', 'Adds the account to the group your Conditional Access policy requires multifactor sign-in for.'],
                'revoke_sessions' => ['Sign out everywhere', 'm365_admin', 'Ends every Microsoft 365 session of the account; it must sign in again on every device.'],
                'block_signin' => ['Block sign-in', 'm365_users', 'Stops the account from signing in. Its mailbox and files stay; it can be unblocked.']];
    }
    function itops_rk_level(int $score): string { return $score >= 50 ? 'high' : ($score >= 25 ? 'medium' : ($score > 0 ? 'low' : 'none')); }

    /** Every enabled member account at risk, with its reasons. Sources that cannot be read are skipped and named. */
    function itops_rk_compute(callable $D): array {
        $src = ['users' => false, 'mfa' => false, 'roles' => false, 'signin' => false, 'devices' => false, 'identity' => false];
        try { $u = $D('users'); $src['users'] = true; $src['signin'] = !empty($u['signin']); } catch (Throwable $e) { return ['sources' => $src, 'people' => []]; }
        $people = [];
        foreach (($u['rows'] ?? []) as $r) {
            if (empty($r['accountEnabled']) || ($r['userType'] ?? 'Member') === 'Guest') continue;
            $people[strtolower((string)$r['id'])] = ['oid' => (string)$r['id'], 'upn' => (string)($r['userPrincipalName'] ?? ''), 'name' => (string)($r['displayName'] ?? ''),
                'mail' => (string)(($r['mail'] ?? '') ?: ($r['userPrincipalName'] ?? '')), 'admin' => false, 'licensed' => !empty($r['assignedLicenses']), 'f' => [], 'd' => [],
                'last' => $r['signInActivity']['lastSignInDateTime'] ?? null, 'created' => $r['createdDateTime'] ?? null];
        }
        $add = function (string $id, string $code, string $detail = '') use (&$people) { if (!isset($people[$id]) || in_array($code, $people[$id]['f'], true)) return; $people[$id]['f'][] = $code; if ($detail !== '') $people[$id]['d'][$code] = $detail; };
        $roles = [];
        try { foreach (itops_sa_role_holders($D, itops_sa_high_templates()) as $id => [$m, $rn]) { $roles[$id] = $rn; if (isset($people[$id])) $people[$id]['admin'] = true; } $src['roles'] = true; } catch (Throwable $e) {}
        try {
            $phone = ['mobilephone', 'alternatemobilephone', 'officephone', 'sms', 'voice'];
            foreach ($D('reg') as $r) {
                $id = strtolower((string)($r['id'] ?? '')); if (!isset($people[$id])) continue;
                if (empty($r['isMfaRegistered'])) $add($id, isset($roles[$id]) ? 'admin_no_mfa' : 'no_mfa', isset($roles[$id]) ? implode(', ', $roles[$id]) : '');
                else { $ms = array_map('strtolower', (array)($r['methodsRegistered'] ?? [])); if ($ms && !array_diff($ms, $phone)) $add($id, 'phone_only'); }
            }
            $src['mfa'] = true;
        } catch (Throwable $e) {}
        foreach ($roles as $id => $rn) if (!empty($people[$id]['licensed'])) $add($id, 'admin_daily', implode(', ', $rn));
        $emR = []; try { $emR = array_map('strtolower', (array)$D('emergency')); } catch (Throwable $e) {}
        if ($src['signin']) foreach ($people as $id => $p) {
            if (in_array(strtolower((string)$p['upn']), $emR, true)) continue;   // BT124 (review SEC-11): emergency accounts are rarely used on purpose
            $d = itops_sa_days_since($p['last']); $age = itops_sa_days_since($p['created']);
            if ($d === null && ($age === null || $age > 30)) $add($id, 'never');
            elseif ($d !== null && $d > 90) $add($id, 'stale', 'last sign-in ' . $d . ' days ago');
        }
        try {
            $byUpn = []; foreach ($people as $id => $p) $byUpn[strtolower($p['upn'])] = $id;
            foreach ($D('devices') as $dv) {
                $id = $byUpn[strtolower((string)($dv['userPrincipalName'] ?? ''))] ?? null; if ($id === null) continue;
                $os = strtolower((string)($dv['operatingSystem'] ?? ''));
                if (in_array($os, ['windows', 'macos'], true) && empty($dv['isEncrypted'])) $add($id, 'device_unencrypted', (string)($dv['deviceName'] ?? ''));
                if (in_array(strtolower((string)($dv['complianceState'] ?? '')), ['noncompliant', 'error', 'conflict'], true)) $add($id, 'device_noncompliant', (string)($dv['deviceName'] ?? ''));
            }
            $src['devices'] = true;
        } catch (Throwable $e) {}
        try {
            foreach ($D('risky') as $rk) {
                $id = strtolower((string)($rk['id'] ?? '')); $state = (string)($rk['riskState'] ?? ''); $lvl = (string)($rk['riskLevel'] ?? '');
                if ($state === 'confirmedCompromised') $add($id, 'compromised');
                elseif ($state === 'atRisk' && in_array($lvl, ['high', 'medium', 'low'], true)) $add($id, 'identity_' . $lvl);
            }
            $src['identity'] = true;
        } catch (Throwable $e) {}
        $F = itops_rk_factors(); $out = [];
        foreach ($people as $p) {
            if (!$p['f']) continue;
            $score = min(100, array_sum(array_map(fn($c) => $F[$c][1], $p['f'])));
            usort($p['f'], fn($a, $b) => $F[$b][1] <=> $F[$a][1]);
            $out[] = ['oid' => $p['oid'], 'upn' => $p['upn'], 'name' => $p['name'], 'mail' => $p['mail'], 'admin' => $p['admin'], 'score' => $score, 'level' => itops_rk_level($score), 'factors' => $p['f'], 'details' => $p['d']];
        }
        usort($out, fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcasecmp($a['name'], $b['name']));
        return ['sources' => $src, 'people' => $out];
    }

    function itops_rk_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_risk_followups LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    /** Keeps the list of a run. Returns the counts. */
    function itops_rk_store(PDO $db, int $runId, array $c): array {
        if (!itops_rk_ready($db)) return ['error' => 'database update needed'];
        $n = ['high' => 0, 'medium' => 0, 'low' => 0];
        $ins = $db->prepare('INSERT INTO itops_risk_people (run_id, user_oid, upn, name, mail, is_admin, score, level, factors, details) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($c['people'] as $p) {
            $ins->execute([$runId, $p['oid'], mb_substr($p['upn'], 0, 255), mb_substr($p['name'], 0, 255), mb_substr($p['mail'], 0, 255), $p['admin'] ? 1 : 0, $p['score'], $p['level'], json_encode($p['factors']), json_encode($p['details'], JSON_UNESCAPED_UNICODE)]);
            $n[$p['level']]++;
        }
        $db->prepare('INSERT INTO itops_risk_runs (run_id, sources, people, high, medium, low) VALUES (?,?,?,?,?,?)')->execute([$runId, json_encode($c['sources']), count($c['people']), $n['high'], $n['medium'], $n['low']]);
        $keep = (int)$db->query('SELECT COALESCE(MIN(id),0) FROM itops_sa_runs')->fetchColumn();
        $db->prepare('DELETE FROM itops_risk_people WHERE run_id < ?')->execute([$keep]); $db->prepare('DELETE FROM itops_risk_runs WHERE run_id < ?')->execute([$keep]);
        return $n + ['people' => count($c['people'])];
    }

    /** The follow-up settings. */
    function itops_rf_cfg(PDO $db): array {
        $d = ['enabled' => false, 'levels' => ['high', 'medium'], 'days' => 7, 'remind_after' => 3, 'manager' => true, 'ticket' => true, 'ticket_category_id' => null, 'mfa_group_id' => '', 'mfa_group_name' => ''];
        try { $v = json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='rf_cfg'")->fetchColumn(), true); if (is_array($v)) $d = array_merge($d, array_intersect_key($v, $d)); } catch (Throwable $e) {}
        return $d;
    }
    function itops_rf_clean(array $b): array {
        $c = [];
        if (array_key_exists('enabled', $b)) { if (!is_bool($b['enabled'])) throw new InvalidArgumentException('enabled is true or false.'); $c['enabled'] = $b['enabled']; }
        if (array_key_exists('levels', $b)) { if (!is_array($b['levels']) || !$b['levels'] || array_diff($b['levels'], ['high', 'medium', 'low'])) throw new InvalidArgumentException('Levels: one or more of high, medium, low.'); $c['levels'] = array_values(array_unique($b['levels'])); }
        foreach (['days' => [1, 60], 'remind_after' => [0, 59]] as $k => [$lo, $hi]) if (array_key_exists($k, $b)) { if (!is_int($b[$k]) || $b[$k] < $lo || $b[$k] > $hi) throw new InvalidArgumentException(($k === 'days' ? 'Days to fix' : 'Reminder after') . ' is a whole number from ' . $lo . ' to ' . $hi . '.'); $c[$k] = $b[$k]; }
        foreach (['manager', 'ticket'] as $k) if (array_key_exists($k, $b)) { if (!is_bool($b[$k])) throw new InvalidArgumentException($k . ' is true or false.'); $c[$k] = $b[$k]; }
        if (array_key_exists('ticket_category_id', $b)) { $v = $b['ticket_category_id']; if ($v !== null && !is_int($v)) throw new InvalidArgumentException('The ticket category is a category id or null.'); $c['ticket_category_id'] = $v; }
        if (array_key_exists('mfa_group_id', $b)) { $v = (string)$b['mfa_group_id']; if ($v !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $v)) throw new InvalidArgumentException('The MFA-required group is a Microsoft 365 group id.'); $c['mfa_group_id'] = $v; $c['mfa_group_name'] = mb_substr(trim((string)($b['mfa_group_name'] ?? '')), 0, 200); }
        return $c;
    }

    /** The reasons as a short markdown list in English and Spanish (for the emails), with the person's own steps. */
    function itops_rf_body(array $codes, bool $steps = true): array {
        $F = itops_rk_factors(); $en = []; $es = []; $esl = itops_rf_es_labels();
        foreach ($codes as $c) { if (!isset($F[$c])) continue;
            $en[] = '- **' . $F[$c][0] . '**' . ($steps ? ': ' . $F[$c][3] : '');
            $es[] = '- **' . ($esl[$c] ?? $F[$c][0]) . '**' . ($steps ? ': ' . $F[$c][4] : ''); }
        return ['en' => implode("\n", $en), 'es' => implode("\n", $es)];
    }
    function itops_rf_es_labels(): array {
        return ['compromised' => 'Microsoft marcó la cuenta como comprometida', 'identity_high' => 'Microsoft ve un riesgo alto en los inicios de sesión', 'identity_medium' => 'Microsoft ve un riesgo medio en los inicios de sesión',
                'identity_low' => 'Microsoft ve un riesgo bajo en los inicios de sesión', 'admin_no_mfa' => 'Cuenta de administrador sin inicio de sesión multifactor', 'no_mfa' => 'Sin inicio de sesión multifactor',
                'phone_only' => 'Solo un mensaje de texto o llamada como segundo paso', 'admin_daily' => 'Rol de administrador en la cuenta de uso diario', 'stale' => 'Sin uso por más de 90 días',
                'never' => 'Nunca ha iniciado sesión', 'device_unencrypted' => 'Una computadora sin cifrar', 'device_noncompliant' => 'Un dispositivo que no cumple las reglas de seguridad'];
    }

    /** One run's follow-up. $D reads Microsoft 365 (managers). Returns what happened. */
    function itops_rf_process(PDO $db, int $runId, callable $D, ?string $today = null): array {
        $cfg = itops_rf_cfg($db); $today = $today ?: date('Y-m-d');
        $out = ['notified' => 0, 'reminded' => 0, 'escalated' => 0, 'fixed' => 0, 'proposed' => 0, 'tickets' => 0, 'emails_failed' => 0];
        $rr = $db->prepare('SELECT sources FROM itops_risk_runs WHERE run_id=?'); $rr->execute([$runId]); $src = json_decode((string)$rr->fetchColumn(), true) ?: [];
        if (empty($src['users']) || empty($src['mfa'])) return $out + ['skipped' => 'The accounts or their multifactor status could not be read; nothing was sent or closed.'];
        require_once __DIR__ . '/EmailTemplates.php';
        $q = $db->prepare('SELECT * FROM itops_risk_people WHERE run_id=?'); $q->execute([$runId]);
        $now = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) if (in_array($p['level'], $cfg['levels'], true)) $now[strtolower($p['user_oid'])] = $p;
        $open = []; foreach ($db->query("SELECT * FROM itops_risk_followups WHERE status IN ('open','escalated')")->fetchAll(PDO::FETCH_ASSOC) as $f) $open[strtolower($f['user_oid'])] = $f;
        $link = (defined('APP_URL') ? rtrim((string)APP_URL, '/') : '');
        $send = function (string $tpl, string $to, array $vars, string $what, int $fid) use ($db, &$out) {
            if ($to === '' || strpos($to, '@') === false) { $out['emails_failed']++; return false; }
            $r = itops_email($tpl, $to, $vars);
            if (empty($r['ok'])) { $out['emails_failed']++; audit_log('rf_email_failed', 'security_assessment', $fid, $what . ' → ' . $to, null, ['Why' => (string)($r['error'] ?? '')]); return false; }
            return true;
        };
        foreach ($now as $oid => $p) {
            $codes = json_decode($p['factors'], true) ?: [];
            if (isset($open[$oid])) {
                $f = $open[$oid]; $fid = (int)$f['id'];
                $db->prepare('UPDATE itops_risk_followups SET factors=?, last_score=?, level=?, last_run_id=? WHERE id=?')->execute([json_encode($codes), (int)$p['score'], $p['level'], $runId, $fid]);
                $remindOn = date('Y-m-d', strtotime($f['started_at'] . ' +' . (int)$cfg['remind_after'] . ' days'));
                if ($f['status'] === 'open' && !$f['reminded_at'] && $cfg['remind_after'] > 0 && $today >= $remindOn && $today < $f['deadline']) {
                    $send('risk_user_reminder', $p['mail'], ['recipient_name' => $p['name'], 'deadline' => itops_tpl_d2($f['deadline']), 'body_md' => itops_rf_body($codes), 'link' => $link], 'reminder', $fid);
                    $db->prepare('UPDATE itops_risk_followups SET reminded_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $fid]);
                    audit_log('rf_reminded', 'security_assessment', $fid, $p['name'] . ' (' . $p['upn'] . ')', null, ['Deadline' => $f['deadline']]); $out['reminded']++;
                }
                if ($f['status'] === 'open' && $today >= $f['deadline']) { itops_rf_escalate($db, $f, $p, $codes, $cfg, $D, $send, $out); }
                continue;
            }
            $dq = $db->prepare("SELECT COUNT(*) FROM itops_risk_followups WHERE user_oid=? AND status='dismissed' AND closed_at >= ?"); $dq->execute([$p['user_oid'], date('Y-m-d H:i:s', strtotime($today . ' -30 days'))]);
            if ((int)$dq->fetchColumn() > 0) continue;   // an administrator dismissed it recently: not again for 30 days
            $deadline = date('Y-m-d', strtotime($today . ' +' . (int)$cfg['days'] . ' days'));
            $db->prepare("INSERT INTO itops_risk_followups (user_oid, upn, name, mail, level, first_score, last_score, factors, status, deadline, started_at, first_run_id, last_run_id) VALUES (?,?,?,?,?,?,?,?,'open',?,?,?,?)")
               ->execute([$p['user_oid'], $p['upn'], $p['name'], $p['mail'], $p['level'], (int)$p['score'], (int)$p['score'], json_encode($codes), $deadline, $today . ' ' . date('H:i:s'), $runId, $runId]);
            $fid = (int)$db->lastInsertId();
            $ok = $send('risk_user_notice', $p['mail'], ['recipient_name' => $p['name'], 'deadline' => itops_tpl_d2($deadline), 'body_md' => itops_rf_body($codes), 'link' => $link], 'notice', $fid);
            audit_log('rf_started', 'security_assessment', $fid, $p['name'] . ' (' . $p['upn'] . ')', null, ['Level' => $p['level'], 'Score' => (int)$p['score'], 'Reasons' => implode(', ', $codes), 'Deadline' => $deadline, 'Email' => $ok ? 'sent' : 'not sent']);
            $out['notified']++;
        }
        // fixed: no longer at a followed level
        foreach ($open as $oid => $f) {
            if (isset($now[$oid])) continue;
            itops_rf_close($db, $f, 'fixed', 'Fixed: the assessment of ' . date('m/d/Y') . ' no longer finds ' . ($f['name'] ?: $f['upn']) . ' at risk.', null);
            $out['fixed']++;
        }
        return $out;
    }

    /** The deadline passed: tell the manager, open a ticket, propose the changes (nothing changes until approved). */
    function itops_rf_escalate(PDO $db, array $f, array $p, array $codes, array $cfg, callable $D, callable $send, array &$out): void {
        $fid = (int)$f['id']; $mgr = null;
        if ($cfg['manager']) { try { $mgr = $D('manager:' . $p['user_oid']); } catch (Throwable $e) { $mgr = null; } }
        if ($mgr && !empty($mgr['mail'])) $send('risk_manager_escalation', $mgr['mail'], ['recipient_name' => $mgr['name'], 'person_name' => $p['name'], 'deadline' => itops_tpl_d2($f['deadline']), 'body_md' => itops_rf_body($codes, false)], 'manager', $fid);
        $tid = null;
        if ($cfg['ticket']) {
            try {
                require_once __DIR__ . '/TicketNumbers.php';
                $F = itops_rk_factors();
                $desc = "Risky people follow-up (Security assessment). " . $p['name'] . ' (' . $p['upn'] . ') has not fixed these by ' . date('m/d/Y', strtotime($f['deadline'])) . ":\n\n"
                      . implode("\n", array_map(fn($c) => '- ' . ($F[$c][5] ?? $c), $codes)) . "\n\nScore " . (int)$p['score'] . ' (' . $p['level'] . "). The person was emailed on " . date('m/d/Y', strtotime($f['started_at']))
                      . ($f['reminded_at'] ? ' and reminded on ' . date('m/d/Y', strtotime($f['reminded_at'])) : '') . ($mgr && !empty($mgr['mail']) ? '; their manager (' . $mgr['name'] . ') was told today' : '') . '.'
                      . "\n\nChanges " . (function_exists('itops_app_name') ? itops_app_name() : 'the app') . " can make wait for approval in IT Operations → Risky people → Approvals.";
                $subj = mb_substr('Account at risk: ' . $p['name'], 0, 250); $cat = $cfg['ticket_category_id'];
                // requester: the person's own ITOps account when they have one, else the first active Super Admin (the
                // ticket is IT's own work; the person is named in it)
                $rq = $db->prepare('SELECT id FROM itops_users WHERE LOWER(email) IN (LOWER(?), LOWER(?)) AND status=\'active\' LIMIT 1'); $rq->execute([$p['mail'], $p['upn']]); $req = (int)$rq->fetchColumn();
                if (!$req) $req = (int)$db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active' ORDER BY u.id LIMIT 1")->fetchColumn();
                [$tn, $tid] = itops_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $desc, $cat, $p, $req) {
                    $db->prepare("INSERT INTO itops_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id) VALUES (?,?,?,?,?,'open','request',?)")
                       ->execute([$tn, $subj, $desc, $cat, $p['level'] === 'high' ? 'p2_high' : 'p3_medium', $req]);
                    return [$tn, (int)$db->lastInsertId()];
                });
                if (!function_exists('itops_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
                try { itops_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
                try { require_once __DIR__ . '/TicketRouting.php'; itops_tr_on_created($db, $tid, null, 'risk'); } catch (Throwable $e) {}
                audit_log('rf_ticket', 'security_assessment', $fid, $p['name'] . ' (' . $p['upn'] . ')', null, ['Ticket' => $tn]);
                $out['tickets']++;
            } catch (Throwable $e) { $tid = null; audit_log('rf_ticket_failed', 'security_assessment', $fid, $p['name'], null, ['Why' => mb_substr($e->getMessage(), 0, 200)]); }
        }
        // proposals
        $F = itops_rk_factors(); $acts = [];
        foreach ($codes as $c) { $a = $F[$c][2] ?? ''; if ($a === '' || isset($acts[$a])) continue; if ($a === 'mfa_group' && $cfg['mfa_group_id'] === '') continue; $acts[$a] = $F[$c][5]; }
        $n = 0;
        foreach ($acts as $a => $why) {
            $ex = $db->prepare("SELECT COUNT(*) FROM itops_risk_actions WHERE followup_id=? AND action=? AND status IN ('pending','done')"); $ex->execute([$fid, $a]);
            if ((int)$ex->fetchColumn()) continue;
            $db->prepare("INSERT INTO itops_risk_actions (followup_id, user_oid, upn, name, action, reason, status, created_at) VALUES (?,?,?,?,?,?,'pending',?)")->execute([$fid, $p['user_oid'], $p['upn'], $p['name'], $a, $why, date('Y-m-d H:i:s')]);
            $n++;
        }
        $out['proposed'] += $n;
        if ($n) { try { foreach ($db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active'")->fetchAll(PDO::FETCH_COLUMN) as $uid)
            $db->prepare('INSERT INTO itops_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$uid, 'Changes wait for your approval', $n . ' proposed change(s) for ' . $p['name'] . ' (risky people follow-up).', 'warning', '#risk_users']); } catch (Throwable $e) {} }
        $db->prepare("UPDATE itops_risk_followups SET status='escalated', escalated_at=?, ticket_id=?, manager_mail=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $tid, $mgr['mail'] ?? null, $fid]);
        audit_log('rf_escalated', 'security_assessment', $fid, $p['name'] . ' (' . $p['upn'] . ')', null, ['Manager told' => $mgr && !empty($mgr['mail']) ? $mgr['name'] : 'no manager set', 'Ticket' => $tid ? 'yes' : 'no', 'Changes proposed' => $n]);
        $out['escalated']++;
    }

    /** Closes a follow-up (fixed | dismissed): the ticket gets a note and is resolved, open proposals are withdrawn. */
    function itops_rf_close(PDO $db, array $f, string $status, string $note, ?int $by): void {
        $fid = (int)$f['id'];
        $db->prepare('UPDATE itops_risk_followups SET status=?, closed_at=?, closed_by=?, close_note=? WHERE id=?')->execute([$status, date('Y-m-d H:i:s'), $by, mb_substr($note, 0, 500), $fid]);
        $db->prepare("UPDATE itops_risk_actions SET status='withdrawn', decided_at=?, result=? WHERE followup_id=? AND status='pending'")->execute([date('Y-m-d H:i:s'), $status === 'fixed' ? 'No longer needed: fixed.' : 'Withdrawn: the follow-up was dismissed.', $fid]);
        if (!empty($f['ticket_id'])) {
            try {
                $who = $by ?: (int)$db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active' ORDER BY u.id LIMIT 1")->fetchColumn();   // automatic: noted as the Super Admin
                $db->prepare('INSERT INTO itops_ticket_comments (ticket_id, user_id, content, is_internal) VALUES (?,?,?,1)')->execute([(int)$f['ticket_id'], $who, $note]);
                $db->prepare("UPDATE itops_tickets SET status='resolved', resolved_at=COALESCE(resolved_at, ?), resolution_notes=COALESCE(NULLIF(resolution_notes,''), ?) WHERE id=? AND status NOT IN ('resolved','closed','cancelled')")
                   ->execute([date('Y-m-d H:i:s'), $note, (int)$f['ticket_id']]);
            } catch (Throwable $e) { audit_log('rf_ticket_failed', 'security_assessment', $fid, (string)($f['name'] ?? ''), null, ['Why' => 'closing the ticket: ' . mb_substr($e->getMessage(), 0, 200)]); }
        }
        audit_log($status === 'fixed' ? 'rf_fixed' : 'rf_dismissed', 'security_assessment', $fid, ($f['name'] ?: $f['upn']) . ' (' . $f['upn'] . ')', null, ['Note' => mb_substr($note, 0, 200)]);
    }

    /** Carries out an approved proposal. Throws with a sentence when it cannot. */
    function itops_rf_execute(PDO $db, array $a, array $user): string {
        if (!function_exists('itops_graph_send')) require_once __DIR__ . '/Graph.php';
        require_once __DIR__ . '/M365Guard.php';
        $cfg = itops_rf_cfg($db); $oid = (string)$a['user_oid'];
        // BT124 (review SEC-12, SEC-11): a refusal leaves the proposal waiting for someone else (InvalidArgumentException),
        // and the emergency access accounts are never blocked or signed out from here
        if (!empty($user['o365_oid']) && strcasecmp((string)$user['o365_oid'], $oid) === 0) throw new InvalidArgumentException('This is your own account: ask another administrator.');
        if (strcasecmp((string)($user['email'] ?? ''), (string)$a['upn']) === 0) throw new InvalidArgumentException('This is your own account: ask another administrator.');
        $emL = []; try { $emL = array_map('strtolower', array_filter((array)json_decode((string)$db->query("SELECT sval FROM itops_settings WHERE skey='sa_emergency'")->fetchColumn(), true), 'is_string')); } catch (Throwable $e) {}
        if (in_array(strtolower((string)$a['upn']), $emL, true) && $a['action'] !== 'mfa_group') throw new InvalidArgumentException('This is an emergency access account: it is never blocked or signed out from here.');
        if ($a['action'] !== 'mfa_group') itops_m365_guard_target($user, (string)$a['upn'], 'do this');   // admin accounts: Super Admin only
        switch ($a['action']) {
            case 'mfa_group':
                if ($cfg['mfa_group_id'] === '') throw new RuntimeException('Choose the MFA-required group in the follow-up settings first.');
                try { itops_graph_send('POST', 'groups/' . rawurlencode($cfg['mfa_group_id']) . '/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/' . rawurlencode($oid)]); }
                catch (Throwable $e) { if (stripos($e->getMessage(), 'already exist') === false) throw $e; return 'Already in the group.'; }
                return 'Added to ' . ($cfg['mfa_group_name'] ?: 'the MFA-required group') . '.';
            case 'revoke_sessions':
                itops_graph_send('POST', 'users/' . rawurlencode($oid) . '/revokeSignInSessions', []);
                return 'Signed out of every session.';
            case 'block_signin':
                itops_graph_send('PATCH', 'users/' . rawurlencode($oid), ['accountEnabled' => false]);
                return 'Sign-in blocked.';
        }
        throw new RuntimeException('Unknown change.');
    }
    /* ── BT144: Microsoft's own risk list (Identity Protection), read live ─────────────────────────────────────────────
     * The same list as the Entra admin center's "Risky users": every account Microsoft flags, disabled ones included, with
     * level, state, why and when it was last updated; a person's risk history; and the two answers sent back to Microsoft
     * (dismiss the risk, confirm the account compromised). Reading needs IdentityRiskyUser.Read.All and Entra ID P2;
     * answering needs IdentityRiskyUser.ReadWrite.All. */
    function itops_rk_ms_states(): array {
        return ['atRisk' => 'At risk', 'confirmedCompromised' => 'Confirmed compromised', 'remediated' => 'Remediated', 'dismissed' => 'Dismissed',
                'confirmedSafe' => 'Confirmed safe', 'none' => 'None', 'unknownFutureValue' => 'Other'];
    }
    function itops_rk_ms_details(): array {
        return ['none' => '', 'adminGeneratedTemporaryPassword' => 'An admin set a temporary password', 'userPerformedSecuredPasswordChange' => 'The person changed their password securely',
                'userPerformedSecuredPasswordReset' => 'The person reset their password securely', 'adminConfirmedSigninSafe' => 'An admin confirmed the sign-in safe',
                'aiConfirmedSigninSafe' => 'Microsoft confirmed the sign-in safe', 'userPassedMFADrivenByRiskBasedPolicy' => 'The person passed multifactor sign-in asked by a risk policy',
                'adminDismissedAllRiskForUser' => 'An admin dismissed the risk', 'adminConfirmedSigninCompromised' => 'An admin confirmed a sign-in compromised',
                'adminConfirmedUserCompromised' => 'An admin confirmed the account compromised', 'hidden' => 'Hidden (the app cannot see why)',
                'userChangedPasswordOnPremises' => 'The person changed their password on the local directory', 'adminDismissedRiskForSignIn' => 'An admin dismissed a sign-in risk',
                'adminConfirmedAccountSafe' => 'An admin confirmed the account safe', 'unknownFutureValue' => ''];
    }
    /**
     * BT169: why Microsoft's list cannot be read — 'permission' (the app's token has neither IdentityRiskyUser permission),
     * 'license' (the permission is there and Microsoft still refuses: the tenant has no Entra ID P2) or 'other' — with the
     * plain message. Business Premium includes Entra ID P1 only, so 'license' is the usual answer there.
     */
    function itops_rk_ms_diag(Throwable $e): array {
        $m = $e->getMessage(); $has = null;
        try { if (!class_exists('ItopsM365')) require_once __DIR__ . '/M365Hub.php'; if (class_exists('ItopsM365') && method_exists('ItopsM365', 'tokenHas')) $has = ItopsM365::tokenHas(['IdentityRiskyUser.Read.All', 'IdentityRiskyUser.ReadWrite.All']); } catch (Throwable $x) { $has = null; }
        $refused = (bool)preg_match('/403|denied|Authorization|Forbidden|licen[cs]e|P2|premium/i', $m);
        if ($refused && $has === false) return ['kind' => 'permission', 'message' => 'The app does not have the Microsoft Graph permission IdentityRiskyUser.Read.All yet (IdentityRiskyUser.ReadWrite.All to dismiss risk or confirm compromised).'];
        if ($refused) return ['kind' => 'license', 'message' => 'Microsoft refused the list: it gives the risky-users list to apps only when the tenant has Microsoft Entra ID P2. Microsoft 365 Business Premium includes Entra ID P1, where the Entra admin center shows the people with limited data (risk level "None", no reason).'];
        return ['kind' => 'other', 'message' => 'Microsoft could not be read: ' . mb_substr($m, 0, 200)];
    }
    /** Graph's answer when the read is not allowed, in plain words. */
    function itops_rk_ms_why(Throwable $e): string {
        $m = $e->getMessage();
        if (preg_match('/403|denied|Authorization|Forbidden/i', $m)) return 'Microsoft refused the read. The app needs the Microsoft Graph permission IdentityRiskyUser.Read.All (with admin consent) and the tenant needs Entra ID P2. Check Settings → Company systems → Microsoft 365 → Permissions.';
        if (preg_match('/P2|license|licen[cs]e/i', $m)) return 'This needs Microsoft Entra ID P2 on the tenant.';
        return 'Microsoft could not be read: ' . mb_substr($m, 0, 200);
    }
    /** Every flagged account, newest change first. Each: id, name, upn, level, state, state_label, detail, updated, deleted, processing, disabled (null = unknown). */
    function itops_rk_ms_list(): array {
        if (!function_exists('itops_graph_paged')) require_once __DIR__ . '/Graph.php';
        $rows = itops_graph_paged('identityProtection/riskyUsers?$top=500', 10);
        $off = null; try { $off = []; foreach (itops_graph_paged('users?$filter=accountEnabled%20eq%20false&$select=id&$top=999', 10) as $u) $off[strtolower((string)($u['id'] ?? ''))] = true; } catch (Throwable $e) { $off = null; }
        $S = itops_rk_ms_states(); $Dt = itops_rk_ms_details(); $out = [];
        foreach ($rows as $r) {
            $id = (string)($r['id'] ?? ''); if ($id === '') continue; $st = (string)($r['riskState'] ?? 'none'); $dt = (string)($r['riskDetail'] ?? 'none');
            $out[] = ['id' => $id, 'name' => (string)($r['userDisplayName'] ?? ''), 'upn' => (string)($r['userPrincipalName'] ?? ''), 'level' => (string)($r['riskLevel'] ?? 'none'),
                'state' => $st, 'state_label' => $S[$st] ?? $st, 'detail' => $Dt[$dt] ?? $dt, 'updated' => $r['riskLastUpdatedDateTime'] ?? null,
                'deleted' => !empty($r['isDeleted']), 'processing' => !empty($r['isProcessing']), 'disabled' => $off === null ? null : isset($off[strtolower($id)])];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['updated'], (string)$a['updated']));
        return $out;
    }
    /** A person's risk history (newest first). */
    function itops_rk_ms_history(string $id): array {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) throw new InvalidArgumentException('Unknown account.');
        if (!function_exists('itops_graph_paged')) require_once __DIR__ . '/Graph.php';
        $S = itops_rk_ms_states(); $Dt = itops_rk_ms_details(); $out = [];
        foreach (itops_graph_paged('identityProtection/riskyUsers/' . $id . '/history?$top=50', 2) as $h) {
            $st = (string)($h['riskState'] ?? 'none'); $dt = (string)($h['riskDetail'] ?? 'none'); $ev = (array)($h['activity']['riskEventTypes'] ?? []);
            $out[] = ['when' => $h['riskLastUpdatedDateTime'] ?? null, 'level' => (string)($h['riskLevel'] ?? ''), 'state' => $S[$st] ?? $st, 'detail' => $Dt[$dt] ?? $dt,
                'by' => (string)($h['initiatedBy'] ?? ''), 'events' => array_map(fn($x) => itops_rk_ms_event((string)$x), $ev)];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
        return $out;
    }
    function itops_rk_ms_event(string $e): string {
        $L = ['unfamiliarFeatures' => 'Unfamiliar sign-in properties', 'anonymizedIPAddress' => 'Anonymous IP address', 'maliciousIPAddress' => 'Malicious IP address',
              'impossibleTravel' => 'Impossible travel', 'leakedCredentials' => 'Leaked credentials', 'passwordSpray' => 'Password spray', 'unlikelyTravel' => 'Atypical travel',
              'investigationsThreatIntelligence' => 'Microsoft threat intelligence', 'suspiciousIPAddress' => 'Suspicious IP address', 'mcasSuspiciousInboxManipulationRules' => 'Suspicious inbox rules',
              'suspiciousInboxForwarding' => 'Suspicious inbox forwarding', 'tokenIssuerAnomaly' => 'Token issuer anomaly', 'anomalousToken' => 'Anomalous token',
              'suspiciousBrowser' => 'Suspicious browser', 'newCountry' => 'New country', 'adminConfirmedUserCompromised' => 'Admin confirmed the account compromised',
              'riskyIPAddress' => 'Activity from a risky IP address', 'suspiciousAPITraffic' => 'Suspicious API traffic', 'attackerinTheMiddle' => 'Attacker in the middle',
              'mfaFraud' => 'Multifactor fraud reported by the person', 'userReportedSuspiciousActivity' => 'The person reported suspicious activity'];
        return $L[$e] ?? trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $e));
    }
    /** Sends Microsoft one answer for up to 60 accounts: 'dismiss' (risk dismissed) or 'compromised' (confirmed compromised). */
    function itops_rk_ms_answer(string $kind, array $ids): void {
        if (!function_exists('itops_graph_send')) require_once __DIR__ . '/Graph.php';
        $path = ['dismiss' => 'identityProtection/riskyUsers/dismiss', 'compromised' => 'identityProtection/riskyUsers/confirmCompromised'][$kind] ?? null;
        if (!$path) throw new InvalidArgumentException('Unknown answer.');
        itops_graph_send('POST', $path, ['userIds' => array_values($ids)]);
    }

}
