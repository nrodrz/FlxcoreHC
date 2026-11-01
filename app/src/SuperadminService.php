<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Platform (SaaS operator) administration — an explicit, audited CROSS-TENANT lane.
 *
 * SECURITY BOUNDARY: this service manages *accounts*, not customers' data. It reads
 * tenant/user records and aggregate COUNTS only, and NEVER selects or decrypts any
 * encrypted client column (npi/mbi/invitee/etc.). Tenant isolation for real records
 * is unchanged — there is deliberately no method here to read another tenant's
 * provider records or documents.
 *
 * Every method requires a caller who is a platform admin; the router enforces that
 * before dispatching, and each mutating action is written to the audit log.
 */
final class SuperadminService
{
    public function __construct(private readonly TenantContext $ctx)
    {
        if (!$ctx->isPlatformAdmin) {
            throw new \RuntimeException('Platform administrator access required.');
        }
    }

    /** Instance-wide metrics (counts only). */
    public function metrics(): array
    {
        $pdo = Database::pdo();
        $one = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
        return [
            'tenants'        => $one("SELECT COUNT(*) FROM tenants"),
            'tenants_active' => $one("SELECT COUNT(*) FROM tenants WHERE status = 'active'"),
            'tenants_suspended' => $one("SELECT COUNT(*) FROM tenants WHERE status <> 'active'"),
            'users'          => $one("SELECT COUNT(*) FROM users"),
            'admins'         => $one("SELECT COUNT(*) FROM users WHERE platform_admin = 1"),
            'holders'        => $one("SELECT COUNT(*) FROM credential_holders WHERE deleted_at IS NULL"),
            'new_30d'        => $one("SELECT COUNT(*) FROM tenants WHERE created_at >= (NOW() - INTERVAL 30 DAY)"),
        ];
    }

    /**
     * SaaS revenue & subscription overview across all tenants. Money is derived from
     * each tenant\'s plan price; access state uses the same rules as the app lock.
     */
    public function billingOverview(): array
    {
        $pdo = Database::pdo();
        $tenants = $pdo->query("SELECT id, name, billing_status, plan_id, current_period_end, past_due_since, created_at FROM tenants")->fetchAll();
        $plans   = $pdo->query("SELECT * FROM plans")->fetchAll();
        $byId = []; $default = null;
        foreach ($plans as $pl) { $byId[$pl['id']] = $pl; if ($pl['is_default']) $default = $pl; }

        $status = ['active'=>0,'trialing'=>0,'past_due'=>0,'canceled'=>0,'none'=>0];
        $mrr = 0.0; $perPlan = []; $trialsEnding = []; $pastDue = []; $lockedCount = 0;
        $now = time();
        foreach ($tenants as $t) {
            $st = $t['billing_status'] ?: 'none';
            $status[$st] = ($status[$st] ?? 0) + 1;
            $plan = ($t['plan_id'] && isset($byId[$t['plan_id']])) ? $byId[$t['plan_id']] : $default;
            $price = $plan ? (float) ($plan['price_monthly'] ?? 0) : 0.0;
            $pname = $plan['name'] ?? '—';
            if (!isset($perPlan[$pname])) $perPlan[$pname] = ['tenants'=>0,'mrr'=>0.0];
            $perPlan[$pname]['tenants']++;
            if ($st === 'active') { $mrr += $price; $perPlan[$pname]['mrr'] += $price; }
            if (BillingService::state($t)['locked']) $lockedCount++;
            if ($st === 'trialing' && !empty($t['current_period_end'])) {
                $days = (int) ceil((strtotime($t['current_period_end']) - $now) / 86400);
                if ($days >= 0 && $days <= 7) $trialsEnding[] = ['name'=>$t['name'],'id'=>$t['id'],'days'=>$days];
            }
            if ($st === 'past_due') $pastDue[] = ['name'=>$t['name'],'id'=>$t['id'],'since'=>$t['past_due_since']];
        }
        usort($trialsEnding, fn($a,$b) => $a['days'] <=> $b['days']);
        return [
            'mrr' => $mrr, 'arr' => $mrr * 12, 'status' => $status,
            'per_plan' => $perPlan, 'trials_ending' => $trialsEnding,
            'past_due' => $pastDue, 'locked' => $lockedCount,
            'paying' => $status['active'],
        ];
    }

