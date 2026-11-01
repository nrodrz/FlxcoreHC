<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Document requests: the tenant asks its provider (or their office) for a document; the provider
 * fulfils it in the portal. Tenant-owned throughout — after ACCEPT the provider only ever sees a
 * read-only "you provided this" record and can never edit or delete the document.
 *
 *   open ──submit──▶ submitted ──accept──▶ accepted (locked)
 *     ▲                 │ return (with note)
 *     └─────────────────┘        also: open/submitted ──cancel──▶ cancelled (tenant only)
 */
final class DocumentRequestService
{
    public const KINDS = [
        'license'      => 'Professional license (current)',
        'renewal'      => 'License / certification renewal',
        'dea'          => 'DEA registration',
        'malpractice'  => 'Malpractice / liability certificate',
        'board'        => 'Board certification',
        'cv'           => 'Curriculum vitae / work history',
        'gov_id'       => 'Government-issued ID',
        'cme'          => 'CME / CE certificate',
        'caqh'         => 'CAQH attestation',
        'other'        => 'Other document',
    ];

    public function __construct(private readonly TenantContext $ctx) {}

    /** Tenant: create a request and nudge the provider. Returns [ok, message, id]. */
    public function create(string $holderId, ?string $credentialId, string $kind, ?string $message, ?string $dueAt): array
    {
        $kind = isset(self::KINDS[$kind]) ? $kind : 'other';
        $label = self::KINDS[$kind];
        // A target credential must belong to THIS tenant and THIS provider — never accept a foreign id.
        if ($credentialId) {
            $chk = Database::pdo()->prepare('SELECT 1 FROM credentials WHERE id = ? AND tenant_id = ? AND holder_id = ? AND deleted_at IS NULL');
            $chk->execute([$credentialId, $this->ctx->tenantId, $holderId]);
            if (!$chk->fetchColumn()) return [false, 'That credential does not belong to this provider.', null];
        }
        $id = uuid4();
        Database::pdo()->prepare(
            'INSERT INTO document_requests (id, tenant_id, holder_id, credential_id, requested_kind, message, status, due_at, requested_by, requested_at)
             VALUES (?,?,?,?,?,?,\'open\',?,?,?)'
        )->execute([$id, $this->ctx->tenantId, $holderId, $credentialId ?: null, $label, $message ?: null,
                    ($dueAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt)) ? $dueAt : null, $this->ctx->userId, utc_now()]);

        ComplianceEventService::record($this->ctx, 'document_requested', 'Document requested from provider: ' . $label,
            ['holder_id' => $holderId, 'credential_id' => $credentialId ?: null, 'detail' => ['request_id' => $id, 'kind' => $kind]]);
        $this->nudgeProvider($holderId, $label, $message);
        return [true, 'Request sent — the provider will see it in their portal.', $id];
    }

    /** Provider portal: open + recently handled requests for this holder. */
    public function forHolder(string $holderId): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare(
            'SELECT r.*, c.name AS credential_name, d.original_name AS document_name
             FROM document_requests r
             LEFT JOIN credentials c ON c.id = r.credential_id AND c.deleted_at IS NULL
             LEFT JOIN credential_documents d ON d.id = r.document_id AND d.deleted_at IS NULL
             WHERE r.tenant_id = ? AND r.holder_id = ?' . ProviderScope::for($this->ctx)->sql('r.holder_id', false) . '
             ORDER BY FIELD(r.status, \'open\',\'returned\',\'submitted\',\'accepted\',\'cancelled\'), r.requested_at DESC LIMIT 200');
        $st->execute(array_merge([$this->ctx->tenantId, $holderId], ProviderScope::for($this->ctx)->params()));
        return $st->fetchAll();
    }

    /** Tenant: open/submitted requests across the org (for the dashboard / provider page). */
    public function pending(?string $holderId = null): array
    {
        if (SupportAccess::active()) return [];
        $sql = 'SELECT r.*, h.first_name, h.last_name, h.org_name, h.holder_type
                FROM document_requests r JOIN credential_holders h ON h.id = r.holder_id
                WHERE r.tenant_id = ? AND r.status IN (\'open\',\'submitted\',\'returned\')' . ProviderScope::for($this->ctx)->sql('r.holder_id', false);
        $p = array_merge([$this->ctx->tenantId], ProviderScope::for($this->ctx)->params());
        if ($holderId) { $sql .= ' AND r.holder_id = ?'; $p[] = $holderId; }
        $sql .= ' ORDER BY FIELD(r.status,\'submitted\',\'open\',\'returned\'), r.requested_at DESC LIMIT 200';
        $st = Database::pdo()->prepare($sql); $st->execute($p);
        return $st->fetchAll();
    }

    /** Count of requests waiting on this provider (open/returned). */
    public function openCountFor(string $holderId): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM document_requests WHERE tenant_id = ? AND holder_id = ? AND status IN ('open','returned')");
        $st->execute([$this->ctx->tenantId, $holderId]);
        return (int)$st->fetchColumn();
    }

    public function find(string $id): ?array
    {
        $sc = ProviderScope::for($this->ctx);   // need-to-know (v1.170.9)
        $st = Database::pdo()->prepare('SELECT * FROM document_requests WHERE id = ? AND tenant_id = ?' . $sc->sql('holder_id', false) . ' LIMIT 1');
        $st->execute(array_merge([$id, $this->ctx->tenantId], $sc->params()));
        return $st->fetch() ?: null;
    }

