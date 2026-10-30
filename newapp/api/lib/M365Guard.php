<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT88 (code audit H-10, M-31): one admin-account guard for the Microsoft 365 hub and the admin area.
 *
 * Taking over an account — reset its password or MFA, issue a Temporary Access Pass, disable it, sign it out, offboard it —
 * is Super Admin only when the account holds a Microsoft 365 admin role in any way: assigned directly, through a group
 * (role-assignable groups), or eligible through Privileged Identity Management (PIM). When the roles cannot be read, the
 * answer is "a Super Admin must do this" (fails closed). Assigning or removing the high-risk directory roles, and changing
 * the members or owners of a role-assignable group, are Super Admin only too.
 */
if (!function_exists('itops_m365_admin_roles')) {
    /** Built-in roles that can take over the tenant or other accounts (role template ids). */
    function itops_m365_high_roles(): array {
        return [
            '62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator', 'e8611ab8-c189-46e8-94e1-60213ab1f814' => 'Privileged Role Administrator',
            '7be44c8a-adaf-4e2a-84d6-ab2649e08a13' => 'Privileged Authentication Administrator', '194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
            'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator', 'c4e39bd9-1100-46d3-8c65-fb160da0071f' => 'Authentication Administrator',
            '9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator', '158c047a-c907-4556-b7ef-446551a6b5f7' => 'Cloud Application Administrator',
            'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9' => 'Conditional Access Administrator', '29232cdf-9323-42fd-ade2-1d097af3e4de' => 'Exchange Administrator',
            'f28a1f50-f6e7-4571-818b-6a12f2af6b6c' => 'SharePoint Administrator', '3a2c62db-5318-420d-8d74-23affee5d9d5' => 'Intune Administrator',
            '729827e3-9c14-49f7-bb1b-9608f156bbb8' => 'Helpdesk Administrator', '966707d0-3269-4727-9be2-8c3a10f19b9d' => 'Password Administrator',
            'fdd7a751-b60b-444a-984c-02652fe8fa1c' => 'Groups Administrator', '8ac3fc64-6eca-42ea-9e69-59f4c7b60eb2' => 'Hybrid Identity Administrator',
            'be2f45a1-457d-42af-a067-6ec1fa63bc45' => 'External Identity Provider Administrator', 'e00e864a-17c5-4a4b-9c06-f5b95a8d5bd8' => 'Partner Tier2 Support',
            '4ba39ca4-527c-499a-b93d-d9b492c50246' => 'Partner Tier1 Support', 'd29b2b05-8046-44ba-8758-1e26182fcf32' => 'Directory Synchronization Accounts',
        ];
    }
    function itops_m365_role_is_high(string $roleId, string $name = ''): bool {
        $h = itops_m365_high_roles();
        if (isset($h[strtolower($roleId)])) return true;
        foreach ($h as $n) if ($name !== '' && strcasecmp($n, $name) === 0) return true;
        return false;
    }
    /**
     * The admin roles an account holds — directly, through a group, or eligible in PIM. Throws RuntimeException with a
     * sentence when they cannot be read (the caller refuses).
     */
    function itops_m365_admin_roles(string $idOrUpn): array {
        if (!function_exists('itops_graph_get')) require_once __DIR__ . '/Graph.php';
        $seg = rawurlencode(trim($idOrUpn));
        if ($seg === '') throw new RuntimeException('No account given.');
        $names = [];
        try {
            $u = itops_graph_get('users/' . $seg . '?$select=id');
            $oid = (string)($u['id'] ?? '');
            foreach ((itops_graph_get('users/' . $seg . '/transitiveMemberOf/microsoft.graph.directoryRole?$select=displayName')['value'] ?? []) as $r) $names[] = (string)($r['displayName'] ?? 'an admin role');
        } catch (\Throwable $e) { throw new RuntimeException('Could not read the Microsoft 365 roles of this account (' . $e->getMessage() . ').'); }
        if ($oid !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $oid)) {
            $f = rawurlencode("principalId eq '" . $oid . "'");
            try { foreach ((itops_graph_get('roleManagement/directory/roleAssignments?$filter=' . $f . '&$expand=roleDefinition')['value'] ?? []) as $a) $names[] = (string)($a['roleDefinition']['displayName'] ?? 'an admin role'); }
            catch (\Throwable $e) { throw new RuntimeException('Could not read the role assignments of this account (' . $e->getMessage() . ').'); }
            try { foreach ((itops_graph_get('roleManagement/directory/roleEligibilitySchedules?$filter=' . $f . '&$expand=roleDefinition')['value'] ?? []) as $a) $names[] = (string)($a['roleDefinition']['displayName'] ?? 'an admin role') . ' (eligible, PIM)'; }
            catch (\Throwable $e) {
                // Without Entra ID P2 there is no PIM: nothing can be eligible. Any other error: we cannot tell.
                if (!preg_match('/P2|premium|license|licen[cs]e|not.?supported|AadPremium/i', $e->getMessage())) throw new RuntimeException('Could not read the PIM (eligible) roles of this account (' . $e->getMessage() . ').');
            }
        }
        return array_values(array_unique(array_filter($names)));
    }
    /** The guard: a Super Admin always passes; anyone else only for an account with no admin role (logged when refused). */
    function itops_m365_guard_target(array $currentUser, string $idOrUpn, string $what, bool $returnsSecret = false): void {
        if ($returnsSecret && !empty($currentUser['is_api_key'])) error_response('API keys cannot reset passwords or issue temporary passes', 403);
        if (($currentUser['role_slug'] ?? '') === 'super_admin') return;
        $refuse = function (string $why) use ($idOrUpn, $what) {
            if (function_exists('audit_log')) { try { audit_log('m365_admin_target_refused', 'm365', null, mb_substr($idOrUpn, 0, 200), null, ['wanted' => $what, 'reason' => $why]); } catch (\Throwable $e) {} }
            error_response($why, 403);
        };
        try { $roles = itops_m365_admin_roles($idOrUpn); }
        catch (\Throwable $e) { $refuse($e->getMessage() . ' A Super Admin must do this.'); return; }
        if ($roles) $refuse('This account holds a Microsoft 365 admin role (' . implode(', ', array_slice($roles, 0, 3)) . ') — only a Super Admin can ' . $what . '.');
    }
}
