<?php

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Security & compliance posture.
 *
 * Healthcare organizations handling provider credentialing data are routinely asked by payers, hospitals and
 * own compliance officers to describe the safeguards behind the software they use. This assembles
 * that answer from what the system actually does, and exports it as a PDF the organization can hand over.
 *
 * Deliberately factual: every control listed is one this codebase implements. Controls that depend
 * on the organization's own configuration are reported live (on/off) rather than asserted, so the
 * document never overstates the posture. It describes the software, not the organization's operations —
 * a written policy, workforce training, and a signed BAA remain the organization's responsibility, and
 * the document says so.
 */
final class CompliancePostureService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * The control catalogue, grouped by domain. 'live' entries are resolved against the tenant's
     * real configuration at render time; everything else is a property of the software itself.
     *
     * @return array<string,array{summary:string,controls:array<int,array{name:string,detail:string,state?:string}>}>
     */
    public function sections(): array
    {
        $s = $this->state();

        return [
            'Data protection' => [
                'summary' => 'How records are protected at rest and in transit.',
                'controls' => [
                    ['name' => 'Encryption at rest for sensitive identifiers',
                     'detail' => 'Provider NPI, date of birth, SSN last four, and email are stored encrypted using XChaCha20-Poly1305 '
                               . '(libsodium) with AES-256-GCM as the fallback. Encrypted fields remain searchable '
                               . 'through keyed blind indexes rather than by storing plaintext.',
                     'state' => $s['crypto']],
                    ['name' => 'Encryption in transit',
                     'detail' => 'All traffic is served over TLS. HTTP Strict-Transport-Security instructs browsers '
                               . 'to refuse insecure connections for a year, including subdomains.',
                     'state' => $s['https']],
                    ['name' => 'Tenant data isolation',
                     'detail' => 'Every record carries a tenant identifier and all data access is funnelled through a '
                               . 'repository layer that applies that scope. An automated check in the build fails if any '
                               . 'query bypasses it, so one organization cannot read another\'s records.',
                     'state' => 'Enforced'],
                    ['name' => 'Document storage',
                     'detail' => 'Uploaded evidence is stored outside the web root and served only through an '
                               . 'authenticated, permission-checked route — never by direct URL.',
                     'state' => 'Enforced'],
                ],
            ],
            'Access control' => [
                'summary' => 'Who can sign in, and what they are allowed to reach.',
                'controls' => [
                    ['name' => 'Multi-factor authentication (TOTP)',
                     'detail' => 'Time-based one-time passwords per RFC 6238, compatible with standard authenticator '
                               . 'apps, with single-use recovery codes. Administrators can require it for owners and '
                               . 'managers, who are then prompted to enrol before reaching any other page.',
                     'state' => $s['mfa']],
                    ['name' => 'Role-based permissions',
                     'detail' => 'Capabilities are granted per role and checked on every action, not merely hidden in '
                               . 'the interface. Roles are configurable per organization; providers receive a restricted '
                               . 'portal limited to their own record.',
                     'state' => 'Enforced'],
                    ['name' => 'Password storage',
                     'detail' => 'Passwords are hashed with Argon2id. They are never stored or logged in a recoverable '
                               . 'form, and a minimum length is enforced at sign-up.',
                     'state' => 'Argon2id'],
                    ['name' => 'Brute-force protection',
                     'detail' => 'Layered throttles limit sign-in attempts per account and per source address, so '
                               . 'password-guessing is rate-limited rather than unlimited.',
                     'state' => 'Enforced'],
                    ['name' => 'Session management',
                     'detail' => 'Session cookies are HttpOnly, SameSite-restricted, and marked Secure over HTTPS. '
                               . 'Sessions expire after inactivity and again at a hard ceiling, and the session '
                               . 'identifier is regenerated on sign-in to prevent fixation.',
                     'state' => $s['session']],
                ],
            ],
            'Application security' => [
                'summary' => 'Defences against common web attacks.',
                'controls' => [
                    ['name' => 'Content Security Policy',
                     'detail' => 'A per-request nonce authorises the application\'s own scripts, so inline script is '
                               . 'disallowed entirely. An injected <script> tag cannot execute even if it reaches a page. '
                               . 'Framing is denied outright, which blocks clickjacking.',
                     'state' => 'Nonce-based'],
                    ['name' => 'Cross-site request forgery protection',
                     'detail' => 'Every state-changing request carries a per-session token that is verified server-side '
                               . 'before the action runs.',
                     'state' => 'Enforced'],
                    ['name' => 'SQL injection protection',
                     'detail' => 'All database access uses prepared statements with bound parameters. Query text is '
                               . 'never assembled from user input.',
                     'state' => 'Enforced'],
                    ['name' => 'Output encoding',
                     'detail' => 'Values rendered into pages are context-escaped, which prevents stored or reflected '
                               . 'cross-site scripting.',
                     'state' => 'Enforced'],
                    ['name' => 'Security response headers',
                     'detail' => 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, and '
                               . 'Cross-Origin-Opener/Resource-Policy are sent on every response.',
                     'state' => 'Enabled'],
                    ['name' => 'Third-party dependency surface',
                     'detail' => 'The application ships with no third-party PHP package manager and no vendored runtime '
                               . 'libraries, so it carries no transitive dependency supply chain to monitor or patch.',
                     'state' => 'None'],
                ],
            ],
            'Auditability' => [
                'summary' => 'The record of who did what.',
                'controls' => [
                    ['name' => 'Immutable audit trail',
                     'detail' => 'Administrative and record-changing actions are written to an append-only audit log '
                               . 'capturing the actor, action, affected record, and timestamp — including credential '
                               . 'changes, verifications, deletions, plan and policy changes.',
                     'state' => $s['audit']],
                    ['name' => 'Access logging',
                     'detail' => 'Views of sensitive records are logged separately from changes, which supports the '
                               . '"who looked at this" question during an investigation.',
                     'state' => 'Enabled'],
                    ['name' => 'Evidence retention',
                     'detail' => 'Credential documents are retained with their issue and expiry dates, producing a '
                               . 'reconstructable compliance history per provider.',
                     'state' => 'Enabled'],
                    ['name' => 'Independent verification of identity',
                     'detail' => 'Provider identity can be confirmed against the CMS NPPES registry. The verified '
                               . 'fields are stored read-only with the date and the verifying user recorded.',
                     'state' => $s['npi']],
                ],
            ],
        ];
    }

    /** Live configuration facts, so the document reflects reality rather than intent. */
    private function state(): array
    {
        $pdo = Database::pdo();

        $t = $pdo->prepare('SELECT require_2fa_admins FROM tenants WHERE id = ? LIMIT 1');
        $t->execute([$this->ctx->tenantId]);
        $require2fa = (int) ($t->fetchColumn() ?: 0);

        $u = $pdo->prepare("SELECT COUNT(*) total, SUM(totp_enabled = 1) withMfa FROM users
                            WHERE tenant_id = ? AND status = 'active'");
        $u->execute([$this->ctx->tenantId]);
        $users = $u->fetch() ?: ['total' => 0, 'withMfa' => 0];

        // v1.171.0 (B-4): counts a need-to-know user sees are limited to their providers (audit: 0 — tenant-wide log is not theirs).
        $__sc = ProviderScope::for($this->ctx);
        if ($__sc->restricted()) { $auditCount = 0; }
        else {
            $a = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ?');
            $a->execute([$this->ctx->tenantId]);
            $auditCount = (int) $a->fetchColumn();
        }

        $n = $pdo->prepare('SELECT COUNT(*) FROM credential_holders
                            WHERE tenant_id = ? AND deleted_at IS NULL AND npi_verified_at IS NOT NULL' . $__sc->sql('id', false));
        $n->execute(array_merge([$this->ctx->tenantId], $__sc->params()));
        $verified = (int) $n->fetchColumn();

        $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (bool) config('force_secure_cookies', false);

        return [
            'crypto'  => (extension_loaded('sodium') ? 'XChaCha20-Poly1305' : 'AES-256-GCM'),
            'https'   => $https ? 'TLS + HSTS' : 'Not detected — serve over HTTPS',
            'mfa'     => $require2fa
                ? 'Required for admins · ' . (int) $users['withMfa'] . ' of ' . (int) $users['total'] . ' users enrolled'
                : 'Available · ' . (int) $users['withMfa'] . ' of ' . (int) $users['total'] . ' users enrolled',
            'session' => (int) config('session_idle_seconds', 1800) / 60 . ' min idle · '
                       . (int) config('session_absolute_seconds', 43200) / 3600 . ' h maximum',
            'audit'   => number_format($auditCount) . ' entries recorded',
            'npi'     => $verified > 0 ? $verified . ' provider(s) NPI-verified' : 'Available',
            'require2fa' => $require2fa,
            'users'   => $users,
        ];
    }

    /** Headline numbers for the on-screen summary. */
    public function summary(): array
    {
        $s = $this->state();
        $total = (int) ($s['users']['total'] ?? 0);
        $mfa   = (int) ($s['users']['withMfa'] ?? 0);
        return [
            'mfaPct'     => $total > 0 ? (int) round($mfa * 100 / $total) : 0,
            'mfaUsers'   => $mfa,
            'totalUsers' => $total,
            'require2fa' => (bool) $s['require2fa'],
            'audit'      => $s['audit'],
        ];
    }

    /**
     * Recommendations the organization can still act on. Only surfaced when they apply, so a fully
     * configured organization sees an empty list rather than permanent nagging.
     */
    public function recommendations(): array
    {
        $s = $this->state();
        $out = [];
        if (!$s['require2fa']) {
            $out[] = ['Require two-factor authentication for owners and managers',
                      'The strongest single control available to you, and the direction health-data rules are moving. '
                    . 'Turn it on under Organization → Security.'];
        }
        $total = (int) ($s['users']['total'] ?? 0); $mfa = (int) ($s['users']['withMfa'] ?? 0);
        if ($total > 0 && $mfa < $total) {
            $out[] = [($total - $mfa) . ' user(s) have not enrolled in two-factor authentication',
                      'Each person enables it from Account → Two-factor authentication.'];
        }
        if (str_starts_with((string) $s['https'], 'Not detected')) {
            $out[] = ['Serve the application over HTTPS',
                      'Encryption in transit and secure cookies both depend on it. Enable SSL in your hosting control panel.'];
        }
        return $out;
    }

    /** Render the posture as a PDF the organization can file or send on. */
    public function pdf(string $orgName): string
    {
        $pdf = new MiniPdf();
        $M = 46.0; $R = MiniPdf::W - $M;
        $NAVY = [0.06, 0.13, 0.22]; $GOLD = [0.95, 0.73, 0.24];
        $INK = [0.13, 0.16, 0.22]; $MUTE = [0.42, 0.46, 0.53];

        $pdf->page();
        $pdf->rect(0, 0, MiniPdf::W, 86, $NAVY);
        $pdf->rect(0, 86, MiniPdf::W, 3, $GOLD);
        $pdf->text($M, 40, 'Security & Compliance Overview', 16, true, [1, 1, 1]);
        $pdf->text($M, 60, $orgName, 10, false, [0.78, 0.84, 0.95]);
        $issued = 'Generated ' . fmt_dt(utc_now(), 'M j, Y g:i A') . ' ET';
        $pdf->text($R - MiniPdf::width($issued, 9), 60, $issued, 9, false, [0.78, 0.84, 0.95]);

        $y = 112.0;
        $y = $pdf->wrap($M, $y, $R - $M,
            'This document describes the technical safeguards implemented by the FlxCoreHC credentialing and '
          . 'compliance platform used by ' . $orgName . '. Items marked with a status reflect this '
          . 'organization\'s current configuration at the time of generation.', 9.5, false, $MUTE) + 14;

        foreach ($this->sections() as $title => $sec) {
            if ($y > 690) { $pdf->page(); $y = 60.0; }
            $pdf->rect($M, $y - 3, $R - $M, 20, [0.94, 0.96, 0.99]);
            $pdf->text($M + 6, $y + 11, $title, 11, true, $NAVY);
            $y += 26;
            $pdf->text($M + 6, $y, $sec['summary'], 8.5, false, $MUTE);
            $y += 14;

            foreach ($sec['controls'] as $c) {
                if ($y > 700) { $pdf->page(); $y = 60.0; }
                $pdf->text($M + 6, $y, '•  ' . $c['name'], 9.5, true, $INK);
                if (!empty($c['state'])) {
                    $st = (string) $c['state'];
                    $pdf->text($R - MiniPdf::width($st, 8.5), $y, $st, 8.5, false, [0.12, 0.55, 0.36]);
                }
                $y += 12;
                $y = $pdf->wrap($M + 16, $y, $R - $M - 22, $c['detail'], 8.5, false, $MUTE) + 8;
            }
            $y += 6;
        }

        if ($y > 640) { $pdf->page(); $y = 60.0; }
        $y += 6;
        $pdf->line($M, $y, $R, $y);
        $y += 16;
        $pdf->text($M, $y, 'Scope and limitations', 10, true, $NAVY);
        $y += 14;
        $pdf->wrap($M, $y, $R - $M,
            'This overview describes controls implemented in software. It is not a certification, audit report, or '
          . 'legal opinion, and it does not by itself establish regulatory compliance. Written policies, workforce '
          . 'training, risk analysis, business associate agreements, and physical and administrative safeguards '
          . 'remain the responsibility of ' . $orgName . '. Verify current requirements with your compliance '
          . 'counsel, as regulations change.', 8.5, false, $MUTE);

        return $pdf->output();
    }
}
