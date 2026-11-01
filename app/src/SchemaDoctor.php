<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Data dictionary + database health for the platform operator.
 *
 * The dictionary is generated live from the schema (so it can never drift from reality) and paired
 * with a curated purpose for each table plus free-form operator notes stored in `schema_notes`.
 * Health surfaces the things that actually bite a shared-hosting install: table sizes and growth,
 * missing tenant scoping, rows without an index, orphaned rows, migration drift, and free space.
 */
final class SchemaDoctor
{
    /** Curated purpose for each table. Anything not listed falls back to "—" and can be annotated. */
    public const PURPOSE = [
        'tenants' => 'One row per organization (client) on the platform, plus the operator\'s own account.',
        'users' => 'Every login: staff, Master Admins, platform admins, and provider-portal users (holder_id links a portal user to their provider record).',
        'roles' => 'Role definitions per tenant, plus platform-owned global roles; capabilities stored as a list.',
        'credential_holders' => 'Providers — the people and entities being credentialed. NPI/email encrypted, with blind indexes for matching.',
        'credentials' => 'Licenses, certifications, trainings, insurance and identifiers held by a provider, with issue/expiry dates that drive everything else.',
        'credential_documents' => 'Files attached to credentials. Multiple per credential with a kind; supersedes_id forms the renewal chain; verification fields record the organization\'s verification checks.',
        'requirement_templates' => 'What each professional type must have on file; drives compliance scoring.',
        'template_items' => 'The individual required items inside a template.',
        'producer_requirements' => 'Which template/requirements apply to a specific provider.',
        'provider_groups' => 'Client groups (billing companies) or departments (hospitals).',
        'holder_groups' => 'Provider ↔ group membership, with a primary flag.',
        'locations' => 'Practice sites with address and optional organizational NPI.',
        'holder_locations' => 'Provider ↔ location affiliation, with a primary flag.',
        'holder_assignments' => 'Need-to-know: which staff user is assigned to which provider.',
        'calendar_events' => 'Deadlines and manual events. Credential expiries sync here automatically (source=credential).',
        'calendar_event_types' => 'Event categories and colors, per tenant.',
        'renewal_tasks' => 'Follow-up tasks, often generated from an expiring credential.',
        'compliance_events' => 'PERMANENT business ledger — every meaningful moment in a provider\'s compliance life. Append-only, never purged.',
        'audit_logs' => 'Security trail: who did what, when, from which IP. Never purged.',
        'access_logs' => 'Read/download/login events, for privacy accounting.',
        'notifications' => 'In-app notification centre entries, per user, with need-to-know applied at generation.',
        'exclusion_screenings' => 'OIG LEIE / SAM / state Medicaid screening results per provider.',
        'verifications' => 'Recorded verification checks against a credential.',
        'attestations' => 'Provider attestations (e.g. annual sign-off).',
        'ce_entries' => 'Continuing-education hours recorded per provider.',
        'applications' => 'Payer / enrollment applications being tracked.',
        'document_requests' => 'A tenant asks a provider for a document; the provider fulfils it in their portal. Tenant owns the result.',
        'external_shares' => 'Password-protected, time-limited read-only profile links for payers and agencies.',
        'external_share_access' => 'Every view, download, failed password and lock on an external share link.',
        'provider_links' => 'Cross-organization links between two tenants\' records of the same provider. Requires three consents.',
        'credential_shares' => 'A specific credential shared along an active cross-organization link, read-only and live.',
        'cross_share_access' => 'Every view/download by the recipient organization on a shared credential.',
        'share_links' => 'Verification links shared externally (legacy/simple sharing).',
        'tenant_mail_settings' => 'Per-tenant outbound email (SMTP host/user, password encrypted, verification state).',
        'email_outbox' => 'Queued and sent email, with delivery status and attempts.',
        'ui_translations' => 'Editable interface translations layered over the file dictionary; context groups them by screen.',
        'schema_notes' => 'Operator annotations shown in this data dictionary.',
        'impersonation_requests' => 'Consent-gated platform support windows (4 hours, approved by the tenant Master Admin).',
        'plans' => 'Subscription plans and their feature/seat limits.',
        'invoices' => 'Billing records per tenant.',
        'regulators' => 'Boards and agencies a provider answers to.',
        'regulator_requirements' => 'Requirements defined by a regulator.',
        'holder_regulators' => 'Which regulators apply to a provider.',
        'training_resources' => 'Training centre materials.',
        'auth_email_codes' => 'One-time sign-in / two-factor codes sent by e-mail (stored as keyed hashes, 10 minutes, single use). Keyed by user: a platform administrator has no tenant.',
        'password_resets' => 'Single-use forgot-password links (the token is stored as a SHA-256 hash, 60 minutes).',
        'requirement_sources' => 'Global catalogue of the legal or regulatory citation behind each requirement, dated and reviewed.',
        'schema_migrations' => 'Applied migration filenames — the schema version record.',
    ];

