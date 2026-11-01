<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Auth;

use FlxCoreHC\Database;

/** DB-backed limiter — works on shared hosting where APCu/Redis aren't available. */
final class RateLimiter
{
    public function tooManyAttempts(string $identifier, int $max, int $windowSeconds): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE identifier = ? AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$identifier, $windowSeconds]);
        return (int) $stmt->fetch()['c'] >= $max;
    }

    public function record(string $identifier): void
    {
        Database::pdo()->prepare(
            'INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, UTC_TIMESTAMP())'
        )->execute([$identifier]);

        // Opportunistic cleanup so the table never grows unbounded.
        if (random_int(1, 50) === 1) {
            Database::pdo()->exec(
                "DELETE FROM login_attempts WHERE (identifier NOT LIKE 'known|%' AND attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))
                    OR (identifier LIKE 'known|%' AND attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 45 DAY))"
            );
        }
    }

    /** True if $identifier was remembered within the last $days days (used for "known sign-in location"). */
    public function seenWithin(string $identifier, int $days): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'
        );
        $stmt->execute([$identifier, $days]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function clear(string $identifier): void
    {
        Database::pdo()->prepare('DELETE FROM login_attempts WHERE identifier = ?')
            ->execute([$identifier]);
    }
}
