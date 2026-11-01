<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Per-tenant role management. Seeds the six system roles into every tenant with exactly the
 * capabilities the old hardcoded Authz matrix granted, then lets a tenant add its own roles
 * and choose each role's capabilities. Authz resolves a user's capabilities through here.
 *
 * All reads and writes are tenant-scoped. System roles are protected: they can be renamed
 * and (except Owner) re-scoped, but not deleted, and Owner always holds every capability.
 */
final class RoleService
{
    /**
     * The system roles seeded into every tenant. Slugs match the historical users.role
     * strings so existing user rows keep working with zero migration. Capabilities here
     * reproduce the previous Authz::MATRIX precisely.
     */
    public const SYSTEM_ROLES = [
        'tenant_owner' => ['name' => 'Master Admin',   'portal_only' => 0, 'sort' => 10, 'caps' => '*'],
        'manager'      => ['name' => 'Manager', 'portal_only' => 0, 'sort' => 20, 'caps' => [
            'org.manage', 'team.manage', 'billing.manage', 'billing.view',
            'records.write', 'records.delete', 'records.import',
            'documents.manage', 'documents.view', 'documents.verify', 'documents.request',
            'share.manage', 'crossorg.manage', 'data.export',
            'reports.view', 'audit.view', 'npi.lookup',
        ]],
        'staff'        => ['name' => 'Staff',   'portal_only' => 0, 'sort' => 30, 'caps' => [
            'records.write', 'records.import', 'documents.manage', 'documents.view',
            'documents.verify', 'documents.request', 'reports.view', 'npi.lookup',
        ]],
        'agent'        => ['name' => 'Coordinator', 'portal_only' => 0, 'sort' => 40, 'caps' => [
            // Data entry without the compliance attestations: can upload, cannot verify or export.
            'records.write', 'documents.manage', 'documents.view', 'documents.request',
            'reports.view', 'npi.lookup',
        ]],
        'producer'     => ['name' => 'Provider (self-service)', 'portal_only' => 1, 'sort' => 50, 'caps' => [
            'documents.view',
        ]],
        'read_only'    => ['name' => 'Read-only', 'portal_only' => 0, 'sort' => 60, 'caps' => [
            'documents.view', 'reports.view',
        ]],
    ];

    public function __construct(private TenantContext $ctx) {}

    /** Seed the system roles for one tenant if they aren't present yet. Idempotent. */
    /**
     * Platform-owned master roles (tenant_id = NULL), applied to every tenant and editable only by
     * the superadmin. Master Admin holds all capabilities and is the tenant's role-management + support
     * -approval authority; the rest are graded defaults. Tenants may create their own roles but cannot
     * edit these.
     */
    public const GLOBAL_ROLES = [
        'master_admin' => ['name' => 'Master Admin', 'portal_only' => 0, 'sort' => 5,  'caps' => '*'],
        'admin'        => ['name' => 'Admin',        'portal_only' => 0, 'sort' => 10, 'caps' => [
            'org.manage', 'team.manage', 'billing.view', 'records.write', 'records.delete',
            'documents.manage', 'documents.view', 'share.manage', 'audit.view', 'npi.lookup',
        ]],
        'manager'      => ['name' => 'Manager', 'portal_only' => 0, 'sort' => 20, 'caps' => [
            'records.write', 'records.delete', 'documents.manage', 'documents.view',
            'share.manage', 'audit.view', 'npi.lookup',
        ]],
        'regular'      => ['name' => 'Regular User', 'portal_only' => 0, 'sort' => 30, 'caps' => [
            'records.write', 'documents.manage', 'documents.view', 'npi.lookup',
        ]],
        'viewer'       => ['name' => 'Viewer', 'portal_only' => 0, 'sort' => 40, 'caps' => [
            'documents.view',
        ]],
        'provider'     => ['name' => 'Provider (self-service)', 'portal_only' => 1, 'sort' => 50, 'caps' => [
            'documents.view',
        ]],
    ];

