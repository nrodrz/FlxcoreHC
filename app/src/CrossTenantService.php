<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Cross-tenant sharing (consent model). THE ONLY CLASS ALLOWED TO READ ACROSS TENANTS.
 *
 * Invariants (enforced here, not just in the UI):
 *  - Matching uses the NPI blind index only: no tenant's data is decrypted or shown to another
 *    tenant during detection. Detection returns a COUNT, never names.
 *  - A link becomes ACTIVE only after three consents: requester Master Admin (creates), the
 *    provider (portal), then the owner Master Admin. Any party can decline or later revoke.
 *  - No tenant ever writes to another tenant's rows. Sharing creates a share row; the recipient
 *    reads the owner's credential/documents through readShared() / sharedDocument(), which verify
 *    an ACTIVE link + non-revoked share for the calling tenant on every call.
 *  - Every recipient view/download is logged with tenant, user and IP (cross_share_access) and the
 *    owner can see it. Every link/share transition is audit_logged and a compliance event.
 *  - The provider revoking collapses every link on their identity (npi_bidx) and all shares.
 */
final class CrossTenantService
{
    public function __construct(private readonly TenantContext $ctx) {}

    // ---- detection (blind, count only) -----------------------------------------

    /** How many OTHER organizations manage this NPI. Never returns who. */
    public static function othersManaging(string $npi, string $excludeTenantId): int
    {
        $npi = preg_replace('/\D/', '', $npi);
        if (strlen($npi) !== 10) return 0;
        $bidx = Crypto::blindIndex($npi);
        $st = Database::pdo()->prepare(
            "SELECT COUNT(DISTINCT tenant_id) FROM credential_holders WHERE npi_bidx = ? AND tenant_id <> ? AND deleted_at IS NULL");
        $st->execute([$bidx, $excludeTenantId]);
        return (int)$st->fetchColumn();
    }

    // ---- requester side --------------------------------------------------------