    /** All tenants with account-level aggregates (no client data). */
    public function tenants(?string $q = null, ?string $status = null): array
    {
        $where = []; $args = [];
        if ($q !== null && trim($q) !== '') { $where[] = "t.name LIKE ?"; $args[] = '%' . trim($q) . '%'; }
        if ($status !== null && $status !== '') { $where[] = "t.status = ?"; $args[] = $status; }
        $sql = "SELECT t.id, t.name, t.vertical, t.org_shape, t.plan_tier, t.status, t.status_reason,
                       t.approved_at, t.created_at, t.parent_tenant_id,
                       (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users,
                       (SELECT COUNT(*) FROM credential_holders h WHERE h.tenant_id = t.id AND h.deleted_at IS NULL) AS holders,
                       (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.tenant_id = t.id) AS last_activity
                FROM tenants t"
             . ($where ? " WHERE " . implode(" AND ", $where) : "")
             . " ORDER BY FIELD(t.status,'pending','active','suspended','rejected','closed'), t.created_at DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** The approvals queue: organizations awaiting review, oldest first. */
    public function pendingTenants(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT t.*, (SELECT u.email FROM users u WHERE u.tenant_id = t.id AND u.role = 'tenant_owner'
                          ORDER BY u.created_at ASC LIMIT 1) AS owner_email,
                    (SELECT u.full_name FROM users u WHERE u.tenant_id = t.id AND u.role = 'tenant_owner'
                          ORDER BY u.created_at ASC LIMIT 1) AS owner_name
             FROM tenants t WHERE t.status = 'pending' ORDER BY t.created_at ASC"
        );
        return $stmt->fetchAll();
    }

    /** Cross-tenant user search by name or email fragment. */
    public function searchUsers(string $q): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $stmt = Database::pdo()->prepare(
            "SELECT u.id, u.tenant_id, u.full_name, u.email, u.role, u.status, u.platform_admin,
                    u.created_at, t.name AS tenant_name, t.status AS tenant_status
             FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email LIKE ? OR u.full_name LIKE ?
             ORDER BY t.name, u.full_name LIMIT 100"
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll();
    }

    /**
     * v1.171.0 (S-3) — the platform operator must hold an APPROVED support grant (the tenant's Master Admin said
     * yes, 4-hour window) before creating logins, resetting passwords or handing out owner-level roles inside a
     * customer organization: each of those is a way to become that customer's user and reach their PHI. Demo
     * organizations carry no real PHI and are exempt. Returns an error message, or null when allowed.
     */
    private function supportGrantError(string $tenantId, string $what): ?string
    {
        try {
            $d = Database::pdo()->prepare('SELECT is_demo FROM tenants WHERE id = ? LIMIT 1');
            $d->execute([$tenantId]);
            if ((bool) $d->fetchColumn()) return null;
        } catch (\Throwable $e) { /* fall through to the grant check */ }
        if (SupportAccess::activeGrant($tenantId, $this->ctx->userId)) return null;
        return $what . ' needs an approved support session for this organization. Request support access and wait for the organization\'s Master Admin to approve it (each approval is a 4-hour window).';
    }

    /** Set a user's password from the console (support path). */
    public function resetUserPassword(string $tenantId, string $userId, string $newPassword): array
    {
        if (strlen($newPassword) < 10) return [false, 'Password must be at least 10 characters.'];
        if ($userId !== $this->ctx->userId && ($err = $this->supportGrantError($tenantId, 'Resetting a user\'s password'))) return [false, $err];
        $stmt = Database::pdo()->prepare(
            "UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([password_hash($newPassword, PASSWORD_ARGON2ID), $userId, $tenantId]);
        if ($stmt->rowCount() === 0) return [false, 'User not found.'];
        $this->audit($tenantId, 'admin_password_reset', 'users', $userId);   // the target's other sessions end (password fingerprint changed)
        return [true, 'Password updated. Share it with the user securely.'];
    }

    /** Rename an organization from the console. */
    public function updateTenantProfile(string $tenantId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) return [false, 'Enter an organization name (up to 160 characters).'];
        Database::pdo()->prepare("UPDATE tenants SET name = ? WHERE id = ?")->execute([$name, $tenantId]);
        $this->audit($tenantId, 'admin_tenant_renamed', 'tenants', $tenantId);
        return [true, 'Organization updated.'];
    }

    /** The tenant's principal owner account, shaped for session establishment. */
    public function ownerRowFor(string $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT u.*, t.name AS tenant_name, t.vertical, t.org_shape, t.status AS tenant_status
             FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.tenant_id = ? AND u.role = 'tenant_owner' AND u.status = 'active'
             ORDER BY u.created_at ASC LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetch() ?: null;
    }

    /** One tenant + its users (login accounts only — no client PHI). */
    /**
     * Operational usage metrics for one tenant — COUNTS AND DATES ONLY, never record contents.
     * This is what the platform operator legitimately needs to manage an account (adoption, seats,
     * storage, activity, health) without reading the client's provider, credential or document data.
     */
    public function tenantUsage(string $tenantId): array
    {
        $pdo = Database::pdo();
        $q = function (string $sql, array $p = []) use ($pdo) {
            try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); } catch (\Throwable $e) { return 0; }
        };
        $t = $tenantId;
        $u = [];
        // adoption
        $u['users']            = (int) $q("SELECT COUNT(*) FROM users WHERE tenant_id = ?", [$t]);
        $u['users_active']     = (int) $q("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status = 'active'", [$t]);
        $u['users_invited']    = (int) $q("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status = 'invited'", [$t]);
        $u['portal_users']     = (int) $q("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role IN ('producer','provider')", [$t]);
        $u['providers']        = (int) $q("SELECT COUNT(*) FROM credential_holders WHERE tenant_id = ? AND deleted_at IS NULL", [$t]);
        $u['credentials']      = (int) $q("SELECT COUNT(*) FROM credentials WHERE tenant_id = ? AND deleted_at IS NULL", [$t]);
        $u['documents']        = (int) $q("SELECT COUNT(*) FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL", [$t]);
        $u['groups']           = (int) $q("SELECT COUNT(*) FROM provider_groups WHERE tenant_id = ?", [$t]);
        $u['locations']        = (int) $q("SELECT COUNT(*) FROM locations WHERE tenant_id = ?", [$t]);
        // compliance health (counts only)
        $u['cred_expired']     = (int) $q("SELECT COUNT(*) FROM credentials WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'expired'", [$t]);
        $u['cred_expiring']    = (int) $q("SELECT COUNT(*) FROM credentials WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'expiring'", [$t]);
        $u['docs_verified']    = (int) $q("SELECT COUNT(*) FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL AND verified_at IS NOT NULL", [$t]);
        // storage
        $u['storage_bytes']    = (int) $q("SELECT COALESCE(SUM(size_bytes),0) FROM credential_documents WHERE tenant_id = ? AND deleted_at IS NULL", [$t]);
        // activity
        $u['actions_30d']      = (int) $q("SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)", [$t]);
        $u['actions_7d']       = (int) $q("SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)", [$t]);
        $u['active_users_30d'] = (int) $q("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)", [$t]);
        $u['last_activity']    = $q("SELECT MAX(created_at) FROM audit_logs WHERE tenant_id = ?", [$t]) ?: null;
        $u['last_login']       = $q("SELECT MAX(created_at) FROM access_logs WHERE tenant_id = ? AND action = 'login'", [$t]) ?: null;
        // feature adoption (did they turn it on / use it at all)
        $u['uses_email']       = (int) $q("SELECT COUNT(*) FROM tenant_mail_settings WHERE tenant_id = ? AND verified_at IS NOT NULL", [$t]);
        $u['doc_requests']     = (int) $q("SELECT COUNT(*) FROM document_requests WHERE tenant_id = ?", [$t]);
        $u['external_shares']  = (int) $q("SELECT COUNT(*) FROM external_shares WHERE tenant_id = ?", [$t]);
        $u['cross_links']      = (int) $q("SELECT COUNT(*) FROM provider_links WHERE (owner_tenant_id = ? OR requester_tenant_id = ?) AND status = 'active'", [$t, $t]);
        $u['notifications']    = (int) $q("SELECT COUNT(*) FROM notifications WHERE tenant_id = ?", [$t]);
        $u['emails_30d']       = (int) $q("SELECT COUNT(*) FROM email_outbox WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)", [$t]);
        // 12-week activity sparkline
        $u['weekly'] = [];
        try {
            $st = $pdo->prepare("SELECT YEARWEEK(created_at,3) w, COUNT(*) n FROM audit_logs
                                 WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 12 WEEK) GROUP BY w ORDER BY w");
            $st->execute([$t]);
            foreach ($st->fetchAll() as $r) { $u['weekly'][(string)$r['w']] = (int)$r['n']; }
        } catch (\Throwable $e) {}
        // top actions (what they actually use)
        $u['top_actions'] = [];
        try {
            $st = $pdo->prepare("SELECT action, COUNT(*) n FROM audit_logs WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)
                                 GROUP BY action ORDER BY n DESC LIMIT 8");
            $st->execute([$t]);
            $u['top_actions'] = $st->fetchAll();
        } catch (\Throwable $e) {}
        return $u;
    }