    /** Tables that intentionally have no tenant_id (platform-wide, global defaults, or keyed elsewhere). */
    private const NO_TENANT_OK = ['schema_migrations', 'plans', 'ui_translations', 'schema_notes', 'roles',
        'external_share_access', 'cross_share_access', 'provider_links', 'credential_shares',
        'invoice_counter', 'leie_exclusions', 'leie_imports', 'login_attempts', 'sam_exclusions',
        'medicaid_exclusions', 'rate_limits', 'error_log', 'role_capabilities', 'credential_catalog',
        'compliance_items', 'domains', 'impersonation_requests',
        'medicaid_imports', 'npi_cache', 'platform_settings', 'sam_imports', 'sam_exclusion_imports',
        'training_catalog', 'vocab', 'settings', 'tenants', 'training_library', 'training_sources',
        // v1.172.2: auth_email_codes is keyed by user_id (a platform administrator has no tenant); requirement_sources is the
        // global catalogue of legal citations. Neither holds tenant data, so a missing tenant_id is correct, not a warning.
        'auth_email_codes', 'requirement_sources'];

    /** @return array<int,array<string,mixed>> one row per table */
    public static function dictionary(): array
    {
        $pdo = Database::pdo();
        $notes = [];
        try {
            foreach ($pdo->query('SELECT table_name, note, updated_at FROM schema_notes')->fetchAll() as $n) {
                $notes[$n['table_name']] = $n;
            }
        } catch (\Throwable $e) { /* pre-migration */ }

        $rows = $pdo->query(
            "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION, CREATE_TIME, UPDATE_TIME
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $t = (string) $r['TABLE_NAME'];
            $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn();
            $hasTenant = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t) . " AND COLUMN_NAME = 'tenant_id'")->fetchColumn();
            $idx = (int) $pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn();
            // exact row count (TABLE_ROWS is an estimate for InnoDB)
            try { $exact = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (\Throwable $e) { $exact = (int) $r['TABLE_ROWS']; }
            $out[] = [
                'table' => $t,
                'purpose' => self::PURPOSE[$t] ?? '—',
                'note' => $notes[$t]['note'] ?? null,
                'note_updated' => $notes[$t]['updated_at'] ?? null,
                'rows' => $exact,
                'columns' => (int) $cols,
                'indexes' => $idx,
                'data_bytes' => (int) $r['DATA_LENGTH'],
                'index_bytes' => (int) $r['INDEX_LENGTH'],
                'total_bytes' => (int) $r['DATA_LENGTH'] + (int) $r['INDEX_LENGTH'],
                'engine' => (string) $r['ENGINE'],
                'collation' => (string) $r['TABLE_COLLATION'],
                'tenant_scoped' => $hasTenant,
                'tenant_expected' => !in_array($t, self::NO_TENANT_OK, true),
                'updated' => $r['UPDATE_TIME'] ?? $r['CREATE_TIME'],
            ];
        }
        return $out;
    }

    /** @return array{summary:array,checks:array} database health */
    public static function health(): array
    {
        $pdo = Database::pdo();
        $dict = self::dictionary();
        $summary = [
            'tables' => count($dict),
            'rows' => array_sum(array_column($dict, 'rows')),
            'bytes' => array_sum(array_column($dict, 'total_bytes')),
            'largest' => [],
            'version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        ];
        $byBytes = $dict;
        usort($byBytes, fn($a, $b) => $b['total_bytes'] <=> $a['total_bytes']);
        $summary['largest'] = array_slice($byBytes, 0, 8);

        $checks = [];
        $ok = fn($l, $d) => ['state' => 'ok', 'label' => $l, 'detail' => $d];
        $warn = fn($l, $d) => ['state' => 'warn', 'label' => $l, 'detail' => $d];
        $bad = fn($l, $d) => ['state' => 'bad', 'label' => $l, 'detail' => $d];

        // migration drift
        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $expected = count(require FLXCOREHC_ROOT . '/database/manifest.php');
            $checks[] = $applied >= $expected
                ? $ok('Migrations', "$applied applied — schema is current.")
                : $bad('Migrations', "$applied applied of $expected shipped — run Console → System → Update database.");
        } catch (\Throwable $e) { $checks[] = $warn('Migrations', 'Could not read the migrations table.'); }

        // tenant scoping
        $missing = array_filter($dict, fn($d) => $d['tenant_expected'] && !$d['tenant_scoped']);
        $checks[] = $missing === []
            ? $ok('Tenant scoping', 'Every tenant-owned table has a tenant_id column.')
            : $warn('Tenant scoping', 'No tenant_id on: ' . implode(', ', array_column($missing, 'table')));

        // engine + collation consistency
        $badEngine = array_filter($dict, fn($d) => $d['engine'] !== 'InnoDB' && $d['engine'] !== '');
        $checks[] = $badEngine === [] ? $ok('Storage engine', 'All tables InnoDB (transactions + FKs).')
            : $warn('Storage engine', 'Non-InnoDB: ' . implode(', ', array_column($badEngine, 'table')));
        $badColl = array_filter($dict, fn($d) => $d['collation'] !== '' && !str_starts_with($d['collation'], 'utf8mb4'));
        $checks[] = $badColl === [] ? $ok('Character set', 'All tables utf8mb4 (full Unicode, Spanish accents safe).')
            : $warn('Character set', 'Not utf8mb4: ' . implode(', ', array_column($badColl, 'table')));

        // indexes
        $noIdx = array_filter($dict, fn($d) => $d['indexes'] < 1 && $d['rows'] > 0);
        $checks[] = $noIdx === [] ? $ok('Indexes', 'Every populated table has at least one index.')
            : $warn('Indexes', 'No index: ' . implode(', ', array_column($noIdx, 'table')));

        // orphans (rows whose tenant no longer exists)
        $orphans = [];
        foreach ($dict as $d) {
            if (!$d['tenant_scoped'] || $d['rows'] === 0) continue;
            try {
                // NULL tenant_id = a platform-level default (global roles, catalog seeds), not an orphan.
                $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$d['table']}` x LEFT JOIN tenants t ON t.id = x.tenant_id
                                        WHERE x.tenant_id IS NOT NULL AND t.id IS NULL")->fetchColumn();
                if ($n > 0) $orphans[] = "{$d['table']} ($n)";
            } catch (\Throwable $e) { /* skip */ }
        }
        $checks[] = $orphans === [] ? $ok('Orphaned rows', 'No rows belong to a deleted organization.')
            : $warn('Orphaned rows', implode(', ', $orphans) . ' — left by a removed tenant; safe to clean.');

        // growth pressure
        $big = array_filter($dict, fn($d) => $d['total_bytes'] > 50 * 1024 * 1024);
        $checks[] = $big === [] ? $ok('Table sizes', 'No table above 50 MB.')
            : $warn('Table sizes', 'Large: ' . implode(', ', array_map(fn($d) => $d['table'] . ' (' . round($d['total_bytes'] / 1048576) . ' MB)', $big)));

        // outbox backlog
        try {
            $stuck = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status <> 'sent' AND created_at < (NOW() - INTERVAL 1 DAY)")->fetchColumn();
            $checks[] = $stuck === 0 ? $ok('Email outbox', 'No messages stuck over a day.')
                : $warn('Email outbox', "$stuck message(s) unsent for more than a day — check mail settings or the cron.");
        } catch (\Throwable $e) {}

        // soft-delete build-up
        try {
            $del = (int) $pdo->query("SELECT COUNT(*) FROM credential_documents WHERE deleted_at IS NOT NULL")->fetchColumn();
            $checks[] = $ok('Retention', "$del document(s) soft-deleted and retained (by design — nothing is purged).");
        } catch (\Throwable $e) {}

        return ['summary' => $summary, 'checks' => $checks, 'dictionary' => $dict];
    }

    public static function saveNote(string $table, string $note, ?string $userId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO schema_notes (id, table_name, note, updated_by, updated_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        )->execute([uuid4(), $table, trim($note) !== '' ? $note : null, $userId, utc_now()]);
    }
}