    /** Seed/refresh the platform-global master roles and their capabilities (tenant_id = NULL). */
    public static function seedGlobals(): void
    {
        $pdo = Database::pdo();
        $now = utc_now();
        foreach (self::GLOBAL_ROLES as $slug => $def) {
            $exists = $pdo->prepare('SELECT id FROM roles WHERE tenant_id IS NULL AND slug = ? LIMIT 1');
            $exists->execute([$slug]);
            $roleId = $exists->fetchColumn();
            if ($roleId === false) {
                $roleId = uuid4();
                $pdo->prepare(
                    'INSERT INTO roles (id, tenant_id, slug, name, is_system, is_global, portal_only, sort_order, created_at)
                     VALUES (?,NULL,?,?,1,1,?,?,?)'
                )->execute([$roleId, $slug, $def['name'], $def['portal_only'], $def['sort'], $now]);
            }
            $caps = $def['caps'] === '*' ? Capabilities::all() : $def['caps'];
            foreach ($caps as $cap) {
                $pdo->prepare(
                    'INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
                     VALUES (?,NULL,?,?,?)'
                )->execute([uuid4(), $roleId, $cap, $now]);
            }
        }
    }

    public static function seedFor(string $tenantId): void
    {        $pdo = Database::pdo();
        $now = utc_now();
        foreach (self::SYSTEM_ROLES as $slug => $def) {
            $exists = $pdo->prepare('SELECT id FROM roles WHERE tenant_id = ? AND slug = ? LIMIT 1');
            $exists->execute([$tenantId, $slug]);
            $roleId = $exists->fetchColumn();
            if ($roleId === false) {
                $roleId = uuid4();
                $pdo->prepare(
                    'INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at)
                     VALUES (?,?,?,?,1,?,?,?)'
                )->execute([$roleId, $tenantId, $slug, $def['name'], $def['portal_only'], $def['sort'], $now]);
            }
            // (Re)assert system-role capabilities so a fresh seed is always correct.
            $caps = $def['caps'] === '*' ? Capabilities::all() : $def['caps'];
            foreach ($caps as $cap) {
                $pdo->prepare(
                    'INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at)
                     VALUES (?,?,?,?,?)'
                )->execute([uuid4(), $tenantId, $roleId, $cap, $now]);
            }
        }
    }

    /** All roles available to this tenant: its own rows plus the platform-global masters it hasn't
     *  shadowed with a local role of the same slug. Global rows are marked is_global for the UI. */
    public function all(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM roles WHERE tenant_id = ? ORDER BY sort_order, name'
        );
        $stmt->execute([$this->ctx->tenantId]);
        $roles = $stmt->fetchAll();
        $localSlugs = array_column($roles, 'slug');