    public function tenantDetail(string $tenantId): ?array
    {
        $t = Database::pdo()->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $t->execute([$tenantId]);
        $tenant = $t->fetch();
        if ($tenant === false) return null;

        $u = Database::pdo()->prepare(
            "SELECT id, full_name, email, role, status, platform_admin, created_at
             FROM users WHERE tenant_id = ? ORDER BY created_at ASC"
        );
        $u->execute([$tenantId]);

        $parent = null;
        if (!empty($tenant['parent_tenant_id'])) {
            $p = Database::pdo()->prepare("SELECT name FROM tenants WHERE id = ?");
            $p->execute([$tenant['parent_tenant_id']]);
            $parent = $p->fetchColumn() ?: null;
        }
        return ['tenant' => $tenant, 'users' => $u->fetchAll(), 'parent' => $parent];
    }

    /** Suspend or reactivate a tenant. Suspended tenants cannot sign in. */
    public function setTenantStatus(string $tenantId, string $status, ?string $reason = null): array
    {
        if (!in_array($status, ['pending', 'active', 'suspended', 'rejected'], true)) {
            return [false, 'Unknown status.'];
        }
        if ($tenantId === $this->ctx->tenantId && $status !== 'active') {
            return [false, 'You cannot suspend or reject your own organization.'];
        }
        $reason = $reason !== null ? (trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : null) : null;
        // Activation stamps approved_at the first time; other states keep it.
        Database::pdo()->prepare(
            "UPDATE tenants SET status = ?, status_reason = ?,
                    approved_at = IF(? = 'active' AND approved_at IS NULL, UTC_TIMESTAMP(), approved_at)
             WHERE id = ?"
        )->execute([$status, $reason, $status, $tenantId]);
        $this->audit($tenantId, 'admin_tenant_' . $status, 'tenants', $tenantId);
        $verb = ['active' => 'activated', 'suspended' => 'suspended', 'rejected' => 'rejected', 'pending' => 'set to pending'][$status];

        // Notify the organization's owner on the decisions that matter to them.
        if (in_array($status, ['active', 'rejected'], true)) {
            $this->notifyOwner($tenantId, $status, $reason);
        }
        return [true, 'Organization ' . $verb . '.'];
    }

