<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — vTiger database access (READ-ONLY).
 *
 * Used only for deduplication during onboarding (does a user with this email
 * already exist?) and simple lookups. Read-only: SELECTs
 * only. User CREATION never happens here — that goes through the bridge
 * (opsh-bridge.php) with vTiger's own model.
 *
 * Config comes from opsh_settings key 'vtiger_db' (JSON: host/db/user/pass/port).
 * Grant this DB user read access on the vTiger schema (read-only).
 */

if (!class_exists('OpshVTigerDb')) {
    final class OpshVTigerDb {
        private PDO $pdo;

        public function __construct(array $cfg) {
            $host = (string)($cfg['host'] ?? '127.0.0.1');
            $db   = (string)($cfg['db']   ?? '');
            $user = (string)($cfg['user'] ?? '');
            $pass = (string)($cfg['pass'] ?? '');
            $port = (int)($cfg['port'] ?? 3306);
            if ($db === '' || $user === '') throw new RuntimeException('vTiger DB is not configured.');
            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 8,
            ]);
        }

        /** Connectivity probe. */
        public function ping(): bool {
            $this->pdo->query('SELECT 1');
            return true;
        }

        private function ph(array $a): string { return implode(',', array_fill(0, count($a), '?')); }

        private function all(string $sql, array $args = []): array {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return $st->fetchAll();
        }

        /**
         * Map of email => {username,status,is_admin} for existing (non-deleted)
         * vTiger users. Used to dedupe before creating. Read-only.
         */
        public function usersByEmails(array $emails): array {
            $emails = array_values(array_unique(array_filter(array_map(
                fn($e) => strtolower(trim((string)$e)), $emails
            ))));
            if (!$emails) return [];
            $ph = $this->ph($emails);
            $sql = "SELECT user_name, status, is_admin, LOWER(email1) AS email
                    FROM vtiger_users
                    WHERE deleted = 0 AND LOWER(email1) IN ({$ph})
                    ORDER BY (status = 'Active') DESC, (is_admin = 'on') DESC, id ASC";
            $map = [];
            foreach ($this->all($sql, $emails) as $r) {
                $e = (string)$r['email'];
                if ($e === '' || isset($map[$e])) continue; // first hit wins
                $map[$e] = [
                    'username' => (string)$r['user_name'],
                    'status'   => (string)$r['status'],
                    'is_admin' => (strtolower((string)$r['is_admin']) === 'on'),
                ];
            }
            return $map;
        }

        /** Every non-deleted vTiger user (BT21: Microsoft 365 → Reconcile / reassignment targets). Read-only. */
        public function allUsers(): array {
            $rows = $this->all("SELECT u.id, u.user_name, u.first_name, u.last_name, u.email1, u.status, u.is_admin, ur.roleid
                                FROM vtiger_users u LEFT JOIN vtiger_user2role ur ON ur.userid = u.id
                                WHERE u.deleted = 0 ORDER BY u.user_name");
            return array_map(fn($r) => ['id' => (string)$r['id'], 'user_name' => (string)$r['user_name'], 'first_name' => (string)$r['first_name'],
                'last_name' => (string)$r['last_name'], 'email' => (string)$r['email1'], 'status' => (string)$r['status'],
                'is_admin' => strtolower((string)$r['is_admin']) === 'on', 'roleid' => (string)($r['roleid'] ?? '')], $rows);
        }

        /**
         * BT163: vTiger's sign-in history since a time (vtiger_loginhistory), newest first, with the user's name. Read-only.
         * [{user_name, first_name, last_name, ip, login_time, logout_time, status}]
         */
        public function loginHistory(string $since, int $limit = 500): array {
            $rows = $this->all("SELECT h.user_name, h.user_ip, h.login_time, h.logout_time, h.status, u.first_name, u.last_name
                                FROM vtiger_loginhistory h LEFT JOIN vtiger_users u ON u.user_name = h.user_name
                                WHERE h.login_time >= ? ORDER BY h.login_id DESC LIMIT " . max(1, min(2000, $limit)), [$since]);
            return array_map(fn($r) => ['user_name' => (string)$r['user_name'], 'first_name' => (string)($r['first_name'] ?? ''), 'last_name' => (string)($r['last_name'] ?? ''),
                'ip' => (string)($r['user_ip'] ?? ''), 'login_time' => (string)$r['login_time'], 'logout_time' => (string)($r['logout_time'] ?? ''), 'status' => (string)($r['status'] ?? '')], $rows);
        }

        /** Roles list (id + label) for the onboarding role picker. Read-only. */
        public function roles(): array {
            try {
                return $this->all("SELECT roleid, rolename FROM vtiger_role ORDER BY rolename");
            } catch (Throwable $e) { return []; }
        }
    }
}
