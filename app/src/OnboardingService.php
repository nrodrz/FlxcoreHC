<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * "Getting started" checklist for a new organization. Every step is COMPUTED from real data (not a
 * flag the user ticks), so it reflects what has actually been set up, and it disappears on its own
 * once everything is done — or when the Master Admin dismisses it. Cheap: a handful of COUNTs.
 */
final class OnboardingService
{
    public function __construct(private readonly TenantContext $ctx) {}

    /** @return array{steps:array<int,array{key:string,title:string,hint:string,href:string,done:bool}>,done:int,total:int,dismissed:bool} */
    public function checklist(): array
    {
        $pdo = Database::pdo(); $tid = $this->ctx->tenantId;
        $cnt = function (string $sql) use ($pdo, $tid): int {
            try { $st = $pdo->prepare($sql); $st->execute([$tid]); return (int)$st->fetchColumn(); } catch (\Throwable $e) { return 0; }
        };
        $isSolo = $this->ctx->orgShape === 'solo_practice';
        $groupWord = strtolower(GroupService::label($this->ctx->orgShape, true));

        $steps = [
            ['key' => 'team', 'title' => 'Invite your team', 'hint' => 'Add the people who manage credentials and give each a role.',
             'href' => '/team', 'done' => $cnt("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role <> 'provider'") > 1],
            ['key' => 'locations', 'title' => 'Add your practice locations', 'hint' => 'Sites where providers practice (optional for a single office).',
             'href' => '/locations', 'done' => $cnt("SELECT COUNT(*) FROM locations WHERE tenant_id = ? AND deleted_at IS NULL") > 0],
        ];
        if (!$isSolo) {
            $steps[] = ['key' => 'groups', 'title' => "Set up your {$groupWord}", 'hint' => 'Organize providers by client group or department.',
                        'href' => '/groups', 'done' => $cnt("SELECT COUNT(*) FROM provider_groups WHERE tenant_id = ? AND deleted_at IS NULL") > 0];
        }
        $steps[] = ['key' => 'providers', 'title' => 'Add or import your providers', 'hint' => 'Download the CSV template to load a whole roster at once.',
                    'href' => '/holders/import', 'done' => $cnt("SELECT COUNT(*) FROM credential_holders WHERE tenant_id = ? AND deleted_at IS NULL") > 0];
        $steps[] = ['key' => 'credentials', 'title' => 'Import their credentials', 'hint' => 'Licenses, DEA, malpractice, CME — expiries feed the calendar automatically.',
                    'href' => '/credentials/import', 'done' => $cnt("SELECT COUNT(*) FROM credentials WHERE tenant_id = ? AND deleted_at IS NULL") > 0];
        $steps[] = ['key' => 'email', 'title' => 'Connect your email', 'hint' => 'Send invites and reminders from your own domain mailbox or Microsoft 365.',
                    'href' => '/organization/email', 'done' => $cnt("SELECT COUNT(*) FROM tenant_mail_settings WHERE tenant_id = ? AND transport = 'smtp' AND verified_at IS NOT NULL") > 0];
        $steps[] = ['key' => 'calendar', 'title' => 'Review the calendar', 'hint' => 'Your renewal deadlines, grouped by urgency.',
                    'href' => '/calendar', 'done' => $cnt("SELECT COUNT(*) FROM calendar_events WHERE tenant_id = ? AND deleted_at IS NULL") > 0];

        $done = count(array_filter($steps, fn($s) => $s['done']));
        $dismissed = false;
        try { $st = $pdo->prepare('SELECT onboarding_dismissed_at FROM tenants WHERE id = ?'); $st->execute([$tid]); $dismissed = (bool)$st->fetchColumn(); } catch (\Throwable $e) {}
        return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'dismissed' => $dismissed];
    }

    public function dismiss(): void
    {
        Database::pdo()->prepare('UPDATE tenants SET onboarding_dismissed_at = ? WHERE id = ?')->execute([utc_now(), $this->ctx->tenantId]);
    }
}