    /** Email the owner of a tenant about an approval decision (best-effort, queued). */
    private function notifyOwner(string $tenantId, string $status, ?string $reason): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT u.full_name, u.email, t.name AS org_name FROM users u
                 JOIN tenants t ON t.id = u.tenant_id
                 WHERE u.tenant_id = ? AND u.role = 'tenant_owner' ORDER BY u.created_at ASC LIMIT 1"
            );
            $stmt->execute([$tenantId]);
            $owner = $stmt->fetch();
            if (!$owner || empty($owner['email'])) return;

            if ($status === 'active') {
                $subject = 'Your organization on FlxCoreHC has been approved';
                $body = "Hello " . $owner['full_name'] . ",\n\n"
                      . "Good news — " . $owner['org_name'] . " has been approved on FlxCoreHC. "
                      . "You now have full access. Just sign in to get started.\n\nWelcome aboard.\n";
                $cat = 'approval';
            } else {
                $subject = 'Update on your FlxCoreHC organization request';
                $body = "Hello " . $owner['full_name'] . ",\n\n"
                      . "We're unable to approve " . $owner['org_name'] . " on FlxCoreHC at this time.\n"
                      . ($reason ? "\nReason: " . $reason . "\n" : '')
                      . "\nIf you believe this is a mistake, please reply to this message.\n";
                $cat = 'rejection';
            }
            MailService::queue($owner['email'], $subject, $body, $cat, $owner['full_name'], $tenantId);
            MailService::flush();
        } catch (\Throwable $e) { /* notification is best-effort */ }
    }

    /** Enable/disable a single user account. */
    public function setUserStatus(string $tenantId, string $userId, string $status): array
    {
        $status = $status === 'active' ? 'active' : 'suspended';
        if ($userId === $this->ctx->userId && $status !== 'active') {
            return [false, 'You cannot disable your own account.'];
        }
        Database::pdo()->prepare("UPDATE users SET status = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$status, $userId, $tenantId]);
        $this->audit($tenantId, 'admin_user_' . ($status === 'active' ? 'enabled' : 'suspended'), 'users', $userId);
        return [true, 'User ' . ($status === 'active' ? 'enabled' : 'suspended') . '.'];
    }

    /** Grant or revoke platform-admin. Never remove the last remaining admin. */
    /** Change a tenant user's role (platform-admin action). */
    public function setUserRole(string $tenantId, string $userId, string $role): array
    {
        $allowed = ['tenant_owner', 'manager', 'agent', 'staff'];
        if (!in_array($role, $allowed, true)) return [false, 'Unknown role.'];
        $pdo = Database::pdo();
        $u = $pdo->prepare("SELECT role, platform_admin FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $u->execute([$userId, $tenantId]);
        $urow = $u->fetch();
        if ($urow === false) return [false, 'User not found.'];
        $cur = $urow['role'];
        if (($role === 'tenant_owner' || $cur === 'tenant_owner') && ($err = $this->supportGrantError($tenantId, 'Changing an owner-level role'))) return [false, $err];
        // A platform administrator should keep full workspace access (Master Admin). Demoting their role
        // hides tenant features they need; require clearing platform-admin first if that is truly intended.
        if (!empty($urow['platform_admin']) && $role !== 'tenant_owner') {
            return [false, 'This user is a platform administrator. Remove platform-admin access first if you intend to lower their role.'];
        }
        // Never leave a tenant without an owner.
        if ($cur === 'tenant_owner' && $role !== 'tenant_owner') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'tenant_owner' AND status = 'active'");
            $c->execute([$tenantId]);
            if ((int)$c->fetchColumn() <= 1) return [false, 'This is the last owner — assign another owner first.'];
        }
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ? AND tenant_id = ?")->execute([$role, $userId, $tenantId]);
        $this->audit($tenantId, 'admin_user_role_changed', 'users', $userId);
        return [true, 'Role updated.'];
    }

    public function setPlatformAdmin(string $tenantId, string $userId, bool $grant): array
    {
        // v1.171.0 (S-3) / v1.172.0: while the platform requires two-factor for its administrators, only a user who already
        // uses it can be promoted. When the requirement is off (Console → Security), promotion is not blocked.
        if ($grant && Auth\AuthService::platformRequires2fa()) {
            $tf = Database::pdo()->prepare('SELECT totp_enabled FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
            $tf->execute([$userId, $tenantId]);
            if (!(int) $tf->fetchColumn()) return [false, 'Platform administrators must use two-factor authentication. Ask this user to enable 2FA on their profile first.'];
        }
        if (!$grant) {
            $admins = (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE platform_admin = 1")->fetchColumn();
            if ($admins <= 1) return [false, 'Cannot remove the last platform administrator.'];
            if ($userId === $this->ctx->userId) return [false, 'You cannot remove your own admin access.'];
        }
        Database::pdo()->prepare("UPDATE users SET platform_admin = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$grant ? 1 : 0, $userId, $tenantId]);
        $this->audit($tenantId, $grant ? 'admin_granted' : 'admin_revoked', 'users', $userId);
        return [true, $grant ? 'Platform admin granted.' : 'Platform admin revoked.'];
    }

    /**
     * Provision a new organization with an invited owner. The owner receives an
     * invite link to set their password. Returns [ok, message, inviteToken|null].
     */
    /** All platform administrators (across the operator org). */
    public function platformAdmins(): array
    {
        return Database::pdo()->query(
            "SELECT u.id, u.tenant_id, u.full_name, u.email, u.status, u.totp_enabled, u.mfa_method, t.name AS org_name
             FROM users u JOIN tenants t ON t.id=u.tenant_id
             WHERE u.platform_admin=1 ORDER BY u.created_at"
        )->fetchAll();
    }

    /** Invite another user into the SaaS admin area (joins the operator org as a platform admin). */
    public function invitePlatformAdmin(array $in): array
    {
        if (!$this->ctx->isPlatformAdmin) return [false, 'Not permitted.', null];
        $name = trim((string) ($in['full_name'] ?? ''));
        $mail = strtolower(trim((string) ($in['email'] ?? '')));
        if ($name === '')                                 return [false, 'Enter a name.', null];
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL))    return [false, 'Enter a valid email.', null];
        $pdo = Database::pdo();
        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
        $dupe->execute([$mail]);
        if ($dupe->fetchColumn()) return [false, 'A user with that email already exists.', null];
        $token = bin2hex(random_bytes(20));
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, invite_token, invite_expires, created_at)
             VALUES (?,?,?,?,?, 'tenant_owner', 'invited', 1, ?, ?, ?)"
        )->execute([
            uuid4(), $this->ctx->tenantId, $mail, password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            $name, TeamService::inviteHash($token), gmdate('Y-m-d H:i:s', time() + 7 * 86400), utc_now(),
        ]);
        $this->audit($this->ctx->tenantId, 'invite_platform_admin', 'users', $mail);
        return [true, 'Platform administrator invited.', $token];
    }

    public function createOrganization(array $in): array
    {
        $orgName   = trim((string) ($in['org_name'] ?? ''));
        $orgShape  = (string) ($in['org_shape'] ?? 'billing_company');
        $ownerName = trim((string) ($in['owner_name'] ?? ''));
        $ownerMail = strtolower(trim((string) ($in['owner_email'] ?? '')));
        $shapes = \FlxCoreHC\Auth\AuthService::ORG_SHAPES;
        if (!isset($shapes[$orgShape]))                       return [false, 'Choose an organization type.', null];
        if ($orgName === '' || $ownerName === '')             return [false, 'Organization and owner name are required.', null];
        if (!filter_var($ownerMail, FILTER_VALIDATE_EMAIL))   return [false, 'Enter a valid owner email.', null];
        $tempPass = (string) ($in['owner_password'] ?? '');
        $useTemp  = $tempPass !== '';
        if ($useTemp && strlen($tempPass) < 10)               return [false, 'Temporary password must be at least 10 characters.', null];

        $pdo = Database::pdo();
        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
        $dupe->execute([$ownerMail]);
        if ($dupe->fetchColumn()) return [false, 'A user with that email already exists.', null];

        [$vertical, $tier] = $shapes[$orgShape];
        $now = utc_now(); $tenantId = uuid4(); $userId = uuid4(); $token = bin2hex(random_bytes(20));
        $pdo->beginTransaction();
        try {
            $trialDays = (int) config('trial_days', 0);
            $billStatus = (\FlxCoreHC\BillingService::isConfigured() && $trialDays > 0) ? 'trialing' : 'none';
            $periodEnd = $billStatus === 'trialing' ? gmdate('Y-m-d H:i:s', time() + $trialDays * 86400) : null;
            $pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, plan_tier, status, billing_status, current_period_end, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$tenantId, $orgName, $vertical, $orgShape, $tier, 'active', $billStatus, $periodEnd, $now]);
            $holderId = null;
            $parts = preg_split('/\s+/', $ownerName, 2);
            if (in_array($orgShape, ['solo_agent', 'solo_practice'], true)) {
                $holderId = uuid4();
                $pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, first_name, last_name, email_enc, vertical, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$holderId, $tenantId, 'individual', $parts[0], $parts[1] ?? '', Crypto::encrypt($ownerMail, $tenantId . '|credential_holders|email_enc'), $vertical, 'active', $now]);
            }
            if (in_array($orgShape, ['agency', 'medical_group', 'solo_practice'], true)) {
                $pdo->prepare("INSERT INTO credential_holders (id, tenant_id, holder_type, org_name, vertical, status, created_at) VALUES (?,?,?,?,?,?,?)")
                    ->execute([uuid4(), $tenantId, 'organization', $orgName, $vertical, 'active', $now]);
            }
            $pwHash = password_hash($useTemp ? $tempPass : bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
            $status = $useTemp ? 'active' : 'invited';
            $inviteTok = $useTemp ? null : TeamService::inviteHash($token);
            $inviteExp = $useTemp ? null : gmdate('Y-m-d H:i:s', time() + 7 * 86400);
            $pdo->prepare("INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, holder_id, status, platform_admin, invite_token, invite_expires, created_at) VALUES (?,?,?,?,?, 'tenant_owner', ?, ?, 0, ?, ?, ?)")
                ->execute([$userId, $tenantId, $ownerMail, $pwHash, $ownerName, $holderId, $status, $inviteTok, $inviteExp, $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return [false, 'Could not create organization: ' . $e->getMessage(), null];
        }
        // Equip the new organization with its role set so its Master Admin can manage the team
        // immediately (idempotent; the tenant_owner slug is always all-capabilities regardless).
        try { RoleService::seedFor($tenantId); } catch (\Throwable $e) { /* non-fatal */ }
        // ...and the starter requirement templates, regulators and calendar event types, exactly like
        // a signup-created tenant gets — so onboarding starts with a full library, not an empty one.
        try { \FlxCoreHC\Auth\AuthService::seedTemplatesFor($tenantId); } catch (\Throwable $e) { /* non-fatal */ }
        $this->audit($tenantId, 'admin_org_created', 'tenants', $tenantId);
        if ($useTemp) {
            return [true, 'Organization "' . $orgName . '" created. Owner ' . $ownerMail . ' can sign in now with the temporary password you set.', null];
        }
        return [true, 'Organization "' . $orgName . '" created. Send the owner their invite link.', $token];
    }

    /**
     * Create a user inside any tenant (platform-admin action). If a password is given the
     * account is active immediately; otherwise an invite token is returned.
     * @return array{0:bool,1:string,2:?string} [ok, message, inviteToken|null]
     */
    /**
     * Every user across all tenants, grouped by tenant, for the console directory.
     * @return array<string,array{tenant:array,users:array}>
     */
    public function allUsersByTenant(?string $q = null): array
    {
        $pdo = Database::pdo();
        $q = trim((string) $q);
        $sql = "SELECT u.id, u.tenant_id, u.full_name, u.email, u.role, u.status, u.platform_admin,
                       u.created_at, t.name AS tenant_name, t.status AS tenant_status
                FROM users u JOIN tenants t ON t.id = u.tenant_id";
        $params = [];
        if ($q !== '') {
            $sql .= " WHERE u.email LIKE ? OR u.full_name LIKE ? OR t.name LIKE ?";
            $like = '%' . $q . '%'; $params = [$like, $like, $like];
        }
        $sql .= " ORDER BY t.name, u.role, u.full_name LIMIT 2000";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $tid = (string) $r['tenant_id'];
            if (!isset($out[$tid])) {
                $out[$tid] = ['tenant' => ['id' => $tid, 'name' => $r['tenant_name'], 'status' => $r['tenant_status']], 'users' => []];
            }
            $out[$tid]['users'][] = $r;
        }
        return $out;
    }

    /**
     * Add a user to any tenant as a VIEWER — the tenant's Master Admin then assigns the real role.
     * This keeps role assignment strictly a tenant decision. Creates an invite (no password set here).
     */
    public function addUserAsViewer(string $tenantId, string $fullName, string $email): array
    {
        $name  = trim($fullName);
        $email = strtolower(trim($email));
        if ($name === '')                               return [false, 'Enter the person\'s name.', null];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.', null];
        if ($err = $this->supportGrantError($tenantId, 'Adding a viewer to an organization')) return [false, $err, null];

        $pdo = Database::pdo();
        $t = $pdo->prepare("SELECT id, name FROM tenants WHERE id = ? LIMIT 1");
        $t->execute([$tenantId]);
        $trow = $t->fetch();
        if (!$trow) return [false, 'Organization not found.', null];

        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) return [false, 'A user with that email already exists.', null];

        $id = uuid4();
        $token = bin2hex(random_bytes(20));
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, invite_token, invite_expires, created_at)
             VALUES (?,?,?,?,?, 'viewer', 'invited', 0, ?, ?, ?)"
        )->execute([
            $id, $tenantId, $email,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            $name, TeamService::inviteHash($token), gmdate('Y-m-d H:i:s', time() + 7 * 86400), utc_now(),
        ]);
        $this->audit($tenantId, 'admin_user_added_viewer', 'users', $id);
        return [true, $name . ' added to ' . $trow['name'] . ' as Viewer. The tenant\'s Master Admin can now assign the right role.', $token];
    }

    public function createUserInTenant(string $tenantId, array $in): array
    {        $name  = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $role  = (string) ($in['role'] ?? 'staff');
        $pass  = (string) ($in['password'] ?? '');
        $allowedRoles = ['tenant_owner', 'manager', 'agent', 'staff'];
        if (!in_array($role, $allowedRoles, true)) $role = 'staff';
        if ($name === '')                                return [false, 'Enter the person\'s name.', null];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return [false, 'Enter a valid email address.', null];
        if ($err = $this->supportGrantError($tenantId, 'Creating a login in an organization')) return [false, $err, null];

        $pdo = Database::pdo();
        $t = $pdo->prepare("SELECT id FROM tenants WHERE id = ? LIMIT 1");
        $t->execute([$tenantId]);
        if (!$t->fetchColumn()) return [false, 'Organization not found.', null];

        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) return [false, 'A user with that email already exists.', null];

        $useTemp = $pass !== '';
        if ($useTemp && strlen($pass) < 10) return [false, 'Temporary password must be at least 10 characters.', null];

        $id = uuid4();
        $token = $useTemp ? null : bin2hex(random_bytes(20));
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, invite_token, invite_expires, created_at)
             VALUES (?,?,?,?,?,?,?,0,?,?,?)"
        )->execute([
            $id, $tenantId, $email,
            password_hash($useTemp ? $pass : bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            $name, $role, $useTemp ? 'active' : 'invited',
            $token === null ? null : TeamService::inviteHash($token), $useTemp ? null : gmdate('Y-m-d H:i:s', time() + 7 * 86400), utc_now(),
        ]);
        $this->audit($tenantId, 'admin_user_created', 'users', $id);
        return $useTemp
            ? [true, $name . ' created and can sign in now with the temporary password.', null]
            : [true, 'Invite created for ' . $name . '.', $token];
    }

    private function audit(string $tenantId, string $action, string $entity, ?string $entityId): void
    {
        try {
            Database::pdo()->prepare(
                "INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, created_at)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([uuid4(), $tenantId, $this->ctx->userId, $action, $entity, $entityId,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    // ---- Platform audit log (v1.172.5) --------------------------------------------------------------
    // The tenant audit log is scoped to one organization, so operator actions (settings, tests, support access) had no
    // screen of their own. This is the cross-organization view for platform administrators.
    // PRIVACY BOUNDARY: the "changes" detail of a row is returned ONLY when the actor is a platform administrator (operator
    // accountability). For ordinary users of a customer organization it is withheld, and the free-text search does not look
    // inside it, so this screen cannot be used to read or probe customers' record data.

    /** @return array{0:string,1:array} [where-sql, params] */
    private function auditWhere(array $f): array
    {
        $w = '1=1'; $p = [];
        if (!empty($f['tenant_id'])) { $w .= ' AND a.tenant_id = ?'; $p[] = (string) $f['tenant_id']; }
        if (!empty($f['staff']))     { $w .= ' AND u.platform_admin = 1'; }
        if (!empty($f['action']))    { $w .= ' AND a.action = ?'; $p[] = (string) $f['action']; }
        if (!empty($f['from']))      { $w .= ' AND a.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))        { $w .= ' AND a.created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $w .= ' AND (a.action LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR t.name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        return [$w, $p];
    }

    public function auditPage(array $f, int $limit = 100, int $offset = 0): array
    {
        [$w, $p] = $this->auditWhere($f);
        $limit = max(1, min($limit, 500)); $offset = max(0, $offset);
        $st = Database::pdo()->prepare(
            "SELECT a.id, a.created_at, a.action, a.entity_type, a.ip_address, a.tenant_id, t.name AS tenant_name,
                    u.full_name AS user_name, u.email AS user_email, COALESCE(u.platform_admin, 0) AS is_staff,
                    CASE WHEN u.platform_admin = 1 THEN a.changes ELSE NULL END AS changes
             FROM audit_logs a
             JOIN tenants t ON t.id = a.tenant_id
             LEFT JOIN users u ON u.id = a.user_id
             WHERE {$w} ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($p);
        return $st->fetchAll();
    }

    public function auditCount(array $f): int
    {
        [$w, $p] = $this->auditWhere($f);
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM audit_logs a JOIN tenants t ON t.id = a.tenant_id LEFT JOIN users u ON u.id = a.user_id WHERE {$w}");
        $st->execute($p);
        return (int) $st->fetchColumn();
    }

    public function auditActions(): array
    {
        return array_column(Database::pdo()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(), 'action');
    }

    public function auditTenants(): array
    {
        return Database::pdo()->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll();
    }
}
