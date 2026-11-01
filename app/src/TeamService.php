<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Tenant-level user management: an organization manages ITS OWN team.
 *
 * Every method is scoped to the caller's tenant ($ctx->tenantId) and can only see
 * or touch users in that tenant — this is the same isolation guarantee as the rest
 * of the app, enforced here with explicit tenant_id filters on every query.
 *
 * Roles (within a tenant):
 *   tenant_owner — full control, incl. team management; there is always >= 1
 *   admin        — can manage the team
 *   member       — regular user, no team management
 *
 * This service NEVER touches platform_admin (that is the SaaS operator tier, granted
 * only from the platform console) and never crosses tenant boundaries.
 */
final class TeamService
{
    public const ROLES = [
        'tenant_owner' => 'Master Admin',
        'manager'      => 'Manager',
        'agent'        => 'Coordinator',
        'staff'        => 'Staff',
        'producer'     => 'Provider (self-service)',
        'read_only'    => 'Read-only',
    ];
    // Roles allowed to manage the team.
    /** v1.171.5 — human label for a role slug; never shows the raw slug (e.g. tenant_owner) to a user who cannot assign that role. */
    public static function roleLabel(string $slug, array $assignable = []): string
    {
        if (isset($assignable[$slug])) return (string) $assignable[$slug];
        if (isset(self::ROLES[$slug])) return self::ROLES[$slug];
        if (isset(\FlxCoreHC\RoleService::GLOBAL_ROLES[$slug])) return \FlxCoreHC\RoleService::GLOBAL_ROLES[$slug]['name'];
        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }

    public const MANAGER_ROLES = ['tenant_owner', 'manager'];

    public static function canManage(TenantContext $ctx): bool
    {
        // Central matrix — also demotes everyone in a pending tenant to read_only,
        // so management buttons vanish while an organization awaits approval.
        return Authz::can($ctx, 'team.manage');
    }

    public function __construct(private readonly TenantContext $ctx)
    {
        if (!self::canManage($ctx)) {
            throw new \RuntimeException('You do not have permission to manage users.');
        }
    }

    /**
     * v1.170.8 — read-only directory (id, name, role, status) that does NOT require team.manage.
     * Pages that only need to display "who" (verifier names, portal-login status, audit filter) use this,
     * so a Staff/Coordinator/Read-only user no longer hits the constructor's permission exception (500).
     * No email, holder link or invite state is exposed here.
     */
    public static function directory(TenantContext $ctx): array
    {
        $st = Database::pdo()->prepare("SELECT id, full_name, role, status FROM users WHERE tenant_id = ? ORDER BY full_name");
        $st->execute([$ctx->tenantId]);
        return $st->fetchAll();
    }

    /** Portal login (if any) for a holder — read-only, no team.manage needed (display only). */
    public static function portalUserFor(TenantContext $ctx, string $holderId): ?array
    {
        $st = Database::pdo()->prepare("SELECT id, full_name, email, role, status FROM users WHERE tenant_id = ? AND holder_id = ? LIMIT 1");
        $st->execute([$ctx->tenantId, $holderId]);
        return $st->fetch() ?: null;
    }

