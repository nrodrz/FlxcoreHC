<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Puts the configured table prefix (fchc_ on a new installation) in front of every application table name in a SQL statement.
 *
 * The application's SQL keeps writing the logical names (`users`, `credential_holders`); this class — used by PrefixedPdo, the one place
 * every statement passes through — turns them into the real names (`fchc_users`). It is a small tokenizer, not a regular expression over
 * the whole text: string literals, comments and column names are never touched. A name is rewritten only where SQL expects a table:
 *   after FROM / JOIN / INTO / UPDATE / TABLE / REFERENCES / DESCRIBE / IF [NOT] EXISTS, after a comma inside a FROM (or UPDATE) list,
 *   and as the qualifier of a column (`users.id`).
 * Only names in database/tables.php are rewritten; an identifier that already carries the prefix is never rewritten twice.
 */
final class SqlPrefixer
{
    /** @var array<string,true>|null */
    private static ?array $tables = null;
    /** @var array<string,string> sql => rewritten, per prefix */
    private static array $cache = [];

    public static function tables(): array
    {
        if (self::$tables === null) {
            $root = defined('FLXCOREHC_ROOT') ? FLXCOREHC_ROOT : dirname(__DIR__, 2);
            self::$tables = array_fill_keys(array_merge(require $root . '/database/tables.php', require $root . '/database/tables_legacy.php'), true);
        }
        return self::$tables;
    }

    /** The real name of a logical table. */
    public static function name(string $logical, string $prefix): string
    {
        return isset(self::tables()[$logical]) ? $prefix . $logical : $logical;
    }

    public static function rewrite(string $sql, string $prefix): string
    {
        if ($prefix === '') return $sql;
        $key = $prefix . "\0" . $sql;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        if (count(self::$cache) > 4000) self::$cache = [];
        return self::$cache[$key] = self::run($sql, $prefix);
    }