        // Add global masters not shadowed by a local role of the same slug.
        $g = Database::pdo()->query('SELECT * FROM roles WHERE tenant_id IS NULL ORDER BY sort_order, name')->fetchAll();
        foreach ($g as $gr) {
            if (in_array($gr['slug'], $localSlugs, true)) continue;
            // v1.171.5: the platform master_admin is the same role as the tenant's built-in Master Admin (tenant_owner) — list it once
            if ($gr['slug'] === 'master_admin' && in_array('tenant_owner', $localSlugs, true)) continue;
            $roles[] = $gr;
        }
        usort($roles, fn($a, $b) => [(int)$a['sort_order'], $a['name']] <=> [(int)$b['sort_order'], $b['name']]);
        foreach ($roles as &$r) {
            $r['capabilities'] = $this->capsForRow($r);
            $r['user_count']   = $this->userCount($r['slug']);
        }
        return $roles;
    }

    /** Capabilities for a role row (global rows read from the NULL-tenant capability set). */
    private function capsForRow(array $r): array
    {
        if (!empty($r['is_global'])) {
            $st = Database::pdo()->prepare('SELECT capability FROM role_capabilities WHERE role_id = ? AND tenant_id IS NULL');
            $st->execute([$r['id']]);
            return array_column($st->fetchAll(), 'capability');
        }
        return $this->capsFor($r['id']);
    }

    public function find(string $roleId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM roles WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$roleId, $this->ctx->tenantId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['capabilities'] = $this->capsFor($roleId);
        $row['user_count']   = $this->userCount($row['slug']);
        return $row;
    }

    /** Capability slugs granted to a role. */
    public function capsFor(string $roleId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT capability FROM role_capabilities WHERE role_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$roleId, $this->ctx->tenantId]);
        return array_column($stmt->fetchAll(), 'capability');
    }

    /**
     * The capabilities held by a role SLUG — the resolution Authz uses. Owner is always
     * all-capabilities even if the row was tampered with. Unknown slug => none.
     */
    public static function capabilitiesForSlug(string $tenantId, string $slug): array
    {
        if ($slug === 'tenant_owner' || $slug === 'master_admin') return Capabilities::all();
        // Tenant-local role wins; otherwise fall back to the platform-global master role.
        $stmt = Database::pdo()->prepare(
            'SELECT rc.capability FROM role_capabilities rc
             JOIN roles r ON r.id = rc.role_id
             WHERE r.tenant_id = ? AND r.slug = ?'
        );
        $stmt->execute([$tenantId, $slug]);
        $caps = array_column($stmt->fetchAll(), 'capability');
        if ($caps !== []) return $caps;
        $stmt = Database::pdo()->prepare(
            'SELECT rc.capability FROM role_capabilities rc
             JOIN roles r ON r.id = rc.role_id
             WHERE r.tenant_id IS NULL AND r.slug = ?'
        );
        $stmt->execute([$slug]);
        return array_column($stmt->fetchAll(), 'capability');
    }

    /**
     * v1.170.9 — is this request's user a provider self-service (portal-only) login? True for the built-in
     * 'producer' slug and for ANY custom role flagged portal_only. Every "provider may only touch their OWN
     * records" confinement must use this, never a comparison with one role name.
     */
    public static function isPortalContext(TenantContext $ctx): bool
    {
        static $memo = [];
        $k = $ctx->tenantId . '|' . $ctx->role;
        return $memo[$k] ??= ($ctx->role === 'producer' || self::isPortalOnly($ctx->tenantId, $ctx->role));
    }

    /** Is this role slug confined to the producer portal? */
    public static function isPortalOnly(string $tenantId, string $slug): bool
    {
        $stmt = Database::pdo()->prepare('SELECT portal_only FROM roles WHERE tenant_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$tenantId, $slug]);
        $v = $stmt->fetchColumn();
        if ($v !== false) return (bool) $v;
        $stmt = Database::pdo()->prepare('SELECT portal_only FROM roles WHERE tenant_id IS NULL AND slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * v1.171.0 (S-1) — capability ceiling. A person who can manage roles may only hand out capabilities they
     * hold themselves, and only Owner-level users may touch the built-in roles. Without this a Manager
     * (who holds team.manage) could create a role with every capability, assign it to themselves and become an
     * effective owner. Owner-level actors (tenant_owner / master_admin) hold every capability, so they are unbounded.
     */
    private function actorCaps(): array
    {
        return self::capabilitiesForSlug($this->ctx->tenantId, $this->ctx->role);
    }

    private function actorIsOwnerLevel(): bool
    {
        return self::isReservedOwnerSlug($this->ctx->role);
    }

    /** Capabilities in $wanted that the actor does not hold. */
    private function beyondCeiling(array $wanted): array
    {
        $wanted = array_values(array_intersect(Capabilities::all(), $wanted));
        return array_values(array_diff($wanted, $this->actorCaps()));
    }

    /** Create a custom role. Returns [ok, message, roleId?]. */
    public function create(string $name, array $capabilities, bool $portalOnly = false): array
    {
        // v1.171.9: clean FIRST (invisible characters removed), then length, then the shared duplicate rule.
        $name = TextNorm::clean($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) return [false, 'Enter a role name (2–80 characters).', null];
        $over = $this->beyondCeiling($capabilities);
        if ($over) return [false, 'You cannot grant capabilities you do not hold yourself: ' . implode(', ', $over) . '.', null];
        if (self::nameTaken($name, $this->ctx->tenantId, null, false)) return [false, 'A role with that name already exists.', null];
        $slug = $this->uniqueSlug($name);
        $caps = array_values(array_intersect(Capabilities::all(), $capabilities));
        // A portal-only role is a provider self-service login; viewing their own
        // documents is its natural default.
        if ($portalOnly && !in_array('documents.view', $caps, true)) {
            $caps[] = 'documents.view';
        }
        $pdo = Database::pdo(); $now = utc_now(); $roleId = uuid4();
        $pdo->prepare(
            'INSERT INTO roles (id, tenant_id, slug, name, is_system, portal_only, sort_order, created_at)
             VALUES (?,?,?,?,0,?,?,?)'
        )->execute([$roleId, $this->ctx->tenantId, $slug, $name, $portalOnly ? 1 : 0, 200, $now]);
        foreach ($caps as $cap) {
            $pdo->prepare('INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at) VALUES (?,?,?,?,?)')
                ->execute([uuid4(), $this->ctx->tenantId, $roleId, $cap, $now]);
        }
        $this->audit('role_created', $roleId);
        return [true, 'Role “' . $name . '” created.', $roleId];
    }

    /**
     * v1.171.8/9: ONE duplicate-name rule for tenant create, tenant rename, global create and global rename.
     * Names compare through TextNorm::fold() (case, spacing, invisible characters, full-width and look-alike letters
     * ignored). Taken = global roles + the built-in constant names + (for a tenant) that tenant's roles — minus the role
     * being renamed. A built-in role keeps its right to its own constant name.
     */
    public static function nameTaken(string $name, ?string $tenantId, ?string $exceptId, bool $isSystemRole): bool
    {
        $wanted = TextNorm::fold($name);
        if ($wanted === '') return true;
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT name FROM roles WHERE tenant_id IS NULL AND id <> ?'); $st->execute([(string) $exceptId]);
        $taken = array_column($st->fetchAll(), 'name');
        if ($tenantId !== null) {
            $st = $pdo->prepare('SELECT name FROM roles WHERE tenant_id = ? AND id <> ?'); $st->execute([$tenantId, (string) $exceptId]);
            $taken = array_merge($taken, array_column($st->fetchAll(), 'name'));
        }
        if (!$isSystemRole) {
            $taken = array_merge($taken, array_column(self::SYSTEM_ROLES, 'name'), array_column(self::GLOBAL_ROLES, 'name'));
        }
        foreach ($taken as $tn) { if (TextNorm::fold((string) $tn) === $wanted) return true; }
        return false;
    }

    /** Update a role's name and capability set. Owner's capabilities are locked to all. */
    public function update(string $roleId, string $name, ?array $capabilities, ?bool $portalOnly = null): array
    {
        $role = $this->find($roleId);
        if (!$role) return [false, 'Role not found.'];
        $name = TextNorm::clean($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) return [false, 'Enter a role name (2–80 characters).'];
        if (self::nameTaken($name, $this->ctx->tenantId, $roleId, !empty($role['is_system']))) return [false, 'A role with that name already exists.'];
        // v1.171.0 (S-1): built-in roles are Owner-only; nobody edits a role more powerful than themselves.
        if (!empty($role['is_system']) && !$this->actorIsOwnerLevel()) {
            return [false, 'Only an owner can change a built-in role.'];
        }
        if (!$this->actorIsOwnerLevel()) {
            $above = array_values(array_diff((array) $role['capabilities'], $this->actorCaps()));
            if ($above) return [false, 'This role holds capabilities you do not have, so you cannot edit it.'];
        }
        // v1.171.9: a request that carries no capability list (rename only) leaves the capability set alone.
        $over = $capabilities === null ? [] : $this->beyondCeiling($capabilities);
        if ($over) return [false, 'You cannot grant capabilities you do not hold yourself: ' . implode(', ', $over) . '.'];

        $pdo = Database::pdo(); $now = utc_now();
        // Rename (system roles may be renamed, keeping their slug).
        $pdo->prepare('UPDATE roles SET name = ?, updated_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$name, $now, $roleId, $this->ctx->tenantId]);
        if ($portalOnly !== null && !$role['is_system']) {
            $pdo->prepare('UPDATE roles SET portal_only = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$portalOnly ? 1 : 0, $roleId, $this->ctx->tenantId]);
        }

        // Owner always holds every capability — never editable, so the org can't be bricked.
        if ($role['slug'] !== 'tenant_owner' && $capabilities !== null) {
            $caps = array_values(array_intersect(Capabilities::all(), $capabilities));
            $pdo->prepare('DELETE FROM role_capabilities WHERE role_id = ? AND tenant_id = ?')
                ->execute([$roleId, $this->ctx->tenantId]);
            foreach ($caps as $cap) {
                $pdo->prepare('INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at) VALUES (?,?,?,?,?)')
                    ->execute([uuid4(), $this->ctx->tenantId, $roleId, $cap, $now]);
            }
        }
        $this->audit('role_updated', $roleId);
        return [true, 'Role updated.'];
    }

    /**
     * Delete a custom role. Refused for system roles, for roles still assigned to users,
     * and never allowed to remove the last team-managing role (no lockout).
     */
    public function delete(string $roleId): array
    {
        $role = $this->find($roleId);
        if (!$role) return [false, 'Role not found.'];
        if ($role['is_system']) return [false, 'Built-in roles cannot be deleted.'];
        if ($this->userCount($role['slug']) > 0) return [false, 'Reassign the users in this role before deleting it.'];
        Database::pdo()->prepare('DELETE FROM roles WHERE id = ? AND tenant_id = ? AND is_system = 0')
            ->execute([$roleId, $this->ctx->tenantId]);
        $this->audit('role_deleted', $roleId);
        return [true, 'Role deleted.'];
    }

    /**
     * v1.171.0 (B-5) — the roles THIS actor may assign to a user: only roles the tenant really has (the platform-global
     * masters are not assignable rows here — offering them used to save "Staff" without saying so), never a reserved
     * owner-level role unless the actor is owner-level, and never a role that holds capabilities the actor lacks.
     */
    public function assignable(): array
    {
        $out = [];
        $stmt = Database::pdo()->prepare('SELECT id, slug, name FROM roles WHERE tenant_id = ? ORDER BY sort_order, name');
        $stmt->execute([$this->ctx->tenantId]);
        $ownerLevel = $this->actorIsOwnerLevel();
        $mine = $this->actorCaps();
        foreach ($stmt->fetchAll() as $r) {
            if (self::isReservedOwnerSlug($r['slug']) && !$ownerLevel) continue;
            if (!$ownerLevel && array_diff($this->capsFor($r['id']), $mine)) continue;
            $out[$r['slug']] = $r['name'];
        }
        return $out;
    }

    // ---- Superadmin: platform-global master roles (tenant_id = NULL) ---------------
    // These edit the master set applied to every tenant. Guarded at the route by platform-admin.

    /** All global master roles with capability sets, for the console. */
    public static function globals(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM roles WHERE tenant_id IS NULL ORDER BY sort_order, name')->fetchAll();
        foreach ($rows as &$r) {
            $st = Database::pdo()->prepare('SELECT capability FROM role_capabilities WHERE role_id = ? AND tenant_id IS NULL');
            $st->execute([$r['id']]);
            $r['capabilities'] = array_column($st->fetchAll(), 'capability');
        }
        return $rows;
    }

    public static function findGlobal(string $roleId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM roles WHERE id = ? AND tenant_id IS NULL LIMIT 1');
        $st->execute([$roleId]);
        $r = $st->fetch();
        if (!$r) return null;
        $c = Database::pdo()->prepare('SELECT capability FROM role_capabilities WHERE role_id = ? AND tenant_id IS NULL');
        $c->execute([$roleId]);
        $r['capabilities'] = array_column($c->fetchAll(), 'capability');
        return $r;
    }

    /** Create a new global master role applied to all tenants. */
    public static function createGlobal(string $name, array $capabilities, bool $portalOnly = false): array
    {
        $name = TextNorm::clean($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) return [false, 'Enter a role name (2–80 characters).', null];
        if (self::nameTaken($name, null, null, false)) return [false, 'A role with that name already exists.', null];   // v1.171.9
        $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $base = trim((string)$base, '_') ?: 'role';
        $slug = $base; $i = 2;
        while (Database::pdo()->query('SELECT 1 FROM roles WHERE tenant_id IS NULL AND slug = ' . Database::pdo()->quote($slug) . ' LIMIT 1')->fetchColumn()) {
            $slug = $base . '_' . $i++;
        }
        $caps = array_values(array_intersect(Capabilities::all(), $capabilities));
        if ($portalOnly && !in_array('documents.view', $caps, true)) $caps[] = 'documents.view';
        $pdo = Database::pdo(); $now = utc_now(); $roleId = uuid4();
        $pdo->prepare(
            'INSERT INTO roles (id, tenant_id, slug, name, is_system, is_global, portal_only, sort_order, created_at)
             VALUES (?,NULL,?,?,0,1,?,?,?)'
        )->execute([$roleId, $slug, $name, $portalOnly ? 1 : 0, 100, $now]);
        foreach ($caps as $cap) {
            $pdo->prepare('INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at) VALUES (?,NULL,?,?,?)')
                ->execute([uuid4(), $roleId, $cap, $now]);
        }
        return [true, 'Global role “' . $name . '” created for all tenants.', $roleId];
    }

    /** Update a global master role's name/capabilities. Master Admin stays all-capabilities. */
    public static function updateGlobal(string $roleId, string $name, array $capabilities): array
    {
        $role = self::findGlobal($roleId);
        if (!$role) return [false, 'Global role not found.'];
        // Built-in master roles are the locked security baseline every tenant relies on: immutable.
        if (in_array($role['slug'], array_keys(self::GLOBAL_ROLES), true)) {
            return [false, 'Built-in master roles are locked and cannot be changed. Create a new global role, or let tenants create their own.'];
        }
        $name = TextNorm::clean($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) return [false, 'Enter a role name (2–80 characters).'];
        if (self::nameTaken($name, null, $roleId, false)) return [false, 'A role with that name already exists.'];   // v1.171.9
        $pdo = Database::pdo(); $now = utc_now();
        $pdo->prepare('UPDATE roles SET name = ?, updated_at = ? WHERE id = ? AND tenant_id IS NULL')
            ->execute([$name, $now, $roleId]);
        if ($role['slug'] !== 'master_admin') { // master admin is always all-caps
            $caps = array_values(array_intersect(Capabilities::all(), $capabilities));
            $pdo->prepare('DELETE FROM role_capabilities WHERE role_id = ? AND tenant_id IS NULL')->execute([$roleId]);
            foreach ($caps as $cap) {
                $pdo->prepare('INSERT IGNORE INTO role_capabilities (id, tenant_id, role_id, capability, created_at) VALUES (?,NULL,?,?,?)')
                    ->execute([uuid4(), $roleId, $cap, $now]);
            }
        }
        return [true, 'Global role updated for all tenants.'];
    }

    /** Delete a non-seed global role (seeded masters are protected). */
    public static function deleteGlobal(string $roleId): array
    {
        $role = self::findGlobal($roleId);
        if (!$role) return [false, 'Global role not found.'];
        if (in_array($role['slug'], array_keys(self::GLOBAL_ROLES), true)) return [false, 'Built-in master roles cannot be deleted.'];
        $inUse = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $inUse->execute([$role['slug']]);
        if ((int)$inUse->fetchColumn() > 0) return [false, 'Users are still assigned this role across tenants.'];
        Database::pdo()->prepare('DELETE FROM roles WHERE id = ? AND tenant_id IS NULL')->execute([$roleId]);
        return [true, 'Global role deleted.'];
    }

    // ---- helpers ----

    private function userCount(string $slug): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = ?');
        $stmt->execute([$this->ctx->tenantId, $slug]);
        return (int) $stmt->fetchColumn();
    }

    /** Slugs that resolve to "every capability". Nobody may create a custom role with one of these names. */
    public const RESERVED_OWNER_SLUGS = ['tenant_owner', 'master_admin'];

    public static function isReservedOwnerSlug(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED_OWNER_SLUGS, true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $base = trim((string) $base, '_') ?: 'role';
        $base = substr($base, 0, 32);
        // v1.170.8 (security) — a custom role named "Master Admin" produced slug master_admin, which
        // capabilitiesFor() treats as all-caps regardless of what was ticked. Never mint a reserved slug.
        if (self::isReservedOwnerSlug($base)) $base .= '_role';
        $slug = $base; $n = 2;
        while (true) {
            $stmt = Database::pdo()->prepare('SELECT 1 FROM roles WHERE tenant_id = ? AND slug = ? LIMIT 1');
            $stmt->execute([$this->ctx->tenantId, $slug]);
            if (!$stmt->fetchColumn()) return $slug;
            $slug = substr($base, 0, 30) . '_' . $n++;
        }
    }

    private function audit(string $action, string $roleId): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO audit_logs (id, tenant_id, user_id, action, entity_type, entity_id, ip_address, created_at)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([uuid4(), $this->ctx->tenantId, $this->ctx->userId, $action, 'roles', $roleId,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
