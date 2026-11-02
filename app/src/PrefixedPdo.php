<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * A PDO that puts the table prefix (config `db_prefix`, fchc_ on a new installation) in front of every application table name in
 * every statement. It is the single door all SQL goes through (the application's database class and the installer), so the application keeps writing
 * logical table names and the database holds fchc_users, fchc_credentials, … See SqlPrefixer.
 */
final class PrefixedPdo extends \PDO
{
    private string $tablePrefix = '';

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null, string $prefix = '')
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->tablePrefix = $prefix;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        return parent::prepare(SqlPrefixer::rewrite($query, $this->tablePrefix), $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        $q = SqlPrefixer::rewrite($query, $this->tablePrefix);
        return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$fetchModeArgs);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        return parent::exec(SqlPrefixer::rewrite($statement, $this->tablePrefix));
    }
}
