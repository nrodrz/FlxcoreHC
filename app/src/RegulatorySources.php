<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/** The dated legal/regulatory citation behind each requirement ("why does the system require this?"). Platform-wide. */
final class RegulatorySources
{
    public static function all(): array
    {
        try { return Database::pdo()->query('SELECT * FROM requirement_sources ORDER BY authority, code')->fetchAll(); }
        catch (\Throwable $e) { return []; }
    }

    public static function save(array $in, ?string $userId): array
    {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['code'] ?? '')));
        $authority = trim((string)($in['authority'] ?? ''));
        if ($code === '' || $authority === '') return [false, 'Code and authority are required.'];
        $d = fn(string $k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : null;
        Database::pdo()->prepare(
            'INSERT INTO requirement_sources (id, code, authority, regulation, url, effective_date, superseded_date, last_reviewed, review_every_days, verified_by, summary, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE authority = VALUES(authority), regulation = VALUES(regulation), url = VALUES(url), effective_date = VALUES(effective_date),
               superseded_date = VALUES(superseded_date), last_reviewed = VALUES(last_reviewed), review_every_days = VALUES(review_every_days), verified_by = VALUES(verified_by), summary = VALUES(summary)'
        )->execute([uuid4(), $code, mb_substr($authority, 0, 160), mb_substr(trim((string)($in['regulation'] ?? '')), 0, 200) ?: null,
            mb_substr(trim((string)($in['url'] ?? '')), 0, 255) ?: null, $d('effective_date'), $d('superseded_date'), $d('last_reviewed') ?? today_local(),
            max(30, (int)($in['review_every_days'] ?? 180)), mb_substr(trim((string)($in['verified_by'] ?? '')), 0, 120) ?: null,
            mb_substr(trim((string)($in['summary'] ?? '')), 0, 500) ?: null, utc_now()]);
        return [true, 'Source saved.'];
    }

    /** Sources whose review date has lapsed — surfaced in the console so nothing goes stale silently. */
    /** One source by its code (for inline "why is this required?" citations). Cached per request. */
    private static array $byCode = [];
    public static function byCode(?string $code): ?array
    {
        if (!$code) return null;
        if (self::$byCode === []) { foreach (self::all() as $s) self::$byCode[$s['code']] = $s; }
        return self::$byCode[$code] ?? null;
    }

    public static function dueForReview(): array
    {
        return array_values(array_filter(self::all(), fn($s) => !$s['superseded_date'] && (!$s['last_reviewed'] || strtotime($s['last_reviewed'] . ' +' . (int)$s['review_every_days'] . ' days') < time())));
    }
}
