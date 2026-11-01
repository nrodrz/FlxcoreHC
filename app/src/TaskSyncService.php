<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Repository\RenewalTaskRepository;

/**
 * Reconciles compliance gaps with renewal tasks — the "automation" layer that
 * turns the compliance engine's findings into work items, with no cron
 * dependency (runs opportunistically when compliance pages load).
 *
 * Per producer requirement gap (missing / expired / expiring):
 *   - ensure exactly one open auto task exists (keyed by source_key)
 *   - due dates: expiring → the credential's expiry date;
 *                expired  → 7 days out; missing → 21 days out
 *   - priority: expiring → normal; expired/missing → high
 * When a gap resolves (or the requirement is unassigned), open auto tasks are
 * closed automatically. If a person marks an auto task done while the gap
 * persists, we respect that for 30 days before re-raising (so next year's
 * renewal still generates a fresh task).
 */
final class TaskSyncService
{
    /** @return array{created:int, closed:int} */
    /** @param array<string,array<string,string>> $assignees holderId => (token => userId) */
    public static function sync(RenewalTaskRepository $tasks, array $producers, array $assignees = []): array
    {
        $existing = $tasks->autoMap();
        $liveKeys = [];
        $created = 0; $closed = 0;

        foreach ($producers as $p) {
            $h = $p['holder'];
            $pName = $h['holder_type'] === 'individual'
                ? trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))
                : (string)($h['org_name'] ?? 'Provider');

            foreach ($p['rows'] as $r) {
                $key = 'gap:' . $h['id'] . ':' . $r['token'];
                $st = (string)$r['status'];
                if ($st === 'active') continue;          // no gap
                $liveKeys[$key] = true;

                $prior = $existing[$key] ?? null;
                if ($prior !== null) {
                    if ($prior['status'] !== 'done') continue;         // open task already tracks it
                    // Done but gap persists/reappeared: re-raise only if auto-closed
                    // earlier, or the human completion is older than 30 days.
                    $steps = json_decode((string)($prior['steps'] ?? ''), true) ?: [];
                    $humanDone = empty($steps['auto_closed']);
                    $doneAt = strtotime((string)($prior['completed_at'] ?? '')) ?: 0;
                    if ($humanDone && $doneAt > strtotime('-30 days')) continue;
                }

                $carrierBit = !empty($r['carrier']) ? ' (' . $r['carrier'] . ')' : '';
                [$verb, $due, $pri] = match ($st) {
                    'expiring' => ['Renew', $r['expiry'] ?? today_local('+14 days'), 'normal'],
                    'expired'  => ['Renew', today_local('+7 days'), 'high'],
                    default    => ['Obtain', today_local('+21 days'), 'high'],
                };
                // If someone is accountable for this requirement (entity duties usually
                // are), the task lands on them rather than in a general pile.
                $owner = $assignees[$h['id']][$r['token']] ?? null;
                $tasks->insert([
                    'credential_id' => $r['credential_id'] ?: null,
                    'holder_id'     => $h['id'],
                    'title'         => $verb . ': ' . $r['name'] . $carrierBit . ' — ' . $pName,
                    'status'        => 'pending',
                    'due_date'      => substr((string)$due, 0, 10),
                    'priority'      => $pri,
                    'assigned_to'   => $owner,
                    'steps'         => json_encode(['auto' => 1, 'status_when_raised' => $st]),
                    'source_key'    => $key,
                ]);
                $created++;
            }
        }

        // Auto-close open auto tasks whose gap no longer exists.
        foreach ($existing as $key => $t) {
            if (isset($liveKeys[$key]) || $t['status'] === 'done') continue;
            $steps = json_decode((string)($t['steps'] ?? ''), true) ?: [];
            $steps['auto_closed'] = 1;
            $tasks->update($t['id'], [
                'status' => 'done', 'completed_at' => utc_now(), 'steps' => json_encode($steps),
            ]);
            $closed++;
        }
        return ['created' => $created, 'closed' => $closed];
    }
}
