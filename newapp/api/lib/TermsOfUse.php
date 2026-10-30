<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Terms of Use (BT97).
 *
 * Every person who signs in accepts the Terms once ("I agree" / "Sign out"). The organization's acceptance is given by a
 * person whose role has "Terms of Use · accept for the organization" (BT98: module terms_org; Super Admin always, nobody
 * else until a Super Admin ticks it on a role). Continued use is acceptance too (term 1.2), but the click is the evidence: each acceptance is a
 * row in opsh_terms_acceptances and an Audit Log entry (user, version, date and time, address).
 *
 * The text and its version are fixed here: nothing in the app can change them. A material change gets a new
 * OPSH_TOU_VERSION, and everyone accepts again at their next sign-in. BT98: the Terms name only the organization (this
 * installation's own name) and the product; the owner is named only in term 6 (Independent developer). The effective date
 * is this installation's start (installed_at, else its first Audit Log entry).
 *
 * Until the database update of BT97 has run (no acceptance table yet) nobody is asked, so an upload never locks people out.
 */
if (!defined('OPSH_TOU_VERSION')) {
    define('OPSH_TOU_VERSION', '1.0');
}
require_once __DIR__ . '/Brand.php';

if (!function_exists('opsh_tou')) {
    /** BT98: the date these Terms take effect on this installation — when it was installed, else its first Audit Log entry. */
    function opsh_tou_effective(PDO $db): string {
        try {
            $v = (string)$db->query("SELECT sval FROM opsh_settings WHERE skey='installed_at'")->fetchColumn();
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
            $v = (string)$db->query("SELECT MIN(created_at) FROM opsh_audit_log")->fetchColumn();
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
        } catch (Throwable $e) {}
        return date('Y-m-d');
    }

    /** The Terms: [h => heading, p => [paragraphs], items => [list]] per term. The organization is this installation's own name. */
    function opsh_tou(PDO $db): array {
        $P = OPSH_PRODUCT; $org = opsh_org_name($db);
        $S = [];
        $S[] = ['h' => '1. Acceptance', 'p' => [
            '1.1 These Terms of Use ("Terms") are an agreement between ' . OPSH_OWNER . ', the owner of ' . $P . ' (the "Owner"), and ' . ($org !== '' ? $org : 'the organization that installs or uses ' . $P) . ' (the "Organization"). They also apply to each person who signs in (a "User").',
            '1.2 By selecting "I agree", or by installing, accessing or continuing to use ' . $P . ', you accept these Terms. If you do not accept them, do not use ' . $P . '.',
            '1.3 You may not use ' . $P . ' if you cannot form a binding contract, or if the law forbids you to receive it.']];
        $S[] = ['h' => '2. Who is bound', 'p' => [
            '2.1 The person who installs, activates or first sets up ' . $P . ' for an Organization confirms that they have authority to accept these Terms on its behalf, and accepts them for the Organization. Use of ' . $P . ' by the Organization\'s personnel is also acceptance by the Organization.',
            '2.2 Each User accepts these Terms for their own use, and also follows the Organization\'s own policies.',
            '2.3 If the Organization and the Owner have signed a Software License Agreement or another written agreement for ' . $P . ', that agreement prevails where it differs from these Terms.']];
        $S[] = ['h' => '3. License', 'p' => [
            '3.1 The Owner grants the Organization a limited, non-exclusive, non-transferable, non-sublicensable, revocable license to install and use ' . $P . ' on one installation, for the Organization\'s internal business, by its own personnel.',
            '3.2 Unless the Owner states a fee in writing, the license is free of charge. A free license may be limited, changed or ended under term 12.',
            '3.3 All rights not expressly granted are reserved by the Owner.']];
        $S[] = ['h' => '4. What is not allowed', 'p' => ['Unless the Owner allows it in writing, no one may:'], 'items' => [
            'copy ' . $P . ' beyond the licensed installation and its backups;',
            'distribute, sell, sublicense, rent, lend or host it for others, or use it to provide services to third parties;',
            'modify it or create derivative works, other than through its own settings and branding;',
            'reverse engineer, decompile or disassemble it, except where the law allows this despite this term;',
            'remove, hide or change copyright lines, ownership information, these Terms or license notices;',
            'use it to build a competing product, or disclose its source code, design or database structure;',
            'use it unlawfully, to infringe anyone\'s rights, or to try to get around its security or permissions.']];
        $S[] = ['h' => '5. Ownership', 'p' => [
            '5.1 ' . $P . ' is proprietary software. It is licensed, not sold. The Owner owns all rights in it: source and object code, database structure, screens, design, documentation and Help content, and every update, module and customization.',
            '5.2 Using, testing, suggesting or requesting features of ' . $P . ', or being the first or only organization to use a version or module, gives no Organization or User any ownership or co-authorship in ' . $P . ' or in what is made from it. Ideas and feedback may be used by the Owner freely and without payment.',
            '5.3 The Organization owns its own data (term 7) and its own names and logos entered through branding.']];
        $S[] = ['h' => '6. Independent developer', 'p' => [
            '6.1 ' . $P . ' was conceived, designed and developed by ' . OPSH_OWNER . ' as an independent software developer, and not as an employee, contractor or consultant of any company or organization. It was not commissioned by, and is not a work made for hire for, any Organization that uses it.',
            '6.2 The Owner is an independent software vendor. These Terms create no employment, partnership, joint-venture or agency relationship. Any other work the Owner does for an Organization is separate from these Terms and transfers no right in ' . $P . '.']];
        $S[] = ['h' => '7. Your data', 'p' => [
            '7.1 The Organization owns the data entered into or connected to ' . $P . ' and is responsible for it: its accuracy, its lawful use, who may see it, backups, and the security of the server it runs on.',
            '7.2 The Owner accesses the Organization\'s data only when the Organization asks for installation or support, only as far as that work needs, and keeps it confidential. If the data is health, rehabilitation or other regulated information, any data-protection agreement the law requires is signed before the Owner accesses it.',
            '7.3 ' . $P . ' records actions in an audit log, including acceptance of these Terms, so the Organization can review use of its installation.']];
        $S[] = ['h' => '8. Third-party components and services', 'p' => [
            '8.1 ' . $P . ' includes open-source components under their own licenses, listed in THIRD-PARTY-NOTICES.txt. Those licenses apply to those components.',
            '8.2 ' . $P . ' can connect to services the Organization subscribes to separately (for example Microsoft 365 or vTiger). Their providers\' terms govern them, and the Owner is not responsible for them. Their names only identify what ' . $P . ' connects to and imply no affiliation or endorsement.']];
        $S[] = ['h' => '9. No warranty', 'p' => [
            strtoupper($P) . ' IS PROVIDED "AS IS" AND "AS AVAILABLE", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. THE OWNER DOES NOT WARRANT THAT IT WILL BE ERROR-FREE OR UNINTERRUPTED, OR THAT IT MEETS ANY LEGAL, REGULATORY, CONTRACT OR FUNDING-AGENCY REQUIREMENT. CHECKS, RULES AND REPORTS IN ' . strtoupper($P) . ' ASSIST YOU, BUT YOU REMAIN RESPONSIBLE FOR YOUR OWN DECISIONS AND REVIEW.']];
        $S[] = ['h' => '10. Limitation of liability', 'p' => [
            '10.1 TO THE EXTENT THE LAW ALLOWS, THE OWNER IS NOT LIABLE FOR INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, REVENUE OR DATA.',
            '10.2 THE OWNER\'S TOTAL LIABILITY UNDER THESE TERMS IS LIMITED TO THE GREATER OF THE AMOUNT THE ORGANIZATION PAID FOR ' . strtoupper($P) . ' IN THE 12 MONTHS BEFORE THE CLAIM AND US$100.']];
        $S[] = ['h' => '11. Indemnity', 'p' => [
            'The Organization will defend and hold the Owner harmless from third-party claims arising from its data, its use of connected services, or its use of ' . $P . ' in breach of these Terms or the law.']];
        $S[] = ['h' => '12. Changes, suspension and termination', 'p' => [
            '12.1 The Owner may update these Terms. A material change is shown in ' . $P . ' and must be accepted again before use continues. Other changes take effect 30 days after notice in ' . $P . ', and continued use after that is acceptance.',
            '12.2 The Owner may change, suspend or end a free license with 30 days\' notice, or at once if these Terms are breached.',
            '12.3 The Organization may stop using ' . $P . ' at any time. When the license ends, the Organization stops using ' . $P . ' and removes it. The Owner will tell the Organization how to export its data before removal.',
            '12.4 Terms 4, 5, 6, 7, 9, 10, 11, 13 and 14 survive the end of the license.']];
        $S[] = ['h' => '13. Assignment', 'p' => [
            'The Organization may not transfer these Terms or its license without the Owner\'s written consent. The Owner may transfer them, and may sell or transfer ' . $P . ', to a successor or buyer, who will then update the ownership information shown in ' . $P . '.']];
        $S[] = ['h' => '14. Law and disputes', 'p' => [
            'These Terms are governed by the laws of the State of Florida, without regard to conflict-of-laws rules. Disputes go to the state and federal courts in Florida. Either party may seek an injunction anywhere to protect its intellectual property or confidential information.']];
        $S[] = ['h' => '15. General and contact', 'p' => [
            'These Terms, with any signed agreement under term 2.3, are the entire agreement on their subject. If a term is unenforceable, the rest stays in force. Not enforcing a term is not a waiver of it. Questions about these Terms go to the Organization\'s administrator, who can reach the Owner.']];
        return [
            'product' => $P, 'title' => $P . ' Terms of Use', 'version' => OPSH_TOU_VERSION, 'effective' => opsh_tou_effective($db),
            'organization' => $org, 'sections' => $S,
        ];
    }

    function opsh_tou_table(PDO $db): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $q = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opsh_terms_acceptances'"); $ok = (int)$q->fetchColumn() > 0; }
        catch (Throwable $e) { $ok = false; }
        return $ok;
    }

    /** Who may accept for the organization (BT98): the permission "Terms of Use · accept for the organization"
     *  (module terms_org, edit). Super Admin always; no other role has it until a Super Admin ticks it. */
    function opsh_tou_can_org(array $user): bool {
        return ($user['role_slug'] ?? '') === 'super_admin' || opsh_can($user, 'terms_org', 'edit');
    }

    /** BT98: who has accepted the current Terms (for the people who manage users or may accept for the organization). */
    function opsh_tou_acceptances(PDO $db): array {
        if (!opsh_tou_table($db)) return [];
        try {
            $q = $db->prepare("SELECT a.user_id, u.display_name, u.email, r.name AS role_name, a.scope, a.org_name, a.accepted_at FROM opsh_terms_acceptances a
                               LEFT JOIN opsh_users u ON u.id=a.user_id LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE a.version=? ORDER BY a.accepted_at DESC, a.id DESC LIMIT 2000");
            $q->execute([OPSH_TOU_VERSION]);
            return array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => (string)$r['display_name'], 'email' => (string)$r['email'], 'role' => (string)$r['role_name'],
                                        'for_org' => $r['scope'] === 'org', 'org_name' => (string)$r['org_name'], 'at' => (string)$r['accepted_at']], $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { return []; }
    }
    /** BT98: the active people who still have to accept the current Terms. */
    function opsh_tou_pending_count(PDO $db): ?int {
        if (!opsh_tou_table($db)) return null;
        try { $q = $db->prepare("SELECT COUNT(*) FROM opsh_users u WHERE u.status='active' AND NOT EXISTS (SELECT 1 FROM opsh_terms_acceptances a WHERE a.user_id=u.id AND a.version=? AND a.scope='user')");
              $q->execute([OPSH_TOU_VERSION]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return null; }
    }

    /** Where this person stands with the current Terms. API keys are never asked (no person behind them). */
    function opsh_tou_status(PDO $db, ?array $user): array {
        $st = ['version' => OPSH_TOU_VERSION, 'required' => false, 'accepted_at' => null, 'org_accepted' => null, 'org_needed' => false, 'org_name' => opsh_org_name($db), 'can_org' => false];
        if (!$user || empty($user['id']) || !empty($user['api_key_id']) || !opsh_tou_table($db)) return $st;
        try {
            $q = $db->prepare("SELECT accepted_at FROM opsh_terms_acceptances WHERE user_id=? AND version=? AND scope='user' ORDER BY id DESC LIMIT 1");
            $q->execute([(int)$user['id'], OPSH_TOU_VERSION]); $mine = $q->fetchColumn();
            $q = $db->prepare("SELECT a.accepted_at, a.org_name, u.display_name FROM opsh_terms_acceptances a LEFT JOIN opsh_users u ON u.id=a.user_id WHERE a.version=? AND a.scope='org' ORDER BY a.id LIMIT 1");
            $q->execute([OPSH_TOU_VERSION]); $org = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return $st; }
        $st['accepted_at'] = $mine ?: null;
        $st['org_accepted'] = $org ? ['at' => $org['accepted_at'], 'by' => (string)($org['display_name'] ?? ''), 'org_name' => (string)$org['org_name']] : null;
        $st['can_org'] = opsh_tou_can_org($user);
        $st['org_needed'] = !$org && $st['can_org'];
        $st['required'] = !$mine || $st['org_needed'];
        return $st;
    }
    function opsh_tou_required(PDO $db, ?array $user): bool { return opsh_tou_status($db, $user)['required']; }

    /** Record an acceptance (and the organization's, when this person may give it and it is still missing). */
    function opsh_tou_accept(PDO $db, array $user, bool $forOrg, string $orgName): array {
        if (!opsh_tou_table($db)) error_response('The database update of this build has not run yet (Settings → Maintenance → Apply updates now).', 409);
        $st = opsh_tou_status($db, $user);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ins = $db->prepare("INSERT INTO opsh_terms_acceptances (user_id, version, scope, org_name, ip_address, user_agent, accepted_at) VALUES (?,?,?,?,?,?,NOW())");
        if ($st['org_needed']) {
            if (!$forOrg) error_response('Tick the box to accept the Terms for your organization first.', 400);
            $set = opsh_org_name($db);
            $orgName = $set !== '' ? $set : trim(preg_replace('/\s+/u', ' ', $orgName));   // BT98: an organization already named keeps its name
            if ($orgName === '') error_response('Type the name of your organization.', 400);
            if (mb_strlen($orgName) > 120) error_response('The organization name is longer than 120 characters.', 400);
            $ins->execute([(int)$user['id'], OPSH_TOU_VERSION, 'org', $orgName, $ip, $ua]);
            if (function_exists('audit_log')) audit_log('terms_accept_org', 'terms', (int)$user['id'], OPSH_TOU_VERSION, null, ['version' => OPSH_TOU_VERSION, 'organization' => $orgName]);
            // The organization's name feeds About & ownership → Licensed to, when it is not set yet.
            try { if (opsh_org_name($db) === '') $db->prepare("INSERT INTO opsh_settings (skey, sval) VALUES ('org_name', ?) ON DUPLICATE KEY UPDATE sval=IF(sval='' OR sval IS NULL, VALUES(sval), sval)")->execute([$orgName]); } catch (Throwable $e) {}
        }
        if (!$st['accepted_at']) {
            $ins->execute([(int)$user['id'], OPSH_TOU_VERSION, 'user', null, $ip, $ua]);
            if (function_exists('audit_log')) audit_log('terms_accept', 'terms', (int)$user['id'], OPSH_TOU_VERSION, null, ['version' => OPSH_TOU_VERSION]);
        }
        return opsh_tou_status($db, $user);
    }
}
