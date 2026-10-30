<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT119: security baselines, build 4c (part 2). Defender incidents handled from the app, and admin roles.
 *
 *  - Incidents: read one with its alerts; assign it, set it in progress, resolve it (a classification and a
 *    determination are required, as Microsoft asks), add a comment, make a ticket of it (once).
 *  - Admin roles: every admin role holder with what makes the account risky (synced, licensed, no multifactor, not
 *    signed in for 90 days, disabled, guest); remove a role, or make it eligible (Entra ID P2) instead of permanent.
 *    At least two permanent Global Administrators always stay, and an emergency access account keeps its role.
 *    Every role change keeps what it was, so "Put back" can undo it.
 *
 * Graph (application permissions): SecurityIncident.ReadWrite.All (SecurityIncident.Read.All to read),
 * RoleManagement.ReadWrite.Directory (RoleManagement.Read.Directory to read), RoleEligibilitySchedule.ReadWrite.Directory
 * (eligible roles), User.Read.All, AuditLog.Read.All (last sign-in, multifactor registration).
 */

if (!function_exists('itops_so_classes')) {

    /* ─────────── incidents ─────────── */

    /** classification => [label, [determination => label]] (Microsoft's pairs). */
    function itops_so_classes(): array {
        return [
            'truePositive' => ['True positive (a real threat)', ['multiStagedAttack' => 'Multi-staged attack', 'maliciousUserActivity' => 'Malicious user activity', 'compromisedAccount' => 'Compromised account',
                               'malware' => 'Malware', 'phishing' => 'Phishing', 'unwantedSoftware' => 'Unwanted software', 'other' => 'Other']],
            'informationalExpectedActivity' => ['Expected activity (informational)', ['securityTesting' => 'Security test', 'lineOfBusinessApplication' => 'Line-of-business application', 'confirmedActivity' => 'Confirmed activity', 'other' => 'Other']],
            'falsePositive' => ['False positive (not a threat)', ['notMalicious' => 'Not malicious', 'notEnoughDataToValidate' => 'Not enough data to validate', 'other' => 'Other']],
        ];
    }
    function itops_so_inc_id(string $id): string {
        if (!preg_match('/^[0-9]{1,20}$/', $id)) throw new InvalidArgumentException('Not an incident id.');
        return $id;
    }
    /** One incident with its alerts and comments. */
    function itops_so_incident(string $id): array {
        $i = itops_graph_get('security/incidents/' . itops_so_inc_id($id) . '?$expand=alerts');
        $alerts = [];
        foreach ((array)($i['alerts'] ?? []) as $a) {
            $who = []; $dev = [];
            foreach ((array)($a['evidence'] ?? []) as $e) {
                if (!empty($e['userAccount']['userPrincipalName'])) $who[] = strtolower((string)$e['userAccount']['userPrincipalName']);
                if (!empty($e['deviceDnsName'])) $dev[] = (string)$e['deviceDnsName'];
                if (!empty($e['mailboxConfigurationId']) || !empty($e['primaryAddress'])) $who[] = strtolower((string)($e['primaryAddress'] ?? ''));
            }
            $alerts[] = ['id' => (string)($a['id'] ?? ''), 'title' => (string)($a['title'] ?? ''), 'severity' => strtolower((string)($a['severity'] ?? '')), 'status' => (string)($a['status'] ?? ''),
                         'category' => (string)($a['category'] ?? ''), 'source' => (string)($a['serviceSource'] ?? ''), 'created' => $a['createdDateTime'] ?? null,
                         'users' => array_values(array_unique(array_filter($who))), 'devices' => array_values(array_unique($dev)), 'url' => (string)($a['alertWebUrl'] ?? '')];
        }
        $com = array_map(fn($c) => ['by' => (string)($c['createdByDisplayName'] ?? ''), 'at' => $c['createdDateTime'] ?? null, 'text' => (string)($c['comment'] ?? '')], (array)($i['comments'] ?? []));
        return ['id' => (string)($i['id'] ?? $id), 'name' => (string)($i['displayName'] ?? ''), 'severity' => strtolower((string)($i['severity'] ?? '')), 'status' => (string)($i['status'] ?? ''),
                'assigned' => (string)($i['assignedTo'] ?? ''), 'classification' => (string)($i['classification'] ?? 'unknown'), 'determination' => (string)($i['determination'] ?? 'unknown'),
                'created' => $i['createdDateTime'] ?? null, 'updated' => $i['lastUpdateDateTime'] ?? null, 'url' => (string)($i['incidentWebUrl'] ?? ''), 'tags' => (array)($i['customTags'] ?? []),
                'alerts' => $alerts, 'comments' => $com];
    }
    /**
     * What to send for a change. $want: assign (address or '' = nobody), status (active | inProgress | resolved),
     * classification, determination, comment (resolving comment). Returns [patch, plain-words summary]. Throws
     * InvalidArgumentException when it does not hold together.
     */
    function itops_so_inc_patch(array $cur, array $want): array {
        $p = []; $say = [];
        if (array_key_exists('assign', $want)) {
            $to = strtolower(trim((string)$want['assign']));
            if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Assign to a sign-in address.');
            if ($to !== strtolower($cur['assigned'] ?? '')) { $p['assignedTo'] = $to; $say[] = $to === '' ? 'unassigned' : 'assigned to ' . $to; }
        }
        $st = $want['status'] ?? null;
        if ($st !== null) {
            if (!in_array($st, ['active', 'inProgress', 'resolved'], true)) throw new InvalidArgumentException('Unknown status.');
            if ($st === 'resolved') {
                $C = itops_so_classes(); $cl = (string)($want['classification'] ?? ''); $de = (string)($want['determination'] ?? '');
                if (!isset($C[$cl])) throw new InvalidArgumentException('Say what it was (classification) before resolving.');
                if (!isset($C[$cl][1][$de])) throw new InvalidArgumentException('Choose a determination that goes with "' . $C[$cl][0] . '".');
                $why = trim((string)($want['comment'] ?? ''));
                if (mb_strlen($why) < 5) throw new InvalidArgumentException('Say what was found and done (a few words); it is kept with the incident.');
                $p += ['status' => 'resolved', 'classification' => $cl, 'determination' => $de, 'resolvingComment' => mb_substr($why, 0, 1000)];
                $say[] = 'resolved as ' . $C[$cl][0] . ' · ' . $C[$cl][1][$de];
            } elseif ($st !== ($cur['status'] ?? '')) { $p['status'] = $st; $say[] = $st === 'inProgress' ? 'in progress' : 'active again'; }
        }
        if (!$p) throw new InvalidArgumentException('Nothing to change.');
        return [$p, implode('; ', $say)];
    }
    function itops_so_inc_send(string $id, array $patch): void { itops_graph_send('PATCH', 'security/incidents/' . itops_so_inc_id($id), $patch); }
    function itops_so_inc_comment(string $id, string $text): void {
        $text = trim($text); if (mb_strlen($text) < 2) throw new InvalidArgumentException('Write the comment.');
        itops_graph_send('POST', 'security/incidents/' . itops_so_inc_id($id) . '/comments', ['@odata.type' => 'microsoft.graph.security.alertComment', 'comment' => mb_substr($text, 0, 1000)]);
    }
    /** A ticket for the incident (once per incident). Returns [ticket number, ticket id, made now]. */
    function itops_so_inc_ticket(PDO $db, array $inc, int $catId, ?int $owner, array $user): array {
        $q = $db->prepare('SELECT l.ticket_id, t.ticket_number FROM itops_m365_incident_tickets l LEFT JOIN itops_tickets t ON t.id=l.ticket_id WHERE l.incident_id=?'); $q->execute([$inc['id']]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return [(string)$r['ticket_number'], (int)$r['ticket_id'], false];
        if (!$catId) throw new InvalidArgumentException('Choose the ticket category.');
        // Review M365B-19: claim the incident first (ticket 0 = being made), so two clicks at once make one ticket
        $cl = $db->prepare('INSERT IGNORE INTO itops_m365_incident_tickets (incident_id, ticket_id, created_by, created_at) VALUES (?,0,?,NOW())'); $cl->execute([$inc['id'], (int)$user['id']]);
        if ($cl->rowCount() === 0) { $q->execute([$inc['id']]); $r = $q->fetch(PDO::FETCH_ASSOC); if ($r && (int)$r['ticket_id'] > 0) return [(string)$r['ticket_number'], (int)$r['ticket_id'], false]; throw new InvalidArgumentException('A ticket for this incident is being made right now. Reload in a moment.'); }
        try {
            require_once __DIR__ . '/TicketNumbers.php';
            $lines = array_map(fn($a) => '- ' . $a['title'] . ' (' . $a['severity'] . ($a['users'] ? ', ' . implode(', ', $a['users']) : '') . ($a['devices'] ? ', ' . implode(', ', $a['devices']) : '') . ')', $inc['alerts']);
            $desc = 'Microsoft Defender incident ' . $inc['id'] . ': ' . $inc['name'] . "\nSeverity: " . $inc['severity'] . "\nStatus: " . $inc['status'] . ($inc['assigned'] ? "\nAssigned in Defender to: " . $inc['assigned'] : '')
                  . ($lines ? "\n\nAlerts:\n" . implode("\n", $lines) : '') . ($inc['url'] ? "\n\nIn Defender: " . $inc['url'] : '');
            $subj = mb_substr('Security incident: ' . $inc['name'], 0, 250); $pri = ['high' => 'p2_high', 'medium' => 'p3_medium'][$inc['severity']] ?? 'p4_low'; $req = (int)$user['id'];
            [$tn, $tid] = itops_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $desc, $catId, $pri, $req) {
                $db->prepare("INSERT INTO itops_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id) VALUES (?,?,?,?,?,'open','incident',?)")
                   ->execute([$tn, $subj, $desc, $catId, $pri, $req]);
                return [$tn, (int)$db->lastInsertId()];
            });
            if (!function_exists('itops_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
            try { itops_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
            try { require_once __DIR__ . '/TicketRouting.php'; itops_tr_on_created($db, $tid, null, 'security_incident'); } catch (Throwable $e) {}
            if ($owner) $db->prepare('UPDATE itops_tickets SET assignee_id=? WHERE id=?')->execute([$owner, $tid]);
        } catch (Throwable $e) { $db->prepare('DELETE FROM itops_m365_incident_tickets WHERE incident_id=? AND ticket_id=0')->execute([$inc['id']]); throw $e; }
        $db->prepare('UPDATE itops_m365_incident_tickets SET ticket_id=? WHERE incident_id=?')->execute([$tid, $inc['id']]);
        if (function_exists('audit_log')) audit_log('ticket_created', 'ticket', $tid, $tn, null, ['from' => 'Microsoft Defender incident ' . $inc['id']]);
        return [$tn, $tid, true];
    }

    /* ─────────── admin roles ─────────── */

    if (!defined('ITOPS_SO_GA')) define('ITOPS_SO_GA', '62e90394-69f5-4237-9190-012177145e10');
    function itops_so_guid(string $s, string $what): string {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) throw new InvalidArgumentException('Not a ' . $what . '.');
        return strtolower($s);
    }
    /** role definition id => [name, high] (built-in and custom). */
    function itops_so_role_defs(): array {
        require_once __DIR__ . '/M365Guard.php';
        $out = [];
        foreach (itops_graph_paged('roleManagement/directory/roleDefinitions?$select=id,displayName,templateId', 3) as $d) {
            $id = strtolower((string)($d['id'] ?? '')); $tpl = strtolower((string)($d['templateId'] ?? $id));
            $out[$id] = [(string)($d['displayName'] ?? $id), itops_m365_role_is_high($tpl, (string)($d['displayName'] ?? ''))];
        }
        return $out;
    }
    /**
     * Every account and group that holds a directory role, permanent or eligible, with what makes it risky.
     * $em: emergency account addresses. Never throws for the optional parts: they say why they are missing.
     */
    function itops_so_admins(array $em): array {
        $defs = itops_so_role_defs(); $em = array_map('strtolower', $em);
        $asg = itops_graph_paged('roleManagement/directory/roleAssignments?$expand=principal&$top=999', 5);
        $elig = []; $eligErr = null;
        try { $elig = itops_graph_paged('roleManagement/directory/roleEligibilityScheduleInstances?$expand=principal&$top=999', 5); }
        catch (Throwable $e) { $eligErr = $e->getMessage(); }
        $people = [];
        $add = function (array $a, string $kind) use (&$people, $defs) {
            $pr = (array)($a['principal'] ?? []); $pid = strtolower((string)($a['principalId'] ?? ($pr['id'] ?? ''))); if ($pid === '') return;
            $rid = strtolower((string)($a['roleDefinitionId'] ?? '')); [$rn, $high] = $defs[$rid] ?? [$rid, false];
            $type = strtolower(str_replace('#microsoft.graph.', '', (string)($pr['@odata.type'] ?? '#microsoft.graph.user')));
            if (!isset($people[$pid])) $people[$pid] = ['id' => $pid, 'type' => $type, 'name' => (string)($pr['displayName'] ?? ''), 'upn' => strtolower((string)($pr['userPrincipalName'] ?? '')), 'roles' => []];
            $people[$pid]['roles'][] = ['role_id' => $rid, 'name' => $rn, 'high' => $high, 'kind' => $kind, 'assignment_id' => $kind === 'permanent' ? (string)($a['id'] ?? '') : null,
                                         'scope' => (string)($a['directoryScopeId'] ?? '/'), 'ga' => $rid === ITOPS_SO_GA || strcasecmp($rn, 'Global Administrator') === 0];
        };
        foreach ($asg as $a) $add($a, 'permanent');
        foreach ($elig as $a) $add($a, 'eligible');
        $signErr = null; $mfaErr = null;
        foreach ($people as $pid => &$p) {
            $p['emergency'] = $p['upn'] !== '' && in_array($p['upn'], $em, true);
            if ($p['type'] !== 'user') { $p['flags'] = []; continue; }
            $u = null;
            try { $u = itops_graph_get('users/' . $pid . '?$select=id,displayName,userPrincipalName,accountEnabled,userType,onPremisesSyncEnabled,assignedLicenses,signInActivity'); }
            catch (Throwable $e) { $signErr = $signErr ?? $e->getMessage(); try { $u = itops_graph_get('users/' . $pid . '?$select=id,displayName,userPrincipalName,accountEnabled,userType,onPremisesSyncEnabled,assignedLicenses'); } catch (Throwable $e2) { $u = null; } }
            $mfa = null;
            try { $r = itops_graph_get('reports/authenticationMethods/userRegistrationDetails/' . $pid); $mfa = !empty($r['isMfaRegistered']); } catch (Throwable $e) { $mfaErr = $mfaErr ?? $e->getMessage(); }
            $last = $u['signInActivity']['lastSignInDateTime'] ?? null; $days = $last ? (int)floor((time() - strtotime((string)$last)) / 86400) : null;
            if ($u) { $p['name'] = (string)($u['displayName'] ?? $p['name']); $p['upn'] = strtolower((string)($u['userPrincipalName'] ?? $p['upn'])); $p['emergency'] = in_array($p['upn'], $em, true); }
            $p += ['enabled' => $u ? !empty($u['accountEnabled']) : null, 'guest' => $u ? (($u['userType'] ?? 'Member') === 'Guest') : null, 'synced' => $u ? !empty($u['onPremisesSyncEnabled']) : null,
                   'licensed' => $u ? count((array)($u['assignedLicenses'] ?? [])) : null, 'last_signin' => $last, 'days_idle' => $days, 'mfa' => $mfa];
            $f = [];
            if ($p['enabled'] === false) $f[] = 'disabled';
            if ($p['guest']) $f[] = 'guest';
            if ($p['synced']) $f[] = 'synced';
            if ($p['licensed'] && !$p['emergency'] && array_filter($p['roles'], fn($r) => $r['high'])) $f[] = 'daily';
            if ($mfa === false) $f[] = 'no_mfa';
            if ($days !== null && $days > 90 && !$p['emergency']) $f[] = 'idle';
            $p['flags'] = $f;
        }
        unset($p);
        $people = array_values($people);
        usort($people, fn($a, $b) => [!array_filter($a['roles'], fn($r) => $r['ga']), !array_filter($a['roles'], fn($r) => $r['high']), strtolower($a['name'])] <=> [!array_filter($b['roles'], fn($r) => $r['ga']), !array_filter($b['roles'], fn($r) => $r['high']), strtolower($b['name'])]);
        $gaPerm = 0; $gaElig = 0;
        foreach ($people as $p) foreach ($p['roles'] as $r) if ($r['ga'] && $p['type'] === 'user') { if ($r['kind'] === 'permanent') $gaPerm++; else $gaElig++; }
        return ['people' => $people, 'ga_permanent' => $gaPerm, 'ga_eligible' => $gaElig, 'eligible_error' => $eligErr, 'signin_error' => $signErr, 'mfa_error' => $mfaErr];
    }
    /** One permanent assignment, read again from Microsoft. */
    function itops_so_assignment(string $id): array {
        if (!preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $id)) throw new InvalidArgumentException('Not a role assignment id.');
        try { $a = itops_graph_get('roleManagement/directory/roleAssignments/' . $id . '?$expand=principal'); }
        catch (RuntimeException $e) { if (str_contains($e->getMessage(), '404')) throw new InvalidArgumentException('That role assignment no longer exists (refresh the page).'); throw $e; }
        return $a;
    }
    /** How many users hold Global Administrator permanently. BT124 (review SEC-10): accounts that can sign in only. */
    function itops_so_ga_permanent(): int {
        $n = 0;
        foreach (itops_graph_paged("roleManagement/directory/roleAssignments?\$filter=" . rawurlencode("roleDefinitionId eq '" . ITOPS_SO_GA . "'") . '&$expand=principal', 3) as $a) {
            if (strtolower(str_replace('#microsoft.graph.', '', (string)($a['principal']['@odata.type'] ?? '#microsoft.graph.user'))) !== 'user') continue;
            $on = $a['principal']['accountEnabled'] ?? null;
            if ($on === null) { try { $on = !empty(itops_graph_get('users/' . rawurlencode((string)($a['principalId'] ?? '')) . '?$select=accountEnabled')['accountEnabled']); } catch (Throwable $e) { $on = false; } }
            if ($on) $n++;
        }
        return $n;
    }
    function itops_so_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_m365_role_changes LIMIT 1'); $db->query('SELECT 1 FROM itops_m365_incident_tickets LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
    function itops_so_role_label(string $kind, array $p): string {
        $who = ($p['who'] ?? '') ?: '?'; $role = ($p['role'] ?? '') ?: '?';
        if ($kind === 'role_remove') return 'Remove "' . $role . '" from ' . $who;
        if ($kind === 'role_eligible') return 'Make "' . $role . '" eligible (asked for when needed) for ' . $who;
        if ($kind === 'role_restore') return 'Put back role change #' . (int)($p['change_id'] ?? 0) . ' (' . $role . ', ' . $who . ')';
        return $kind;
    }
    /** Fill who / role from Microsoft (never from the browser) for a request. Throws InvalidArgumentException. */
    function itops_so_role_describe(PDO $db, string $kind, array $p): array {
        if ($kind === 'role_restore') {
            $q = $db->prepare('SELECT * FROM itops_m365_role_changes WHERE id=?'); $q->execute([(int)($p['change_id'] ?? 0)]); $c = $q->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new InvalidArgumentException('No such change.');
            if ($c['undone_at']) throw new InvalidArgumentException('That change was already put back.');
            return ['change_id' => (int)$c['id'], 'who' => $c['principal_name'], 'role' => $c['role_name'], 'role_id' => strtolower((string)$c['role_id']), 'principal_id' => strtolower((string)$c['principal_id'])];
        }
        $a = itops_so_assignment((string)($p['assignment_id'] ?? ''));
        $defs = itops_so_role_defs(); $rid = strtolower((string)($a['roleDefinitionId'] ?? ''));
        $pr = (array)($a['principal'] ?? []); $type = strtolower(str_replace('#microsoft.graph.', '', (string)($pr['@odata.type'] ?? '#microsoft.graph.user')));
        if ($type !== 'user') throw new InvalidArgumentException('This role is held by a ' . $type . ', not a person: change it in the Entra admin center.');
        return ['assignment_id' => (string)$a['id'], 'who' => (string)($pr['userPrincipalName'] ?? ($pr['displayName'] ?? '')), 'role' => $defs[$rid][0] ?? $rid, 'role_id' => $rid, 'principal_id' => strtolower((string)($a['principalId'] ?? ''))];
    }
    /** Carry out a role change. Throws InvalidArgumentException (refused, nothing changed) or RuntimeException (Microsoft refused). */
    function itops_so_role_exec(PDO $db, string $kind, array $p, int $by, array $em, ?int $req = null): array {
        $em = array_map('strtolower', $em); $app = function_exists('itops_app_name') ? itops_app_name() : 'ITOps';
        $rec = function (string $action, array $a, ?string $elig, int $reqv = 0) use ($db, $by, $req): int {
            $db->prepare('INSERT INTO itops_m365_role_changes (action, principal_id, principal_name, role_id, role_name, scope, assignment_id, eligibility, by_user, at, request_id) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)')
               ->execute([$action, $a['principal_id'], mb_substr($a['who'], 0, 255), $a['role_id'], mb_substr($a['role'], 0, 255), $a['scope'], $a['assignment_id'] ?? null, $elig, $by, $req]);
            return (int)$db->lastInsertId();
        };
        if ($kind === 'role_remove' || $kind === 'role_eligible') {
            $a = itops_so_assignment((string)($p['assignment_id'] ?? ''));
            $pr = (array)($a['principal'] ?? []); $type = strtolower(str_replace('#microsoft.graph.', '', (string)($pr['@odata.type'] ?? '#microsoft.graph.user')));
            if ($type !== 'user') throw new InvalidArgumentException('This role is held by a ' . $type . ', not a person: change it in the Entra admin center.');
            $rid = strtolower((string)$a['roleDefinitionId']); $defs = itops_so_role_defs();
            $info = ['principal_id' => strtolower((string)$a['principalId']), 'who' => strtolower((string)($pr['userPrincipalName'] ?? '')), 'role_id' => $rid, 'role' => $defs[$rid][0] ?? $rid,
                     'scope' => (string)($a['directoryScopeId'] ?? '/'), 'assignment_id' => (string)$a['id']];
            $ga = $rid === ITOPS_SO_GA;
            if ($ga && in_array($info['who'], $em, true)) throw new InvalidArgumentException($info['who'] . ' is an emergency access account: it keeps Global Administrator, permanently.');
            // BT124 (review SEC-10): one Global Administrator change at a time, so two at once cannot both pass the count
            if ($ga && (int)$db->query("SELECT GET_LOCK('itops_ga_change', 30)")->fetchColumn() !== 1) throw new RuntimeException('Another Global Administrator change is running. Try again in a minute.');
            if ($ga && itops_so_ga_permanent() <= 2) { $db->query("SELECT RELEASE_LOCK('itops_ga_change')"); throw new InvalidArgumentException('At least two people who can sign in must stay Global Administrator permanently (the emergency access accounts count). Add another first.'); }
            if ($ga) register_shutdown_function(function () use ($db) { try { $db->query("SELECT RELEASE_LOCK('itops_ga_change')"); } catch (Throwable $e) {} });
            if ($kind === 'role_remove') {
                itops_graph_send('DELETE', 'roleManagement/directory/roleAssignments/' . $info['assignment_id']);
                $cid = $rec('role_remove', $info, null);
                return ['change_id' => $cid, 'name' => $info['who'] . ' · ' . $info['role'], 'summary' => 'Removed "' . $info['role'] . '" from ' . $info['who']];
            }
            $r = itops_graph_send('POST', 'roleManagement/directory/roleEligibilityScheduleRequests', ['action' => 'adminAssign', 'justification' => 'Made eligible instead of permanent, from ' . $app,
                'roleDefinitionId' => $rid, 'directoryScopeId' => $info['scope'], 'principalId' => $info['principal_id'],
                'scheduleInfo' => ['startDateTime' => gmdate('Y-m-d\TH:i:s\Z'), 'expiration' => ['type' => 'noExpiration']]]);
            try { itops_graph_send('DELETE', 'roleManagement/directory/roleAssignments/' . $info['assignment_id']); }
            catch (Throwable $e) {   // keep it as it was: the eligible copy goes again
                try { itops_graph_send('POST', 'roleManagement/directory/roleEligibilityScheduleRequests', ['action' => 'adminRemove', 'justification' => 'Undo: the permanent role could not be removed', 'roleDefinitionId' => $rid, 'directoryScopeId' => $info['scope'], 'principalId' => $info['principal_id']]); } catch (Throwable $z) {}
                throw new RuntimeException('the permanent role could not be removed, so the eligible one was taken back and nothing changed (' . $e->getMessage() . ')');
            }
            $cid = $rec('role_eligible', $info, (string)($r['id'] ?? ''));
            return ['change_id' => $cid, 'name' => $info['who'] . ' · ' . $info['role'], 'summary' => '"' . $info['role'] . '" is now eligible for ' . $info['who'] . ' (activated in Entra when needed)'];
        }
        if ($kind === 'role_restore') {
            $q = $db->prepare('SELECT * FROM itops_m365_role_changes WHERE id=?'); $q->execute([(int)($p['change_id'] ?? 0)]); $c = $q->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new InvalidArgumentException('No such change.');
            if ($c['undone_at']) throw new InvalidArgumentException('That change was already put back.');
            if (!in_array($c['action'], ['role_remove', 'role_eligible'], true)) throw new InvalidArgumentException('That change cannot be put back.');
            $j = itops_graph_send('POST', 'roleManagement/directory/roleAssignments', ['principalId' => $c['principal_id'], 'roleDefinitionId' => $c['role_id'], 'directoryScopeId' => $c['scope'] ?: '/']);
            $note = 'the role is permanent again';
            if ($c['action'] === 'role_eligible') {
                try { itops_graph_send('POST', 'roleManagement/directory/roleEligibilityScheduleRequests', ['action' => 'adminRemove', 'justification' => 'Put back from ' . $app, 'roleDefinitionId' => $c['role_id'], 'directoryScopeId' => $c['scope'] ?: '/', 'principalId' => $c['principal_id']]); $note .= ' and no longer eligible'; }
                catch (Throwable $e) { $note .= '; the eligible copy could not be removed (' . $e->getMessage() . '): remove it in Entra'; }
            }
            $db->prepare('UPDATE itops_m365_role_changes SET undone_at=NOW(), undone_by=? WHERE id=?')->execute([$by, (int)$c['id']]);
            $cid = $rec('role_restore', ['principal_id' => $c['principal_id'], 'who' => $c['principal_name'], 'role_id' => $c['role_id'], 'role' => $c['role_name'], 'scope' => $c['scope'], 'assignment_id' => (string)($j['id'] ?? '')], null);
            return ['change_id' => $cid, 'name' => $c['principal_name'] . ' · ' . $c['role_name'], 'summary' => 'Put back: "' . $c['role_name'] . '" for ' . $c['principal_name'] . ' (' . $note . ')'];
        }
        throw new InvalidArgumentException('Unknown change.');
    }
}