    private static function run(string $sql, string $prefix): string
    {
        $tables = self::tables();
        $n = strlen($sql); $i = 0; $out = '';
        $prev = '';            // previous significant token, upper-cased word or the punctuation character
        $prev2 = '';           // the one before it
        $first = '';           // first word of the statement (RENAME TABLE a TO b: b is a table too)
        $depth = 0; $clause = [0 => ''];   // nearest clause keyword per parenthesis depth
        $afterDot = false;
        $kw = ['FROM' => 1, 'JOIN' => 1, 'INTO' => 1, 'UPDATE' => 1, 'TABLE' => 1, 'REFERENCES' => 1, 'DESCRIBE' => 1];
        $clauseWords = ['SELECT' => 1, 'FROM' => 1, 'WHERE' => 1, 'GROUP' => 1, 'ORDER' => 1, 'HAVING' => 1, 'LIMIT' => 1, 'SET' => 1,
                        'VALUES' => 1, 'ON' => 1, 'USING' => 1, 'UNION' => 1, 'UPDATE' => 1, 'INTO' => 1, 'JOIN' => 1];
        while ($i < $n) {
            $c = $sql[$i];
            // comments
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\t" || ($sql[$i + 2] ?? '') === "\n")) {
                $e = strpos($sql, "\n", $i); $e = $e === false ? $n : $e + 1; $out .= substr($sql, $i, $e - $i); $i = $e; continue;
            }
            if ($c === '#') { $e = strpos($sql, "\n", $i); $e = $e === false ? $n : $e + 1; $out .= substr($sql, $i, $e - $i); $i = $e; continue; }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') { $e = strpos($sql, '*/', $i + 2); $e = $e === false ? $n : $e + 2; $out .= substr($sql, $i, $e - $i); $i = $e; continue; }
            // string literals
            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === '\\') { $j += 2; continue; }
                    if ($sql[$j] === $c) { if (($sql[$j + 1] ?? '') === $c) { $j += 2; continue; } break; }
                    $j++;
                }
                $lit = substr($sql, $i, $j - $i + 1);
                if ($c === "'") {
                    $inner = substr($lit, 1, -1);
                    // information_schema comparison: TABLE_NAME = 'plans' -> 'fchc_plans' (a name in a value, not in a table position)
                    if (($prev === '=' || $prev === 'LIKE') && $prev2 === 'TABLE_NAME' && isset($tables[$inner])) $lit = "'" . $prefix . $inner . "'";
                    // dynamic DDL kept in a string for PREPARE ... EXECUTE: 'ALTER TABLE plans DROP COLUMN x'
                    elseif (preg_match('/^\s*(ALTER|DROP|CREATE)\s+TABLE\b/i', $inner) && !str_contains($inner, "''") && !str_contains($inner, '\\')) $lit = "'" . self::run($inner, $prefix) . "'";
                }
                $out .= $lit; $i = $j + 1; $prev2 = $prev; $prev = 'STR'; $afterDot = false; continue;
            }
            // backticked identifier
            if ($c === '`') {
                $j = strpos($sql, '`', $i + 1); if ($j === false) { $out .= substr($sql, $i); break; }
                $name = substr($sql, $i + 1, $j - $i - 1);
                $isTable = isset($tables[$name]) && !$afterDot && (self::tablePos($prev, $prev2, $clause[$depth] ?? '', $first) || (($sql[$j + 1] ?? '') === '.'));
                $out .= $isTable ? '`' . $prefix . $name . '`' : substr($sql, $i, $j - $i + 1);
                $i = $j + 1; $prev2 = $prev; $prev = 'ID'; $afterDot = false; continue;
            }
            // words
            if (ctype_alpha($c) || $c === '_') {
                $j = $i + 1; while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$')) $j++;
                $word = substr($sql, $i, $j - $i); $up = strtoupper($word);
                $isTable = isset($tables[$word]) && !$afterDot && (self::tablePos($prev, $prev2, $clause[$depth] ?? '', $first) || (($sql[$j] ?? '') === '.' ));
                $out .= $isTable ? $prefix . $word : $word;
                if (!$afterDot && isset($clauseWords[$up]) && !($up === 'UPDATE' && ($prev === 'KEY' || $prev === 'FOR'))) $clause[$depth] = $up;
                if ($first === '' && !$afterDot) $first = $up;
                $prev2 = $prev; $prev = $isTable ? 'ID' : ($afterDot ? 'ID' : $up); $afterDot = false; $i = $j; continue;
            }
            // numbers / other
            if ($c === '(') { $depth++; $clause[$depth] = ''; }
            elseif ($c === ')') { unset($clause[$depth]); $depth = max(0, $depth - 1); }
            elseif ($c === ';') { $first = ''; $depth = 0; $clause = [0 => '']; }
            $afterDot = ($c === '.');
            if (!ctype_space($c)) { $prev2 = $prev; $prev = $c; }
            $out .= $c; $i++;
        }
        return $out;
    }

    /** May an identifier here be a table name? */
    private static function tablePos(string $prev, string $prev2, string $clause, string $first = ''): bool
    {
        if ($first === 'RENAME' && ($prev === 'TABLE' || $prev === 'TO' || $prev === ',')) return true;
        static $kw = ['FROM' => 1, 'JOIN' => 1, 'INTO' => 1, 'TABLE' => 1, 'REFERENCES' => 1, 'DESCRIBE' => 1];
        if (isset($kw[$prev])) return true;
        if ($prev === 'UPDATE') return $prev2 !== 'KEY' && $prev2 !== 'FOR';
        if ($prev === 'EXISTS') return $prev2 === 'NOT' || $prev2 === 'IF';   // IF NOT EXISTS t / IF EXISTS t (not WHERE EXISTS (subquery))
        if ($prev === ',') return $clause === 'FROM' || $clause === 'UPDATE';
        return false;
    }
}
