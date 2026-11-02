<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Custom fields: each organization defines extra fields for providers (or for itself) without any
 * schema change. Definitions carry label/type/required/options/order; values are stored per
 * definition per record. render() and save() are used by the provider and organization forms;
 * valuesFor() feeds packets, lists and exports.
 */
final class CustomFieldService
{
    public const TYPES = ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date',
                          'select' => 'Choice list', 'checkbox' => 'Yes / no', 'email' => 'Email', 'phone' => 'Phone', 'url' => 'Web address'];

    public function __construct(private readonly TenantContext $ctx) {}

    // ---- definitions ------------------------------------------------------------

    /** Whether migration 119 (sensitive custom fields) has run on this database. Cached per request. */
    private static ?bool $lvl3 = null;
    public static function hasSensitiveColumns(): bool
    {
        if (self::$lvl3 !== null) return self::$lvl3;
        try {
            $q = Database::pdo()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . Database::pdo()->quote(Database::table('custom_field_values')) . " AND COLUMN_NAME = 'value_enc'");
            self::$lvl3 = ((int) $q->fetchColumn()) > 0;
        } catch (\Throwable $e) { self::$lvl3 = false; }
        return self::$lvl3;
    }

    public function definitions(string $appliesTo, bool $activeOnly = true): array
    {
        if (SupportAccess::active()) return [];
        $st = Database::pdo()->prepare('SELECT * FROM custom_field_definitions WHERE tenant_id = ? AND applies_to = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort_order, label');
        $st->execute([$this->ctx->tenantId, $appliesTo]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['options'] = $r['options'] ? (json_decode((string)$r['options'], true) ?: []) : []; }
        return $rows;
    }

    public function saveDefinition(array $in, ?string $id = null): array
    {
        $label = trim((string)($in['label'] ?? ''));
        if ($label === '') return [false, 'Give the field a label.'];
        $appliesTo = in_array($in['applies_to'] ?? '', ['provider','tenant'], true) ? $in['applies_to'] : 'provider';
        $type = isset(self::TYPES[$in['field_type'] ?? '']) ? $in['field_type'] : 'text';
        $code = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)($in['code'] ?? '')) ?: $label));
        $code = trim(mb_substr($code, 0, 40), '_') ?: 'field_' . substr(uuid4(), 0, 8);
        $options = null;
        if ($type === 'select') {
            $opts = array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', (string)($in['options'] ?? ''))), fn($o) => $o !== ''));
            if ($opts === []) return [false, 'A choice list needs at least one option (one per line).'];
            $options = json_encode(array_slice($opts, 0, 100), JSON_UNESCAPED_UNICODE);
        }
        $pdo = Database::pdo();
        $sensitive = !empty($in['is_sensitive']) ? 1 : 0;
        // A sensitive field is never shown in packets or lists, whatever the boxes say.
        $data = [$label, mb_substr(trim((string)($in['help_text'] ?? '')), 0, 240) ?: null, $type, $options,
                 !empty($in['is_required']) ? 1 : 0, $sensitive, $sensitive ? 0 : (!empty($in['show_in_packet']) ? 1 : 0), $sensitive ? 0 : (!empty($in['show_in_list']) ? 1 : 0),
                 (int)($in['sort_order'] ?? 0), utc_now()];
        if ($id) {
            if (self::hasSensitiveColumns()) {
                $st = $pdo->prepare('UPDATE custom_field_definitions SET label=?, help_text=?, field_type=?, options=?, is_required=?, is_sensitive=?, show_in_packet=?, show_in_list=?, sort_order=?, updated_at=? WHERE id=? AND tenant_id=?');
                $st->execute([...$data, $id, $this->ctx->tenantId]);
            } else {
                $d2 = $data; array_splice($d2, 5, 1);  // drop is_sensitive
                $st = $pdo->prepare('UPDATE custom_field_definitions SET label=?, help_text=?, field_type=?, options=?, is_required=?, show_in_packet=?, show_in_list=?, sort_order=?, updated_at=? WHERE id=? AND tenant_id=?');
                $st->execute([...$d2, $id, $this->ctx->tenantId]);
            }
            audit_log($this->ctx, 'custom_field_updated', 'custom_field_definitions', $id, json_encode(['label' => $label]));
            return [true, 'Field updated.'];
        }
        $dup = $pdo->prepare('SELECT 1 FROM custom_field_definitions WHERE tenant_id=? AND applies_to=? AND code=?'); $dup->execute([$this->ctx->tenantId, $appliesTo, $code]);
        if ($dup->fetchColumn()) return [false, 'A field with that key already exists.'];
        $id = uuid4();
        if (self::hasSensitiveColumns()) {
            $pdo->prepare('INSERT INTO custom_field_definitions (id, tenant_id, applies_to, code, label, help_text, field_type, options, is_required, is_sensitive, show_in_packet, show_in_list, sort_order, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
                ->execute([$id, $this->ctx->tenantId, $appliesTo, $code, ...array_slice($data, 0, 9), utc_now()]);
        } else {
            $d2 = $data; array_splice($d2, 5, 1);  // drop is_sensitive
            $pdo->prepare('INSERT INTO custom_field_definitions (id, tenant_id, applies_to, code, label, help_text, field_type, options, is_required, show_in_packet, show_in_list, sort_order, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
                ->execute([$id, $this->ctx->tenantId, $appliesTo, $code, ...array_slice($d2, 0, 8), utc_now()]);
        }
        audit_log($this->ctx, 'custom_field_created', 'custom_field_definitions', $id, json_encode(['label' => $label, 'applies_to' => $appliesTo]));
        return [true, 'Field added.'];
    }

    /** Deactivate (values are kept — history is never lost). */
    public function retireDefinition(string $id): void
    {
        Database::pdo()->prepare('UPDATE custom_field_definitions SET active = 0, updated_at = ? WHERE id = ? AND tenant_id = ?')->execute([utc_now(), $id, $this->ctx->tenantId]);
        audit_log($this->ctx, 'custom_field_retired', 'custom_field_definitions', $id, null);
    }

    // ---- values -----------------------------------------------------------------

    /** definition_id => value for one record. */
    /**
     * definition_id => value. Sensitive values are decrypted only when the viewer may see that field
     * (Level 3); otherwise a mask is returned. Pass $raw=true for internal saves/validation only.
     */
    public function valuesFor(string $recordId, bool $raw = false): array
    {
        if (SupportAccess::active()) return [];
        // Level-3 columns (value_enc, is_sensitive) only exist after migration 119. Degrade cleanly on
        // installs that have not run it yet rather than throwing on every page that reads custom fields.
        $lvl3 = self::hasSensitiveColumns();
        $cols = $lvl3 ? 'v.definition_id, v.value_text, v.value_enc, d.is_sensitive'
                      : 'v.definition_id, v.value_text, NULL AS value_enc, 0 AS is_sensitive';
        $st = Database::pdo()->prepare('SELECT ' . $cols . ' FROM custom_field_values v
                                        JOIN custom_field_definitions d ON d.id = v.definition_id WHERE v.tenant_id = ? AND v.record_id = ?');
        $st->execute([$this->ctx->tenantId, $recordId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (!empty($r['is_sensitive'])) {
                $plain = $r['value_enc'] !== null ? (Crypto::decrypt($r['value_enc']) ?? '') : '';
                $out[$r['definition_id']] = ($raw || SensitiveFields::canView($this->ctx, 'cf:' . $r['definition_id'])) ? $plain : ($plain === '' ? '' : '••••••');
            } else {
                $out[$r['definition_id']] = $r['value_text'];
            }
        }
        return $out;
    }

    /** Labelled values for display/export: [['label'=>..,'value'=>..,'code'=>..], ...] in order. */
    public function labelled(string $appliesTo, string $recordId, bool $packetOnly = false): array
    {
        $vals = $this->valuesFor($recordId); $out = [];
        foreach ($this->definitions($appliesTo) as $d) {
            if (!empty($d['is_sensitive'])) continue;                       // Level 3: never in packets/exports
            if ($packetOnly && empty($d['show_in_packet'])) continue;
            $v = $vals[$d['id']] ?? null;
            if ($v === null || $v === '') continue;
            $out[] = ['code' => $d['code'], 'label' => $d['label'], 'value' => $d['field_type'] === 'checkbox' ? ($v === '1' ? 'Yes' : 'No') : $v];
        }
        return $out;
    }

    /** Validate + store posted values (cf[<definition_id>]). Returns [ok, message]. */
    public function save(string $appliesTo, string $recordId, array $post): array
    {
        $in = (array)($post['cf'] ?? []); $pdo = Database::pdo(); $errors = [];
        foreach ($this->definitions($appliesTo) as $d) {
            // Level 3: a sensitive field can only be set/changed by someone allowed to see it.
            if (!empty($d['is_sensitive']) && !SensitiveFields::canView($this->ctx, 'cf:' . $d['id'])) continue;
            $raw = $in[$d['id']] ?? null;
            $v = is_array($raw) ? '' : trim((string)$raw);
            if ($d['field_type'] === 'checkbox') $v = $v !== '' ? '1' : '0';
            if ($d['is_required'] && ($v === '' || ($d['field_type'] === 'checkbox' && $v === '0'))) { $errors[] = $d['label'] . ' is required.'; continue; }
            if ($v !== '') {
                if ($d['field_type'] === 'number' && !is_numeric($v)) { $errors[] = $d['label'] . ' must be a number.'; continue; }
                if ($d['field_type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $errors[] = $d['label'] . ' must be a date.'; continue; }
                if ($d['field_type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[] = $d['label'] . ' must be a valid email.'; continue; }
                if ($d['field_type'] === 'url' && !filter_var($v, FILTER_VALIDATE_URL)) { $errors[] = $d['label'] . ' must be a valid web address.'; continue; }
                if ($d['field_type'] === 'select' && !in_array($v, $d['options'], true)) { $errors[] = $d['label'] . ': choose one of the listed options.'; continue; }
            }
            $v = mb_substr($v, 0, 4000);
            if (!empty($d['is_sensitive'])) {
                if ($v === '••••••') continue;                                  // untouched mask re-submitted: keep stored value
                $pdo->prepare('INSERT INTO custom_field_values (id, tenant_id, definition_id, record_id, value_text, value_enc, updated_by, updated_at) VALUES (?,?,?,?,NULL,?,?,?)
                               ON DUPLICATE KEY UPDATE value_text = NULL, value_enc = VALUES(value_enc), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
                    ->execute([uuid4(), $this->ctx->tenantId, $d['id'], $recordId, $v === '' ? null : Crypto::encrypt($v), $this->ctx->userId, utc_now()]);
                SensitiveFields::logView($this->ctx, 'cf:' . $d['id'] . ':write', $recordId);
                continue;
            }
            $setEnc = self::hasSensitiveColumns() ? ', value_enc = NULL' : '';
            $pdo->prepare('INSERT INTO custom_field_values (id, tenant_id, definition_id, record_id, value_text, updated_by, updated_at) VALUES (?,?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)' . $setEnc . ', updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
                ->execute([uuid4(), $this->ctx->tenantId, $d['id'], $recordId, $v, $this->ctx->userId, utc_now()]);
        }
        return $errors ? [false, implode(' ', $errors)] : [true, 'Saved.'];
    }

    /** Render the inputs for a form (uses the app's standard field markup). */
    public function render(string $appliesTo, string $recordId): string
    {
        $defs = $this->definitions($appliesTo); if ($defs === []) return '';
        $vals = $this->valuesFor($recordId); $h = '';
        foreach ($defs as $d) {
            if (!empty($d['is_sensitive']) && !SensitiveFields::canView($this->ctx, 'cf:' . $d['id'])) continue;   // hidden, not just masked
            $v = (string)($vals[$d['id']] ?? ''); $n = 'cf[' . e($d['id']) . ']'; $req = $d['is_required'] ? ' required' : '';
            $lbl = '<label>' . e($d['label']) . ($d['is_required'] ? ' <span class="hint">*</span>' : '') . (!empty($d['is_sensitive']) ? ' <span class="chip warn" style="font-size:.58rem">' . e(t('sensitive')) . '</span>' : '') . '</label>';
            $help = $d['help_text'] ? '<div class="hint">' . e($d['help_text']) . '</div>' : '';
            switch ($d['field_type']) {
                case 'textarea': $ctl = '<textarea name="' . $n . '" rows="3"' . $req . '>' . e($v) . '</textarea>'; break;
                case 'select':
                    $ctl = '<select name="' . $n . '"' . $req . '><option value="">—</option>';
                    foreach ($d['options'] as $o) $ctl .= '<option value="' . e($o) . '"' . ($v === $o ? ' selected' : '') . '>' . e($o) . '</option>';
                    $ctl .= '</select>'; break;
                case 'checkbox': $ctl = '<label style="font-weight:normal"><input type="checkbox" name="' . $n . '" value="1"' . ($v === '1' ? ' checked' : '') . '> ' . e($d['label']) . '</label>'; $lbl = ''; break;
                case 'number': $ctl = '<input type="number" step="any" name="' . $n . '" value="' . e($v) . '"' . $req . '>'; break;
                case 'date':   $ctl = '<input type="date" name="' . $n . '" value="' . e($v) . '"' . $req . '>'; break;
                case 'email':  $ctl = '<input type="email" name="' . $n . '" value="' . e($v) . '"' . $req . '>'; break;
                case 'url':    $ctl = '<input type="url" name="' . $n . '" value="' . e($v) . '"' . $req . '>'; break;
                default:       $ctl = '<input type="text" name="' . $n . '" value="' . e($v) . '"' . $req . '>';
            }
            $h .= '<div>' . $lbl . $ctl . $help . '</div>';
        }
        return $h;
    }
}
