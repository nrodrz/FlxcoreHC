<?php
declare(strict_types=1);

namespace FlxCoreHC;

/** Read-only access to the global training reference library (shared by all tenants). */
final class TrainingLibraryService
{
    public const CATEGORIES = [
        'certification' => 'Certification', 'state' => 'State licensing',
        'enrollment' => 'Enrollment & payers', 'compliance' => 'Compliance & privacy',
        'clinical' => 'Clinical & safety', 'general' => 'General',
    ];

    public static function available(): bool
    {
        try { Database::pdo()->query("SELECT 1 FROM training_library LIMIT 1"); return true; }
        catch (\Throwable $e) { return false; }
    }

    /** @return array<array<string,mixed>> */
    public static function all(?string $vertical = null): array
    {
        try {
            if ($vertical !== null && $vertical !== '') {
                $stmt = Database::pdo()->prepare(
                    "SELECT * FROM training_library WHERE vertical = ? ORDER BY sort, title"
                );
                $stmt->execute([$vertical]);
                return $stmt->fetchAll();
            }
            return Database::pdo()->query("SELECT * FROM training_library ORDER BY sort, title")->fetchAll();
        } catch (\Throwable $e) {
            // vertical column absent (pre-071) — fall back to the whole library.
            try { return Database::pdo()->query("SELECT * FROM training_library ORDER BY sort, title")->fetchAll(); }
            catch (\Throwable $e2) { return []; }
        }
    }

    public static function find(string $code): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM training_library WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /** article_code => number of sources, for badges on the index. */
    public static function sourceCounts(): array
    {
        try {
            $rows = Database::pdo()->query("SELECT article_code, COUNT(*) n FROM training_sources GROUP BY article_code")->fetchAll();
            $out = [];
            foreach ($rows as $r) { $out[$r['article_code']] = (int)$r['n']; }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    /** Authoritative sources for an article (official CMS/state/carrier/industry links). */
    public static function sourcesFor(string $code): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT * FROM training_sources WHERE article_code = ? ORDER BY sort, id"
            );
            $stmt->execute([$code]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    public const AUTHORITY_LABEL = [
        'cms' => 'CMS', 'federal' => 'Federal', 'state' => 'State board / agency',
        'carrier' => 'Payer', 'industry' => 'Industry body',
    ];

    /** Naive keyword search over title/summary/body — powers the "Ask" box. */
    public static function search(string $q, ?string $vertical = null): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $terms = array_filter(preg_split('/\s+/', strtolower($q)) ?: []);
        $scored = [];
        foreach (self::all($vertical) as $row) {
            $hay = strtolower(($row['title'] ?? '') . ' ' . ($row['summary'] ?? '') . ' ' . ($row['body'] ?? '') . ' ' . ($row['category'] ?? ''));
            $score = 0;
            foreach ($terms as $t) {
                if ($t === '') continue;
                if (str_contains(strtolower((string)$row['title']), $t)) $score += 3;
                if (str_contains($hay, $t)) $score += 1;
            }
            if ($score > 0) $scored[] = ['score' => $score, 'row' => $row];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn($x) => $x['row'], array_slice($scored, 0, 6));
    }

    /** Very small block renderer: paragraphs, and lines starting with "- " become a list. */
    public static function renderBody(string $body): string
    {
        $out = '';
        foreach (preg_split('/\n\s*\n/', trim($body)) as $block) {
            $lines = preg_split('/\n/', trim($block));
            if ($lines && str_starts_with(trim($lines[0]), '- ')) {
                $out .= '<ul style="margin:.2rem 0 .8rem;padding-left:1.1rem;display:grid;gap:.3rem">';
                foreach ($lines as $l) { $l = trim($l); if ($l !== '') $out .= '<li>' . e(ltrim($l, '- ')) . '</li>'; }
                $out .= '</ul>';
            } else {
                $out .= '<p style="margin:.2rem 0 .7rem">' . e(trim($block)) . '</p>';
            }
        }
        return $out;
    }
}