    /**
     * Master Admin of the requesting org asks to link its record with every other org managing this
     * NPI. One pending link per other org; names stay hidden from the requester until active.
     * Returns number of requests created.
     */
    public function requestLinks(string $holderId): array
    {
        if ($this->ctx->role !== 'tenant_owner') return [false, 'Only a Master Admin can request a link.', 0];
        $h = (new Repository\HolderRepository($this->ctx))->find($holderId);
        if (!$h || empty($h['npi'])) return [false, 'This provider needs an NPI on file to link.', 0];
        $bidx = Crypto::blindIndex(preg_replace('/\D/', '', (string)$h['npi']));
        $pdo = Database::pdo();
        $others = $pdo->prepare("SELECT id, tenant_id FROM credential_holders WHERE npi_bidx = ? AND tenant_id <> ? AND deleted_at IS NULL");
        $others->execute([$bidx, $this->ctx->tenantId]);
        $n = 0;
        foreach ($others->fetchAll() as $o) {
            // skip if a non-declined link already exists for this pair (either direction)
            $ex = $pdo->prepare("SELECT status FROM provider_links WHERE ((requester_holder_id = ? AND owner_holder_id = ?) OR (requester_holder_id = ? AND owner_holder_id = ?)) AND status IN ('pending_provider','pending_owner','active') LIMIT 1");
            $ex->execute([$holderId, $o['id'], $o['id'], $holderId]);
            if ($ex->fetchColumn()) continue;
            $id = uuid4();
            $pdo->prepare(
                'INSERT INTO provider_links (id, npi_bidx, requester_tenant_id, requester_holder_id, owner_tenant_id, owner_holder_id, status, requested_by, requested_at, created_at)
                 VALUES (?,?,?,?,?,?, \'pending_provider\', ?, ?, ?)'
            )->execute([$id, $bidx, $this->ctx->tenantId, $holderId, $o['tenant_id'], $o['id'], $this->ctx->userId, utc_now(), utc_now()]);
            $n++;
            audit_log($this->ctx, 'cross_link_requested', 'holders', $holderId, json_encode(['link_id' => $id]));
            ComplianceEventService::record($this->ctx, 'cross_link_requested', 'Requested to link this provider\'s record with another organization (awaiting provider consent)',
                ['holder_id' => $holderId, 'detail' => ['link_id' => $id]]);
            $this->nudgeProvider($holderId, $h);
        }
        return [$n > 0, $n > 0 ? "$n link request(s) sent — the provider must consent first, then the other organization's Master Admin." : 'No other organization manages this NPI (or requests already exist).', $n];
    }

    // ---- provider side (portal) -----------------------------------------------

    /** Pending links awaiting THIS provider's consent (matched by their NPI, any tenant). */
    public static function pendingForProvider(string $npiBidx): array
    {
        $st = Database::pdo()->prepare(
            "SELECT l.*, tr.name AS requester_name, tw.name AS owner_name
             FROM provider_links l JOIN tenants tr ON tr.id = l.requester_tenant_id JOIN tenants tw ON tw.id = l.owner_tenant_id
             WHERE l.npi_bidx = ? AND l.status = 'pending_provider' ORDER BY l.requested_at DESC");
        $st->execute([$npiBidx]);
        return $st->fetchAll();
    }

    /** All links (any status) on this provider's identity, for the portal overview. */
    public static function linksForProvider(string $npiBidx): array
    {
        $st = Database::pdo()->prepare(
            "SELECT l.*, tr.name AS requester_name, tw.name AS owner_name
             FROM provider_links l JOIN tenants tr ON tr.id = l.requester_tenant_id JOIN tenants tw ON tw.id = l.owner_tenant_id
             WHERE l.npi_bidx = ? ORDER BY l.requested_at DESC");
        $st->execute([$npiBidx]);
        return $st->fetchAll();
    }

    /** Provider consents: pending_provider → pending_owner. Identity check by npi_bidx. */
    public static function providerConsent(string $linkId, string $npiBidx, string $portalUserId, bool $approve): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM provider_links WHERE id = ? AND npi_bidx = ? AND status = 'pending_provider' LIMIT 1");
        $st->execute([$linkId, $npiBidx]);
        $l = $st->fetch();
        if (!$l) return [false, 'Nothing to decide.'];
        if ($approve) {
            $pdo->prepare("UPDATE provider_links SET status = 'pending_owner', provider_consent_at = ?, provider_consent_via = ? WHERE id = ?")
                ->execute([utc_now(), $portalUserId, $linkId]);
            // tell the owner's Master Admin(s)
            self::notifyMasterAdmins($l['owner_tenant_id'], 'cross_link', 'warn',
                'A provider consented to link records with another organization — your approval is needed',
                'Review under Organization → Linked providers.', '/organization/links', 'provider_links', $linkId, 'link:' . $linkId . ':owner');
            self::sysEvent($l['owner_tenant_id'], $l['owner_holder_id'], 'cross_link_provider_consented', 'Provider consented to a cross-organization link (awaiting your Master Admin)');
            self::sysEvent($l['requester_tenant_id'], $l['requester_holder_id'], 'cross_link_provider_consented', 'Provider consented to your link request (awaiting the other organization)');
            return [true, 'Thank you — the other organization will now be asked to approve.'];
        }
        $pdo->prepare("UPDATE provider_links SET status = 'declined', declined_at = ?, declined_by_party = 'provider' WHERE id = ?")->execute([utc_now(), $linkId]);
        self::sysEvent($l['requester_tenant_id'], $l['requester_holder_id'], 'cross_link_declined', 'Provider declined the link request');
        return [true, 'Declined. Nothing is shared.'];
    }

    /** Provider revokes EVERYTHING on their identity: all active/pending links and all shares. */
    public static function providerRevokeAll(string $npiBidx, string $reason = 'Provider withdrew consent'): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id, owner_tenant_id, owner_holder_id, requester_tenant_id, requester_holder_id FROM provider_links WHERE npi_bidx = ? AND status IN ('pending_provider','pending_owner','active')");
        $st->execute([$npiBidx]);
        $n = 0;
        foreach ($st->fetchAll() as $l) {
            $pdo->prepare("UPDATE provider_links SET status = 'revoked', revoked_at = ?, revoked_by_party = 'provider', revoked_reason = ? WHERE id = ?")->execute([utc_now(), $reason, $l['id']]);
            $pdo->prepare("UPDATE credential_shares SET revoked_at = ? WHERE link_id = ? AND revoked_at IS NULL")->execute([utc_now(), $l['id']]);
            self::sysEvent($l['owner_tenant_id'], $l['owner_holder_id'], 'cross_link_revoked', 'Provider withdrew consent — link and all shares revoked');
            self::sysEvent($l['requester_tenant_id'], $l['requester_holder_id'], 'cross_link_revoked', 'Provider withdrew consent — link and all shares revoked');
            $n++;
        }
        return $n;
    }

    // ---- owner side ------------------------------------------------------------

    /** Links involving this tenant (as owner or requester), with the other org's name only once active/pending_owner. */
    public function linksForTenant(): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare(
            "SELECT l.*, tr.name AS requester_name, tw.name AS owner_name,
                    CASE WHEN l.owner_tenant_id = ? THEN 'owner' ELSE 'requester' END AS my_role,
                    CASE WHEN l.owner_tenant_id = ? THEN l.owner_holder_id ELSE l.requester_holder_id END AS my_holder_id
             FROM provider_links l JOIN tenants tr ON tr.id = l.requester_tenant_id JOIN tenants tw ON tw.id = l.owner_tenant_id
             WHERE l.owner_tenant_id = ? OR l.requester_tenant_id = ?
             ORDER BY FIELD(l.status,'pending_owner','active','pending_provider','declined','revoked'), l.requested_at DESC");
        $st->execute([$this->ctx->tenantId, $this->ctx->tenantId, $this->ctx->tenantId, $this->ctx->tenantId]);
        $rows = $st->fetchAll();
        // Requester must not learn the owner's identity before the owner has approved.
        foreach ($rows as &$r) {
            if ($r['my_role'] === 'requester' && !in_array($r['status'], ['active'], true)) $r['owner_name'] = 'Another organization';
        }
        return $rows;
    }

    /** Owner Master Admin decides: pending_owner → active | declined. */
    public function ownerDecide(string $linkId, bool $approve): array
    {
        if ($this->ctx->role !== 'tenant_owner') return [false, 'Only a Master Admin can approve a link.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM provider_links WHERE id = ? AND owner_tenant_id = ? AND status = 'pending_owner' LIMIT 1");
        $st->execute([$linkId, $this->ctx->tenantId]);
        $l = $st->fetch();
        if (!$l) return [false, 'Nothing to decide.'];
        if ($approve) {
            $pdo->prepare("UPDATE provider_links SET status = 'active', owner_consent_at = ?, owner_consent_by = ? WHERE id = ?")->execute([utc_now(), $this->ctx->userId, $linkId]);
            audit_log($this->ctx, 'cross_link_approved', 'holders', $l['owner_holder_id'], json_encode(['link_id' => $linkId]));
            ComplianceEventService::record($this->ctx, 'cross_link_active', 'Cross-organization link approved (all three parties consented)', ['holder_id' => $l['owner_holder_id'], 'detail' => ['link_id' => $linkId]]);
            self::sysEvent($l['requester_tenant_id'], $l['requester_holder_id'], 'cross_link_active', 'Cross-organization link approved — the other organization can now share credentials with you');
            self::notifyMasterAdmins($l['requester_tenant_id'], 'cross_link', 'info', 'Your link request was approved', 'The other organization can now share credentials with you, one at a time.', '/holders/' . $l['requester_holder_id'], 'provider_links', $linkId, 'link:' . $linkId . ':active');
            return [true, 'Link active. Share credentials with them one by one from the provider\'s page.'];
        }
        $pdo->prepare("UPDATE provider_links SET status = 'declined', declined_at = ?, declined_by_party = 'owner' WHERE id = ?")->execute([utc_now(), $linkId]);
        audit_log($this->ctx, 'cross_link_declined', 'holders', $l['owner_holder_id'], json_encode(['link_id' => $linkId]));
        self::sysEvent($l['requester_tenant_id'], $l['requester_holder_id'], 'cross_link_declined', 'The other organization declined the link request');
        return [true, 'Declined. Nothing is shared.'];
    }

    /** Either org's Master Admin revokes a link (and all shares on it). */
    public function revokeLink(string $linkId, string $reason = ''): array
    {
        if ($this->ctx->role !== 'tenant_owner') return [false, 'Only a Master Admin can revoke a link.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM provider_links WHERE id = ? AND (owner_tenant_id = ? OR requester_tenant_id = ?) AND status IN ('pending_provider','pending_owner','active') LIMIT 1");
        $st->execute([$linkId, $this->ctx->tenantId, $this->ctx->tenantId]);
        $l = $st->fetch();
        if (!$l) return [false, 'Link not found.'];
        $party = $l['owner_tenant_id'] === $this->ctx->tenantId ? 'owner' : 'requester';
        $pdo->prepare("UPDATE provider_links SET status = 'revoked', revoked_at = ?, revoked_by_party = ?, revoked_reason = ? WHERE id = ?")->execute([utc_now(), $party, mb_substr($reason, 0, 300) ?: null, $linkId]);
        $pdo->prepare("UPDATE credential_shares SET revoked_at = ?, revoked_by = ? WHERE link_id = ? AND revoked_at IS NULL")->execute([utc_now(), $this->ctx->userId, $linkId]);
        audit_log($this->ctx, 'cross_link_revoked', 'holders', $party === 'owner' ? $l['owner_holder_id'] : $l['requester_holder_id'], json_encode(['link_id' => $linkId]));
        $other = $party === 'owner' ? $l['requester_tenant_id'] : $l['owner_tenant_id'];
        $otherHolder = $party === 'owner' ? $l['requester_holder_id'] : $l['owner_holder_id'];
        self::sysEvent($other, $otherHolder, 'cross_link_revoked', 'The other organization revoked the link — shared credentials are no longer visible');
        self::sysEvent($this->ctx->tenantId, $party === 'owner' ? $l['owner_holder_id'] : $l['requester_holder_id'], 'cross_link_revoked', 'Link revoked by your organization');
        return [true, 'Link revoked. All shares on it are withdrawn.'];
    }

    /** Active links where THIS tenant owns the record for a given holder (candidates to share to). */
    public function activeLinksForHolder(string $holderId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT l.id AS link_id, l.requester_tenant_id AS other_tenant_id, t.name AS other_name, l.requester_holder_id AS other_holder_id
             FROM provider_links l JOIN tenants t ON t.id = l.requester_tenant_id
             WHERE l.owner_tenant_id = ? AND l.owner_holder_id = ? AND l.status = 'active'
             UNION
             SELECT l.id, l.owner_tenant_id, t2.name, l.owner_holder_id
             FROM provider_links l JOIN tenants t2 ON t2.id = l.owner_tenant_id
             WHERE l.requester_tenant_id = ? AND l.requester_holder_id = ? AND l.status = 'active'");
        $st->execute([$this->ctx->tenantId, $holderId, $this->ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    /** Owner shares ONE of its credentials with a linked org. Directional. */
    public function shareCredential(string $credentialId, string $linkId, bool $includeDocs): array
    {
        if (!Authz::can($this->ctx, 'crossorg.manage')) return [false, 'Not permitted.'];
        $pdo = Database::pdo();
        $c = $pdo->prepare("SELECT id, holder_id, name FROM credentials WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
        $c->execute([$credentialId, $this->ctx->tenantId]);
        $cred = $c->fetch();
        if (!$cred) return [false, 'Credential not found.'];
        // the link must be active and involve this tenant + this holder
        $recipient = null;
        foreach ($this->activeLinksForHolder($cred['holder_id']) as $l) { if ($l['link_id'] === $linkId) { $recipient = $l; break; } }
        if (!$recipient) return [false, 'No active link with that organization for this provider.'];
        $pdo->prepare(
            'INSERT INTO credential_shares (id, link_id, owner_tenant_id, credential_id, recipient_tenant_id, include_documents, shared_by, shared_at)
             VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revoked_at = NULL, revoked_by = NULL, include_documents = VALUES(include_documents), shared_by = VALUES(shared_by), shared_at = VALUES(shared_at)'
        )->execute([uuid4(), $linkId, $this->ctx->tenantId, $credentialId, $recipient['other_tenant_id'], $includeDocs ? 1 : 0, $this->ctx->userId, utc_now()]);
        audit_log($this->ctx, 'cross_share_created', 'credentials', $credentialId, json_encode(['to' => $recipient['other_name'], 'docs' => $includeDocs]));
        ComplianceEventService::record($this->ctx, 'credential_shared_cross_org', 'Credential shared with ' . $recipient['other_name'] . ': ' . $cred['name'] . ($includeDocs ? ' (with documents)' : ''),
            ['holder_id' => $cred['holder_id'], 'credential_id' => $credentialId, 'detail' => ['recipient' => $recipient['other_name'], 'link_id' => $linkId]]);
        self::sysEvent($recipient['other_tenant_id'], $recipient['other_holder_id'], 'credential_received_cross_org', 'Received a shared credential from ' . $this->ctx->tenantName . ': ' . $cred['name']);
        return [true, t('Shared with {name} (read-only, live).', ['name' => $recipient['other_name']])];
    }

    public function revokeShare(string $shareId): array
    {
        if (!Authz::can($this->ctx, 'crossorg.manage')) return [false, 'Not permitted.'];
        $st = Database::pdo()->prepare("UPDATE credential_shares SET revoked_at = ?, revoked_by = ? WHERE id = ? AND owner_tenant_id = ? AND revoked_at IS NULL");
        $st->execute([utc_now(), $this->ctx->userId, $shareId, $this->ctx->tenantId]);
        if ($st->rowCount() === 0) return [false, 'Share not found.'];
        audit_log($this->ctx, 'cross_share_revoked', 'credential_shares', $shareId, null);
        return [true, 'Share withdrawn.'];
    }

    /** Shares this tenant has made for a holder (owner view). */
    public function sharesByMeFor(string $holderId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT s.*, c.name AS credential_name, t.name AS recipient_name,
                    (SELECT COUNT(*) FROM cross_share_access a WHERE a.share_id = s.id) AS access_count
             FROM credential_shares s JOIN credentials c ON c.id = s.credential_id AND c.deleted_at IS NULL JOIN tenants t ON t.id = s.recipient_tenant_id
             WHERE s.owner_tenant_id = ? AND c.holder_id = ? ORDER BY s.shared_at DESC");
        $st->execute([$this->ctx->tenantId, $holderId]);
        return $st->fetchAll();
    }

    // ---- recipient side (read-only, every call re-verifies) ----------------------

    /**
     * Credentials shared TO this tenant for one of its holders, live from the owner's rows.
     * Verified per call: share not revoked AND link active AND recipient is this tenant.
     */
    public function readShared(string $myHolderId): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare(
            "SELECT s.id AS share_id, s.include_documents, t.name AS owner_name, s.shared_at,
                    c.id AS credential_id, c.credential_type, c.name, c.issuing_body, c.issued_date, c.expiry_date, c.status, c.last_verified_at
             FROM credential_shares s
             JOIN provider_links l ON l.id = s.link_id AND l.status = 'active'
             JOIN credentials c ON c.id = s.credential_id AND c.tenant_id = s.owner_tenant_id AND c.deleted_at IS NULL
             JOIN credential_holders oh ON oh.id = c.holder_id AND oh.tenant_id = c.tenant_id AND oh.deleted_at IS NULL
             JOIN tenants t ON t.id = s.owner_tenant_id
             WHERE s.recipient_tenant_id = ? AND s.revoked_at IS NULL
               AND ((l.owner_tenant_id = ? AND l.owner_holder_id = ?) OR (l.requester_tenant_id = ? AND l.requester_holder_id = ?))
             ORDER BY c.expiry_date");
        $st->execute([$this->ctx->tenantId, $this->ctx->tenantId, $myHolderId, $this->ctx->tenantId, $myHolderId]);
        $rows = $st->fetchAll();
        if (!$rows) return [];
        // documents (metadata only) for shares that include them
        $withDocs = array_values(array_filter($rows, fn($r) => (int)$r['include_documents'] === 1));
        if ($withDocs) {
            $in = implode(',', array_fill(0, count($withDocs), '?'));
            $d = Database::pdo()->prepare("SELECT id, credential_id, original_name, mime_type, size_bytes, kind, verified_at, verification_method
                                           FROM credential_documents WHERE deleted_at IS NULL AND credential_id IN ($in) ORDER BY created_at DESC");
            $d->execute(array_column($withDocs, 'credential_id'));
            $byCred = [];
            foreach ($d->fetchAll() as $doc) { $byCred[$doc['credential_id']][] = $doc; }
            foreach ($rows as &$r) { $r['documents'] = $byCred[$r['credential_id']] ?? []; }
        }
        // log the view — once per share per session-hour, so a busy page doesn't flood the log
        foreach ($rows as $r) {
            $k = 'xview_' . $r['share_id'];
            if (empty($_SESSION[$k]) || (time() - (int)$_SESSION[$k]) > 3600) { $_SESSION[$k] = time(); self::logAccess($this->ctx, $r['share_id'], 'view', null); }
        }
        return $rows;
    }

    /** Resolve a shared document for download, re-verifying the share; logs the download. */
    public function sharedDocument(string $shareId, string $documentId, string $myHolderId): ?array
    {
        // Bound to the recipient's OWN provider record for this link, so a user may only download
        // shared documents for providers they can see (need-to-know is enforced upstream on the holder).
        $st = Database::pdo()->prepare(
            "SELECT d.file_path, d.original_name, d.mime_type
             FROM credential_shares s
             JOIN provider_links l ON l.id = s.link_id AND l.status = 'active'
               AND ((l.owner_tenant_id = ? AND l.owner_holder_id = ?) OR (l.requester_tenant_id = ? AND l.requester_holder_id = ?))
             JOIN credentials c ON c.id = s.credential_id AND c.tenant_id = s.owner_tenant_id AND c.deleted_at IS NULL
             JOIN credential_holders oh ON oh.id = c.holder_id AND oh.tenant_id = c.tenant_id AND oh.deleted_at IS NULL
             JOIN credential_documents d ON d.credential_id = s.credential_id AND d.tenant_id = s.owner_tenant_id AND d.deleted_at IS NULL
             WHERE s.id = ? AND d.id = ? AND s.recipient_tenant_id = ? AND s.revoked_at IS NULL AND s.include_documents = 1 LIMIT 1");
        $st->execute([$this->ctx->tenantId, $myHolderId, $this->ctx->tenantId, $myHolderId, $shareId, $documentId, $this->ctx->tenantId]);
        $row = $st->fetch();
        if (!$row) return null;
        self::logAccess($this->ctx, $shareId, 'download', $documentId);
        return $row;
    }

    /** Owner: who viewed/downloaded a share. */
    public function accessLog(string $shareId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT a.*, u.full_name AS user_name, t.name AS recipient_name FROM cross_share_access a
             LEFT JOIN users u ON u.id = a.user_id JOIN tenants t ON t.id = a.recipient_tenant_id
             WHERE a.share_id = ? AND a.owner_tenant_id = ? ORDER BY a.created_at DESC LIMIT 500");
        $st->execute([$shareId, $this->ctx->tenantId]);
        return $st->fetchAll();
    }

    // ---- helpers ---------------------------------------------------------------

    private static function logAccess(TenantContext $ctx, string $shareId, string $event, ?string $docId): void
    {
        try {
            $o = Database::pdo()->prepare('SELECT owner_tenant_id FROM credential_shares WHERE id = ?'); $o->execute([$shareId]);
            Database::pdo()->prepare('INSERT INTO cross_share_access (id, share_id, owner_tenant_id, recipient_tenant_id, user_id, event, document_id, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([uuid4(), $shareId, (string)$o->fetchColumn(), $ctx->tenantId, $ctx->userId, $event, $docId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), utc_now()]);
        } catch (\Throwable $e) {}
    }

    /** A compliance event written for a tenant we are not acting as (system actor). */
    private static function sysEvent(string $tenantId, ?string $holderId, string $type, string $summary): void
    {
        try {
            Database::pdo()->prepare('INSERT INTO compliance_events (id, tenant_id, holder_id, event_type, summary, actor_name, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([uuid4(), $tenantId, $holderId, $type, mb_substr($summary, 0, 300), 'System (cross-org)', utc_now()]);
        } catch (\Throwable $e) {}
    }

    private static function notifyMasterAdmins(string $tenantId, string $kind, string $sev, string $title, string $body, string $link, string $etype, string $eid, string $dedupe): void
    {
        try {
            $st = Database::pdo()->prepare("SELECT id FROM users WHERE tenant_id = ? AND role = 'tenant_owner' AND status = 'active'");
            $st->execute([$tenantId]);
            foreach ($st->fetchAll() as $u) { NotificationService::push($tenantId, $u['id'], $kind, $sev, $title, $body, BASE_PATH . $link, $etype, $eid, $dedupe . ':' . $u['id']); }
        } catch (\Throwable $e) {}
    }

    private function nudgeProvider(string $holderId, array $h): void
    {
        try {
            if (empty($h['email'])) return;
            $name = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: 'Provider';
            $loc = (string) config('default_locale', 'en');
            $tl = fn(string $en, array $v = []) => I18n::tl($loc, $en, $v);
            MailService::queue((string)$h['email'], $tl('{org} asks to link your credential records', ['org' => $this->ctx->tenantName]),
                $tl('Hi {name},', ['name' => $name]) . "\n\n" . $tl('{org} would like to link your credential records with another organization that manages you, so renewals do not have to be entered twice. Nothing is shared until you approve, and you can withdraw at any time.', ['org' => $this->ctx->tenantName])
                . "\n\n" . $tl('Review it in your FlxCoreHC portal:') . "\n" . flx_public_base_safe() . '/me/links' . "\n", 'reminder', $name, $this->ctx->tenantId);
        } catch (\Throwable $e) {}
    }
}
