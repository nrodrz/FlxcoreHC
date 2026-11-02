<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Signed license records (Ownership & White-Label Standard, licensing). The platform operator issues them in Console → Licenses.
 *
 * The signed fields, in this order: serial, agreement, licensee, workspace_id, kind, plan, modules, users, term_start, term_end, notes,
 * issued_at, deployment — encoded as JSON and signed with Ed25519 (libsodium). key_id and signature sit beside them. The private key is
 * kept sealed in the platform settings; the public key is in app/config.php (`license_public_key`, written by the installer) or, on an
 * installation that has none, in the platform settings. A row that does not verify is ignored, so a changed row switches nothing on.
 */
final class LicenseService
{
    public const FIELDS = ['serial', 'agreement', 'licensee', 'workspace_id', 'kind', 'plan', 'modules', 'users', 'term_start', 'term_end', 'notes', 'issued_at', 'deployment'];
    public const KINDS  = ['paid' => 'Paid', 'free' => 'Free', 'pilot' => 'Pilot'];
    /** Optional modules a license can switch on. Everything else is the core and is always available. */
    public const MODULES = ['documents' => 'Document management', 'scheduling' => 'Scheduling', 'analytics' => 'Analytics & reports', 'network' => 'Provider network'];
    public const GRACE_DAYS = 14;
    public const TRIAL_DAYS = 30;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_verify_detached');
    }

    /** A new key pair: [public (base64), private (base64)]. */
    public static function generateKeys(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [base64_encode(sodium_crypto_sign_publickey($kp)), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    public static function keyId(string $publicB64): string
    {
        return substr(hash('sha256', $publicB64), 0, 16);
    }

    /** The installation's public key, or '' when there is none yet. */
    public static function publicKey(): string
    {
        $c = (string) config('license_public_key', '');
        return $c !== '' ? $c : (string) (SettingsService::get('license_public_key') ?? '');
    }

    /** Make sure a key pair exists (the installer makes it; an installation without one gets it on first use). Returns the public key. */
    public static function ensureKeys(): string
    {
        $pub = self::publicKey();
        if ($pub !== '' && (string) SettingsService::get('license_signing_key') !== '') return $pub;
        [$newPub, $priv] = self::generateKeys();
        if ($pub === '') { SettingsService::put(['license_public_key' => $newPub]); $pub = $newPub; }
        else { throw new \RuntimeException('The license signing key is missing for the configured public key.'); }
        self::storePrivateKey($priv);
        return $pub;
    }

    public static function storePrivateKey(string $privB64): void
    {
        SettingsService::put(['license_signing_key' => base64_encode((string) Crypto::encrypt($privB64, 'license-signing-key'))]);
    }

    private static function privateKey(): string
    {
        $blob = (string) (SettingsService::get('license_signing_key') ?? '');
        $p = $blob === '' ? null : Crypto::decrypt(base64_decode($blob, true) ?: '', 'license-signing-key');
        if ($p === null || $p === '') throw new \RuntimeException('The license signing key is not available.');
        return $p;
    }

    /** The exact bytes that are signed for a record (fields in the fixed order). */
    public static function canonical(array $r): string
    {
        $o = [];
        foreach (self::FIELDS as $f) {
            $v = $r[$f] ?? null;
            if ($f === 'modules' && is_string($v)) $v = array_values(array_filter(explode(',', $v), fn($x) => $x !== ''));
            if ($f === 'users' && $v !== null) $v = (int) $v;
            $o[$f] = $v;
        }
        return json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function sign(array $r, string $privB64): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::canonical($r), base64_decode($privB64)));
    }

    public static function verify(array $row): bool
    {
        if (!self::available()) return false;
        $pub = self::publicKey();
        if ($pub === '' || ($row['key_id'] ?? '') !== self::keyId($pub)) return false;
        $sig = base64_decode((string) ($row['signature'] ?? ''), true);
        $pk = base64_decode($pub, true);
        if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        try { return sodium_crypto_sign_verify_detached($sig, self::canonical($row), $pk); } catch (\Throwable $e) { return false; }
    }

    /**
     * Issue a license. $in: tenant_id, kind, plan, modules[], users (''/null = unlimited), term_start, term_end (''/null = no end), agreement, notes.
     * @return array{0:bool,1:string} [ok, message or serial]
     */
    public static function issue(TenantContext $ctx, array $in): array
    {
        if (!self::available()) return [false, 'libsodium is not available on this server, so licenses cannot be signed.'];
        $pdo = Database::pdo();
        $t = $pdo->prepare('SELECT id, name, org_shape FROM tenants WHERE id = ? LIMIT 1'); $t->execute([(string) ($in['tenant_id'] ?? '')]);
        $tenant = $t->fetch();
        if (!$tenant) return [false, 'Choose an organization.'];
        $kind = (string) ($in['kind'] ?? 'paid');
        if (!isset(self::KINDS[$kind])) return [false, 'Choose a license kind.'];
        $mods = array_values(array_intersect(array_keys(self::MODULES), (array) ($in['modules'] ?? [])));
        $users = ($in['users'] ?? '') === '' ? null : (int) $in['users'];
        if ($users !== null && $users < 1) return [false, 'The user limit must be at least 1 (or empty for unlimited).'];
        $start = (string) ($in['term_start'] ?? ''); $end = ($in['term_end'] ?? '') === '' ? null : (string) $in['term_end'];
        $okDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) && checkdate((int) substr((string) $d, 5, 2), (int) substr((string) $d, 8, 2), (int) substr((string) $d, 0, 4));
        if (!$okDate($start)) return [false, 'Enter the term start date.'];
        if ($end !== null && (!$okDate($end) || $end < $start)) return [false, 'The term end must be a date on or after the start.'];
        $plan = trim((string) ($in['plan'] ?? '')) ?: null;
        $year = gmdate('Y');
        $n = (int) $pdo->query("SELECT COUNT(*) FROM tenant_licenses WHERE serial LIKE 'LIC-$year-%'")->fetchColumn() + 1;
        $rec = [
            'serial' => sprintf('LIC-%s-%04d', $year, $n), 'agreement' => trim((string) ($in['agreement'] ?? '')) ?: null, 'licensee' => (string) $tenant['name'],
            'workspace_id' => OrgService::workspaceId((string) $tenant['id']), 'kind' => $kind, 'plan' => $plan, 'modules' => implode(',', $mods), 'users' => $users,
            'term_start' => $start, 'term_end' => $end, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'issued_at' => utc_now(), 'deployment' => 'hosted',
        ];
        $pub = self::ensureKeys();
        $rec['key_id'] = self::keyId($pub);
        $rec['signature'] = self::sign($rec, self::privateKey());
        $pdo->prepare('INSERT INTO tenant_licenses (id, tenant_id, serial, agreement, licensee, workspace_id, kind, plan, modules, users, term_start, term_end, notes, issued_at, deployment, key_id, signature, issued_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([uuid4(), $tenant['id'], $rec['serial'], $rec['agreement'], $rec['licensee'], $rec['workspace_id'], $rec['kind'], $rec['plan'], $rec['modules'], $rec['users'],
                       $rec['term_start'], $rec['term_end'], $rec['notes'], $rec['issued_at'], $rec['deployment'], $rec['key_id'], $rec['signature'], $ctx->userId]);
        audit_log($ctx, 'license_issued', 'tenant_licenses', substr($rec['serial'], 0, 36), json_encode(['tenant' => $tenant['name'], 'serial' => $rec['serial'], 'kind' => $kind, 'plan' => $plan, 'modules' => $mods, 'users' => $users, 'term_start' => $start, 'term_end' => $end], JSON_UNESCAPED_UNICODE));
        return [true, $rec['serial']];
    }

    /** Revoke a license: the organization is left with no license (a trial until its date, then the core only). */
    public static function revoke(TenantContext $ctx, string $serial): array
    {
        $pdo = Database::pdo();
        $q = $pdo->prepare('SELECT id, tenant_id FROM tenant_licenses WHERE serial = ? AND revoked_at IS NULL LIMIT 1'); $q->execute([$serial]);
        $row = $q->fetch();
        if (!$row) return [false, 'That license is not active.'];
        $pdo->prepare('UPDATE tenant_licenses SET revoked_at = ?, revoked_by = ? WHERE id = ?')->execute([utc_now(), $ctx->userId, $row['id']]);
        audit_log($ctx, 'license_revoked', 'tenant_licenses', substr($serial, 0, 36), json_encode(['serial' => $serial]));
        return [true, $serial];
    }

    /** One row per organization for the console: its entitlement now and how many users it has. */
    public static function overview(): array
    {
        $rows = Database::pdo()->query("SELECT t.id, t.name, t.org_shape, t.status, t.workspace_id,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.status IN ('active','invited') AND u.role <> 'producer') AS users_in_use
            FROM tenants t ORDER BY t.name")->fetchAll();
        foreach ($rows as &$r) $r['entitlement'] = Entitlement::forTenant((string) $r['id']);
        return $rows;
    }

    /** All licenses of one organization, newest first, with their signature check. */
    public static function history(string $tenantId): array
    {
        try {
            $q = Database::pdo()->prepare('SELECT * FROM tenant_licenses WHERE tenant_id = ? ORDER BY issued_at DESC, serial DESC'); $q->execute([$tenantId]);
            return array_map(fn($r) => $r + ['verified' => self::verify($r)], $q->fetchAll());
        } catch (\Throwable $e) { return []; }
    }
}