    /**
     * Provider portal: attach an uploaded document to the request. Allowed only while open/returned
     * (a replacement while still open supersedes the earlier submission — both retained).
     */
    public function submit(string $requestId, string $documentId, string $submittedBy): array
    {
        $r = $this->find($requestId);
        if (!$r) return [false, 'Request not found.'];
        if (!in_array($r['status'], ['open', 'returned', 'submitted'], true)) return [false, 'This request is closed — the organization has accepted the document.'];
        Database::pdo()->prepare(
            'UPDATE document_requests SET status = \'submitted\', document_id = ?, submitted_at = ?, submitted_by = ?, returned_note = NULL WHERE id = ? AND tenant_id = ?'
        )->execute([$documentId, utc_now(), mb_substr($submittedBy, 0, 160), $requestId, $this->ctx->tenantId]);
        ComplianceEventService::record($this->ctx, 'document_submitted', 'Provider submitted a document: ' . $r['requested_kind'],
            ['holder_id' => $r['holder_id'], 'credential_id' => $r['credential_id'], 'document_id' => $documentId,
             'actor_name' => $submittedBy, 'detail' => ['request_id' => $requestId]]);
        return [true, 'Document submitted — the organization will review it.'];
    }

    /** Tenant: accept → the document is now locked to the tenant; provider is read-only. */
    public function accept(string $requestId): array
    {
        $r = $this->find($requestId);
        if (!$r || $r['status'] !== 'submitted') return [false, 'Nothing to accept.'];
        Database::pdo()->prepare('UPDATE document_requests SET status = \'accepted\', accepted_by = ?, accepted_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$this->ctx->userId, utc_now(), $requestId, $this->ctx->tenantId]);
        ComplianceEventService::record($this->ctx, 'document_accepted', 'Submitted document accepted: ' . $r['requested_kind'],
            ['holder_id' => $r['holder_id'], 'credential_id' => $r['credential_id'], 'document_id' => $r['document_id'], 'detail' => ['request_id' => $requestId]]);
        return [true, 'Accepted. The document is now part of the record.'];
    }

    /** Tenant: return with a note (reopens for the provider). */
    public function returnRequest(string $requestId, string $note): array
    {
        $r = $this->find($requestId);
        if (!$r || $r['status'] !== 'submitted') return [false, 'Nothing to return.'];
        Database::pdo()->prepare('UPDATE document_requests SET status = \'returned\', returned_note = ?, returned_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([mb_substr(trim($note), 0, 500) ?: 'Please resubmit.', utc_now(), $requestId, $this->ctx->tenantId]);
        ComplianceEventService::record($this->ctx, 'document_returned', 'Submitted document returned for correction: ' . $r['requested_kind'],
            ['holder_id' => $r['holder_id'], 'credential_id' => $r['credential_id'], 'detail' => ['request_id' => $requestId, 'note' => $note]]);
        $this->nudgeProvider($r['holder_id'], $r['requested_kind'], 'Please resubmit: ' . $note);
        return [true, 'Returned to the provider with your note.'];
    }

    public function cancel(string $requestId): array
    {
        $r = $this->find($requestId);
        if (!$r || in_array($r['status'], ['accepted', 'cancelled'], true)) return [false, 'Cannot cancel.'];
        Database::pdo()->prepare('UPDATE document_requests SET status = \'cancelled\', cancelled_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([utc_now(), $requestId, $this->ctx->tenantId]);
        return [true, 'Request cancelled.'];
    }

    /** Email nudge only — the request itself lives in the portal; nothing sensitive is emailed. */
    private function nudgeProvider(string $holderId, string $label, ?string $message): void
    {
        try {
            // Through the repository so the encrypted email decrypts (and tenant scope is enforced).
            $h = (new Repository\HolderRepository($this->ctx))->find($holderId);
            if (!$h) return;
            // Recipients: the provider (if they have an email) PLUS any office contacts flagged for requests.
            $recips = [];
            if (!empty($h['email'])) $recips[] = ['email' => $h['email'], 'name' => trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: ($h['org_name'] ?? 'there'), 'locale' => null];
            foreach ((new Repository\ProviderContactRepository($this->ctx))->recipientsFor($holderId, 'requests') as $c) $recips[] = $c;
            if ($recips === []) return;
            $tenantLoc = (string) config('default_locale', 'en');
            try { $t = Database::pdo()->prepare('SELECT default_locale FROM tenants WHERE id = ?'); $t->execute([$this->ctx->tenantId]); $tenantLoc = (string)($t->fetchColumn() ?: $tenantLoc); } catch (\Throwable $e) {}
            foreach ($recips as $rc) {
            $name = $rc['name'];
            $loc = $rc['locale'] ?: $tenantLoc;
            $tl = fn(string $en, array $v = []) => I18n::tl($loc, $en, $v);
            $subject = $tl('{org} requests a document: {label}', ['org' => $this->ctx->tenantName, 'label' => $label]);
            $body = $tl('Hi {name},', ['name' => $name]) . "\n\n"
                  . $tl('{org} has requested a document from you: {label}.', ['org' => $this->ctx->tenantName, 'label' => $label]) . "\n"
                  . ($message ? $tl('Note: {msg}', ['msg' => $message]) . "\n" : '')
                  . "\n" . $tl('Please upload it in your FlxCoreHC portal (do not reply with attachments):') . "\n"
                  . flx_public_base_safe() . '/me/requests' . "\n\n— " . $this->ctx->tenantName . "\n";
            MailService::queue((string)$rc['email'], $subject, $body, 'reminder', $name, $this->ctx->tenantId);
            }
        } catch (\Throwable $e) { /* nudge is best-effort */ }
    }
}
