<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * Fire an outbound webhook event. Safe no-op if integrations are not installed.
 * Never throws — an integration failure must not break a core write.
 */
if (!function_exists('itops_emit')) {
    function itops_emit(string $event, array $row): void {
        $f = __DIR__ . '/Integrations.php';
        if (!is_file($f)) return;
        require_once $f;
        if (function_exists('itops_fire_event')) itops_fire_event($event, $row);
    }
}
/**
 * ITOps — Generic CRUD Engine
 * Reusable CRUD operations for all entity types
 */

class CrudEngine {
    private PDO $db;
    private string $table;
    private string $entityType;
    private array $allowedFields;
    private array $searchFields;
    private ?array $currentUser;
    
    public function __construct(
        string $table, 
        string $entityType, 
        array $allowedFields, 
        array $searchFields = [],
        ?array $currentUser = null
    ) {
        $this->db = getDB();
        $this->table = $table;
        $this->entityType = $entityType;
        $this->allowedFields = $allowedFields;
        $this->searchFields = $searchFields;
        $this->currentUser = $currentUser;
    }
    
    /**
     * LIST with pagination, search, filtering, and sorting
     */
    /** Columns that must never leave the server (API responses, audit log, webhooks). */
    /** Largest page a list request may ask for (routes may raise it, e.g. the Tickets queue). */
    public $maxLimit = 100;
    const HIDDEN = ['password_hash', 'mfa_secret', 'mfa_backup_codes'];
    public static function scrub(?array $row, bool $alsoEncrypted = false): ?array {
        if (!$row) return $row;
        foreach (self::HIDDEN as $k) unset($row[$k]);
        if ($alsoEncrypted) foreach (array_keys($row) as $k) if (substr((string)$k, -4) === '_enc') unset($row[$k]);
        return $row;
    }

    public function listAll(array $params, string $joins = '', string $selectExtra = '', string $whereExtra = '', array $whereParams = []): array {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min($this->maxLimit, max(1, (int)($params['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $search = trim($params['search'] ?? '');
        $sort = $params['sort'] ?? 'created_at';
        $order = strtoupper($params['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        $where = ['1=1'];
        $bind = $whereParams;
        
        if ($whereExtra) $where[] = $whereExtra;
        
        // Search
        if ($search && $this->searchFields) {
            $searchConds = [];
            foreach ($this->searchFields as $f) {
                $searchConds[] = "$f LIKE ?";
                $bind[] = "%$search%";
            }
            $where[] = '(' . implode(' OR ', $searchConds) . ')';
        }
        
        // Dynamic filters
        foreach ($params as $k => $v) {
            if (in_array($k, $this->allowedFields) && $v !== '' && $v !== null && is_scalar($v)) {   // review WORK-18: arrays are not filters
                $where[] = "t.$k = ?";
                $bind[] = $v;
            }
        }
        
        $whereClause = implode(' AND ', $where);
        $select = "t.*" . ($selectExtra ? ", $selectExtra" : '');
        
        // Count
        $countSql = "SELECT COUNT(*) FROM {$this->table} t $joins WHERE $whereClause";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($bind);
        $total = (int)$stmt->fetchColumn();
        
        // Validate sort field
        $allowedSort = array_merge($this->allowedFields, ['id', 'created_at', 'updated_at']);
        if (!in_array($sort, $allowedSort)) $sort = 'created_at';
        
        // Fetch
        $sql = "SELECT $select FROM {$this->table} t $joins WHERE $whereClause ORDER BY t.$sort $order LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $rows = array_map(fn($r) => self::scrub($r), $stmt->fetchAll());
        
        return [
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
            ]
        ];
    }
    
    /**
     * GET single record by ID
     */
    public function getById(int $id, string $joins = '', string $selectExtra = ''): ?array {
        $select = "t.*" . ($selectExtra ? ", $selectExtra" : '');
        $sql = "SELECT $select FROM {$this->table} t $joins WHERE t.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return self::scrub($stmt->fetch() ?: null);
    }
    
    /**
     * CREATE a new record
     */
    public function create(array $data): array {
        $fields = array_intersect_key($data, array_flip($this->allowedFields));
        if (empty($fields)) error_response('No valid fields provided');
        
        $columns = implode(',', array_keys($fields));
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($fields));
        $id = (int)$this->db->lastInsertId();
        
        audit_log('created', $this->entityType, $id, $data['name'] ?? $data['title'] ?? $data['subject'] ?? $data['display_name'] ?? $data['email'] ?? '', null, self::scrub($fields));

        $row = $this->getById($id) ?? ['id' => $id];
        itops_emit($this->entityType . '.created', self::scrub($row, true));
        return $row;
    }
    
    /**
     * UPDATE an existing record
     */
    public function update(int $id, array $data): array {
        $old = $this->getById($id);
        if (!$old) error_response('Record not found', 404);
        
        $fields = array_intersect_key($data, array_flip($this->allowedFields));
        if (empty($fields)) error_response('No valid fields provided');
        
        $sets = [];
        $bind = [];
        foreach ($fields as $k => $v) {
            $sets[] = "$k = ?";
            $bind[] = $v;
        }
        $bind[] = $id;
        
        $sql = "UPDATE {$this->table} SET " . implode(',', $sets) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        
        // (credential *_enc values stay in the audit copy — encrypted — so a past secret can be revealed from History)
        // Review WORK-18: the audit copy holds the fields this change touched (before and after), not the whole record
        audit_log('updated', $this->entityType, $id, $old['name'] ?? $old['title'] ?? $old['subject'] ?? $old['display_name'] ?? $old['email'] ?? '', self::scrub(array_intersect_key($old, $fields)), self::scrub($fields));

        $row = $this->getById($id) ?? $old;
        itops_emit($this->entityType . '.updated', self::scrub($row, true));
        return $row;
    }
    
    /**
     * DELETE a record (soft or hard)
     */
    public function delete(int $id, bool $soft = false): bool {
        $old = $this->getById($id);
        if (!$old) error_response('Record not found', 404);
        
        $nm = $old['name'] ?? $old['title'] ?? $old['subject'] ?? $old['display_name'] ?? $old['email'] ?? '';
        // BT141: a record the app keeps in Deleted items goes there (with what belongs to it) instead of being erased
        require_once __DIR__ . '/RecycleBin.php';
        $binKind = itops_bin_kind_by_table($this->table);
        if ($binKind) {
            try { itops_bin_put($this->db, $binKind, $id, $this->currentUser); }
            catch (ItopsBinRefused $e) { audit_log('delete_refused', $this->entityType, $id, (string)$nm, null, ['Why' => $e->getMessage()]); error_response('Not deleted: ' . $e->getMessage(), 409); }
            audit_log('deleted', $this->entityType, $id, (string)$nm, null, ['Kept in' => 'Deleted items']);
            itops_emit($this->entityType . '.deleted', self::scrub($old, true));
            return true;
        }
        if ($soft && in_array('status', $this->allowedFields)) {
            $this->db->prepare("UPDATE {$this->table} SET status='archived' WHERE id=?")->execute([$id]);
        } else {
            $this->db->prepare("DELETE FROM {$this->table} WHERE id=?")->execute([$id]);
        }
        
        audit_log('deleted', $this->entityType, $id, $nm);
        itops_emit($this->entityType . '.deleted', self::scrub($old, true));
        return true;
    }
}
