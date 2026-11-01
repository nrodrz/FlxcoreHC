<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC\Repository;

/** Tenant-configurable calendar event types (manual events pick one; 'deadline' is system). */
final class CalendarEventTypeRepository extends TenantRepository
{
    protected function table(): string { return 'calendar_event_types'; }
    protected function fillable(): array { return ['code', 'label', 'color', 'is_system', 'is_active', 'sort']; }

    /** Active types ordered for display. */
    public function active(): array
    {
        return $this->all(['is_active' => 1], 'sort ASC, label ASC');
    }

    /** code => label map (includes inactive, for rendering historical events). */
    public function labelMap(): array
    {
        $out = [];
        foreach ($this->all([], 'sort ASC') as $t) { $out[$t['code']] = $t['label']; }
        return $out;
    }

    /** code => color map for the calendar UI. */
    public function colorMap(): array
    {
        $out = [];
        foreach ($this->all([], 'sort ASC') as $t) { if (!empty($t['color'])) $out[$t['code']] = $t['color']; }
        return $out;
    }

    public function findByCode(string $code): ?array
    {
        $rows = $this->all(['code' => $code], 'sort ASC', 1);
        return $rows[0] ?? null;
    }
}