    /** All users in this tenant. */
    public function users(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, full_name, email, role, status, platform_admin, holder_id, provider_access, created_at
             FROM users WHERE tenant_id = ? ORDER BY created_at ASC"
        );
        $stmt->execute([$this->ctx->tenantId]);
        return $stmt->fetchAll();
    }

    /** Create a user in this tenant. Returns [ok, message]. */
    public function create(array $in): array
    {
        $name  = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $role  = $this->normalizeRole($in['role'] ?? 'member');
        $pass  = (string) ($in['password'] ?? '');
        if ($role === null)                              return [false, 'That role does not exist in this organization. Pick a role from the list.'];
        if (!$this->actorMayGrant($role))                return [false, 'You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only).'];

        if (!self::validFullName($name))                 return [false, 'Enter the person\'s real first and last name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return [false, 'Enter a valid email address.'];
        if (strlen($pass) < 10)                          return [false, 'Password must be at least 10 characters.'];

        $exists = Database::pdo()->prepare("SELECT 1 FROM users WHERE email = ? AND tenant_id = ? LIMIT 1");
        $exists->execute([$email, $this->ctx->tenantId]);
        if ($exists->fetchColumn()) return [false, 'A user with that email already exists.'];

        if (!PlanService::canAddUser($this->ctx->tenantId)) {
            return [false, 'Your plan seat limit is reached. Upgrade or remove a user to add more.'];
        }
        $holderId = trim((string)($in['holder_id'] ?? '')) ?: null;
        if ($role === 'producer' || \FlxCoreHC\RoleService::isPortalOnly($this->ctx->tenantId, $role)) {   // v1.170.8: any portal-only role
            if ($holderId === null) return [false, 'Pick which provider this login belongs to.'];
            $own = Database::pdo()->prepare("SELECT 1 FROM credential_holders WHERE id = ? AND tenant_id = ? LIMIT 1");
            $own->execute([$holderId, $this->ctx->tenantId]);
            if (!$own->fetchColumn()) return [false, 'That provider record was not found.'];
        } else {
            $holderId = null;
        }
        $id = uuid4();
        Database::pdo()->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin, holder_id, created_at)
             VALUES (?,?,?,?,?,?, 'active', 0, ?, ?)"
        )->execute([$id, $this->ctx->tenantId, $email, password_hash($pass, PASSWORD_ARGON2ID), $name, $role, $holderId, utc_now()]);
        $this->audit('user_created', $id, ['role' => $role]);
        return [true, $name . ' added to your team.'];
    }

    /** Invite a user by email: creates an 'invited' account + one-time link. Returns [ok, msg, token]. */
    public function invite(array $in): array
    {
        $name  = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $role  = $this->normalizeRole($in['role'] ?? 'staff');
        if ($role === null)                              return [false, 'That role does not exist in this organization. Pick a role from the list.', null];
        if (!$this->actorMayGrant($role))                return [false, 'You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only).', null];
        if (!self::validFullName($name))                return [false, 'Enter the person\'s real first and last name.', null];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.', null];

        $exists = Database::pdo()->prepare("SELECT 1 FROM users WHERE email = ? AND tenant_id = ? LIMIT 1");
        $exists->execute([$email, $this->ctx->tenantId]);
        if ($exists->fetchColumn()) return [false, 'A user with that email already exists.', null];

        if (!PlanService::canAddUser($this->ctx->tenantId)) {
            $lim = PlanService::seatLimit($this->ctx->tenantId);
            return [false, t('Your plan includes {n} seats. Upgrade or remove a user to add more.', ['n' => $lim]), null];
        }

        // Producer invites must carry the producer record they belong to.
        $holderId = trim((string)($in['holder_id'] ?? '')) ?: null;
        if ($role === 'producer' || \FlxCoreHC\RoleService::isPortalOnly($this->ctx->tenantId, $role)) {   // v1.170.8: any portal-only role
            if ($holderId === null) return [false, 'Pick which provider this login belongs to.', null];
            $own = Database::pdo()->prepare("SELECT 1 FROM credential_holders WHERE id = ? AND tenant_id = ? LIMIT 1");
            $own->execute([$holderId, $this->ctx->tenantId]);
            if (!$own->fetchColumn()) return [false, 'That provider record was not found.', null];
        } else {
            $holderId = null;
        }
        $id    = uuid4();
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin,
                holder_id, invite_token, invite_expires, created_at)
             VALUES (?,?,?,?,?,?, 'invited', 0, ?, ?, ?, ?)"
        )->execute([
            $id, $this->ctx->tenantId, $email,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID), // unusable until accepted
            $name, $role, $holderId, self::inviteHash($token), gmdate('Y-m-d H:i:s', time() + 7 * 86400), utc_now(),
        ]);
        $this->audit('user_invited', $id, ['role' => $role]);
        return [true, t('Invitation created for {name}.', ['name' => $name]), $token];
    }

    /**
     * Ensure a producer holder has a linked portal login. Idempotent: if a user is already tied to
     * the holder (or the email is taken), it returns that state without creating a duplicate.
     * Returns [created(bool), message, token|null]. Producer logins bypass the seat check because
     * portal access is mandatory infrastructure for a producer, not an optional staff seat.
     */
    public function ensureProducerUser(string $holderId, string $fullName, string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'A valid email is needed to create the portal login.', null];

        // Already linked to this producer?
        $has = Database::pdo()->prepare("SELECT id FROM users WHERE tenant_id = ? AND holder_id = ? LIMIT 1");
        $has->execute([$this->ctx->tenantId, $holderId]);
        if ($has->fetchColumn()) return [false, 'This provider already has a portal login.', null];

        // Email already used by someone in this tenant?
        $dup = Database::pdo()->prepare("SELECT id, holder_id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1");
        $dup->execute([$this->ctx->tenantId, $email]);
        if ($row = $dup->fetch()) {
            // Link that existing account to this producer if it isn't linked yet.
            if (empty($row['holder_id'])) {
                Database::pdo()->prepare("UPDATE users SET holder_id = ?, role = 'producer' WHERE id = ? AND tenant_id = ?")
                    ->execute([$holderId, $row['id'], $this->ctx->tenantId]);
                $this->audit('provider_user_linked', $row['id']);
                return [true, 'Linked the existing account to this provider.', null];
            }
            return [false, 'That email is already used by another user.', null];
        }

        $id    = uuid4();
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, platform_admin,
                holder_id, invite_token, invite_expires, created_at)
             VALUES (?,?,?,?,?, 'producer', 'invited', 0, ?, ?, ?, ?)"
        )->execute([
            $id, $this->ctx->tenantId, $email,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            $fullName ?: $email, $holderId, self::inviteHash($token), gmdate('Y-m-d H:i:s', time() + 7 * 86400), utc_now(),
        ]);
        $this->audit('provider_user_invited', $id);
        return [true, t('Portal login created for {name}.', ['name' => ($fullName ?: $email)]), $token];
    }

    /** The user linked to a producer holder, or null. */
    public function userForHolder(string $holderId): ?array
    {
        $st = Database::pdo()->prepare("SELECT id, full_name, email, role, status FROM users
            WHERE tenant_id = ? AND holder_id = ? LIMIT 1");
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetch() ?: null;
    }

    /** True if a producer holder has any linked user account. */
    public function holderHasUser(string $holderId): bool
    {
        $st = Database::pdo()->prepare("SELECT 1 FROM users WHERE tenant_id = ? AND holder_id = ? LIMIT 1");
        $st->execute([$this->ctx->tenantId, $holderId]);
        return (bool) $st->fetchColumn();
    }

    /** Regenerate the invite link for a still-pending user. */
    public function resendInvite(string $userId): array
    {
        $u = $this->find($userId);
        if (!$u || $u['status'] !== 'invited') return [false, 'That user is not pending an invite.', null];
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare(
            "UPDATE users SET invite_token = ?, invite_expires = ? WHERE id = ? AND tenant_id = ?"
        )->execute([self::inviteHash($token), gmdate('Y-m-d H:i:s', time() + 7 * 86400), $userId, $this->ctx->tenantId]);
        $this->audit('user_invite_resent', $userId);
        return [true, 'New invite link generated.', $token];
    }

    /** Invite tokens are stored hashed (S-8): a database read does not yield usable invite links. The raw token only travels in the emailed link. */
    public static function inviteHash(string $token): string
    {
        return hash('sha256', 'invite|' . $token);
    }

    /** Raw-token fallback for invites issued before v1.171.3. Only a 40-char value qualifies, so the stored 64-char hash can never be replayed as a token. */
    private static function legacyInvite(string $token): string
    {
        return strlen($token) === 40 ? $token : '-';
    }

    /** Look up a valid (unexpired, pending) invite by token — public, no tenant context. */
    public static function findInvite(string $token): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT u.id, u.tenant_id, u.full_name, u.email, t.name AS org_name
             FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.invite_token IN (?, ?) AND u.status = 'invited'
               AND u.invite_expires > ? AND t.status = 'active' LIMIT 1"
        );
        $stmt->execute([self::inviteHash($token), self::legacyInvite($token), gmdate('Y-m-d H:i:s')]);   // hash first; raw value = invites issued before v1.171.3
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Accept an invite: set password, activate, clear token — public, no tenant context. */
    public static function acceptInvite(string $token, string $password): array
    {
        if (strlen($password) < 10) return [false, 'Password must be at least 10 characters.'];
        $inv = self::findInvite($token);
        if (!$inv) return [false, 'This invitation is invalid or has expired.'];
        // v1.171.4 — atomic consumption: the row must STILL be this pending, unexpired invitation at write time.
        $st = Database::pdo()->prepare(
            "UPDATE users SET password_hash = ?, status = 'active', invite_token = NULL, invite_expires = NULL
             WHERE id = ? AND tenant_id = ? AND status = 'invited' AND invite_expires > ? AND invite_token IN (?, ?)"
        );
        $st->execute([password_hash($password, PASSWORD_ARGON2ID), $inv['id'], $inv['tenant_id'], gmdate('Y-m-d H:i:s'), self::inviteHash($token), self::legacyInvite($token)]);
        if ($st->rowCount() !== 1) return [false, 'This invitation is invalid or has expired.'];
        return [true, 'Your account is ready — please sign in.'];
    }

    public function updateRole(string $userId, string $role): array
    {
        $role   = $this->normalizeRole($role);
        if ($role === null) return [false, 'That role does not exist in this organization. Pick a role from the list.'];
        $target = $this->find($userId);
        if (!$target) return [false, 'User not found.'];
        if (!$this->actorMayTouch($target)) return [false, "Only an owner can change an owner's role."];
        if (!$this->actorMayGrant($role))   return [false, 'You can only assign roles that do not exceed your own permissions (Master Admin roles: Master Admin only).'];
        // Don't allow removing the last owner (incl. demoting yourself as the last owner).
        if ($target['role'] === 'tenant_owner' && $role !== 'tenant_owner' && $this->ownerCount() <= 1) {
            return [false, 'Your organization must have at least one owner.'];
        }
        // A portal-only login (the Producer role, or any custom portal role) is meaningless
        // without a linked producer record, and a stale link is a leak risk if the user is
        // moved to a non-portal role — so keep them in sync with the role's portal flag.
        $portalOnly = \FlxCoreHC\RoleService::isPortalOnly($this->ctx->tenantId, $role);
        // A platform administrator must never be moved into a self-service portal role — that role hides
        // the console and would lock the operator out of platform administration. Clear the platform flag
        // first (a deliberate, separate action) if you truly intend to demote them.
        if ($portalOnly && !empty($target['platform_admin'])) {
            return [false, 'This is a platform administrator. Remove platform-admin access first if you intend to move them to a self-service role.'];
        }
        if ($portalOnly && empty($target['holder_id'])) {
            return [false, 'Link this user to a provider record before giving them a self-service portal role.'];
        }
        $holderId = $portalOnly ? $target['holder_id'] : null;
        Database::pdo()->prepare("UPDATE users SET role = ?, holder_id = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$role, $holderId, $userId, $this->ctx->tenantId]);
        $this->audit('user_role_changed', $userId, ['from' => (string) $target['role'], 'to' => $role]);
        if (RoleService::isReservedOwnerSlug($role) && !RoleService::isReservedOwnerSlug((string) $target['role'])) {
            $this->audit('owner_level_role_granted', $userId, ['from' => (string) $target['role'], 'to' => $role]);   // v1.170.9: explicit high-signal event
        }
        return [true, 'Role updated.'];
    }

    public function setStatus(string $userId, string $status): array
    {
        $status = $status === 'active' ? 'active' : 'suspended';
        $target = $this->find($userId);
        if (!$target) return [false, 'User not found.'];
        if (!$this->actorMayTouch($target)) return [false, "Only an owner can disable an owner's account."];
        if ($userId === $this->ctx->userId && $status !== 'active') return [false, 'You cannot disable your own account.'];
        if ($target['role'] === 'tenant_owner' && $status !== 'active' && $this->ownerCount() <= 1) {
            return [false, 'You cannot disable the last owner.'];
        }
        Database::pdo()->prepare("UPDATE users SET status = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$status, $userId, $this->ctx->tenantId]);
        $this->audit($status === 'active' ? 'user_enabled' : 'user_disabled', $userId);
        return [true, 'User ' . ($status === 'active' ? 'enabled' : 'suspended') . '.'];
    }

    public function resetPassword(string $userId, string $newPass): array
    {
        if (strlen($newPass) < 10) return [false, 'Password must be at least 10 characters.'];
        $target = $this->find($userId);
        if (!$target) return [false, 'User not found.'];
        if (!$this->actorMayTouch($target)) return [false, "Only an owner can reset an owner's password."];
        Database::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?")
            ->execute([password_hash($newPass, PASSWORD_ARGON2ID), $userId, $this->ctx->tenantId]);
        $this->audit('user_password_reset', $userId);
        return [true, 'Password reset.'];
    }

    /**
     * A person's full name: 3–120 chars and at least two words of 2+ letters each.
     * Stops keyboard-mash accounts ("sdfsd") in a professional system while accepting
     * real names with hyphens, apostrophes, and accents.
     */
    public static function validFullName(string $name): bool
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) return false;
        $words = preg_split('/\s+/u', $name) ?: [];
        $real  = array_filter($words, fn($w) => mb_strlen(preg_replace('/[^\p{L}]/u', '', $w)) >= 2);
        return count($real) >= 2;
    }

    /** Managers manage staff; only an owner may act on an owner's account. */
    private function actorMayTouch(array $target): bool
    {
        if (($target['role'] ?? '') !== 'tenant_owner') return true;
        return $this->ctx->role === 'tenant_owner';
    }

    /**
     * v1.170.8 (security) — only a Master Admin may GRANT a role that carries every capability
     * (tenant_owner, or the reserved master_admin slug). Before this, a Manager with team.manage could
     * promote any user — including themselves — to Master Admin via add / invite / change-role.
     */
    private function actorMayGrant(string $role): bool
    {
        if (\FlxCoreHC\RoleService::isReservedOwnerSlug($role)) return $this->ctx->role === 'tenant_owner';
        // v1.171.0 (S-1): capability ceiling — nobody may hand out (or take) a role holding capabilities they lack.
        if (\FlxCoreHC\RoleService::isReservedOwnerSlug($this->ctx->role)) return true;
        $mine   = \FlxCoreHC\RoleService::capabilitiesForSlug($this->ctx->tenantId, $this->ctx->role);
        $theirs = \FlxCoreHC\RoleService::capabilitiesForSlug($this->ctx->tenantId, $role);
        return array_diff($theirs, $mine) === [];
    }

    /** Edit a user's name and sign-in email (v1.47 team rebuild). */
    public function updateUser(string $userId, string $name, string $email): array
    {
        $name  = trim($name);
        $email = strtolower(trim($email));
        $target = $this->find($userId);
        if (!$target) return [false, 'User not found.'];
        if (!$this->actorMayTouch($target)) return [false, "Only an owner can edit an owner's account."];
        if (!self::validFullName($name)) return [false, 'Enter the person\'s real first and last name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.'];
        if ($email !== $target['email']) {
            $exists = Database::pdo()->prepare("SELECT 1 FROM users WHERE email = ? AND tenant_id = ? AND id <> ? LIMIT 1");
            $exists->execute([$email, $this->ctx->tenantId, $userId]);
            if ($exists->fetchColumn()) return [false, 'Another user already signs in with that email.'];
        }
        Database::pdo()->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$name, $email, $userId, $this->ctx->tenantId]);
        $this->audit('user_updated', $userId);
        return [true, 'User updated.'];
    }

    // ---- helpers (all tenant-scoped) ----

    /** Only ever returns a user that belongs to the caller's tenant. */
    private function find(string $userId): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT id, role, status, holder_id, email, full_name, platform_admin FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$userId, $this->ctx->tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function ownerCount(): int
    {
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'tenant_owner' AND status = 'active'");
        $stmt->execute([$this->ctx->tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /** v1.171.0 (B-5): a role is valid only if this tenant actually has it. Unknown → null (rejected), never silently "staff". */
    private function normalizeRole(string $role): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM roles WHERE tenant_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$this->ctx->tenantId, $role]);
        return $stmt->fetchColumn() ? $role : null;
    }

    private function audit(string $action, string $userId, ?array $changes = null): void
    {
        try {
            Database::pdo()->prepare(
                "INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, changes, ip_address, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([uuid4(), $this->ctx->tenantId, $this->ctx->userId, $action, 'users', $userId,
                $changes === null ? null : json_encode($changes, JSON_UNESCAPED_SLASHES),   // role slugs / status only — never secrets
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
