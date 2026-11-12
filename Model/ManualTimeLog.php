<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\SubtaskTimeTrackingModel;

/**
 * Dates time typed into a subtask's "Time spent" field (core keeps only the total).
 *
 * A subtask's manual time is its recorded total minus every logger's timer-tracked
 * hours. sync() compares that with what the ledger already holds and logs the
 * difference, so it is safe to call on any subtask event, any number of times.
 */
class ManualTimeLog extends Base
{
    public const TABLE = 'timereport_manual_time';

    public function sync(int $subtaskId, int $userId, ?int $now = null): void
    {
        $subtask = $this->db->table(SubtaskModel::TABLE)->eq('id', $subtaskId)->findOne();
        if (empty($subtask)) {
            return;
        }

        $tracked = 0.0;
        foreach ($this->db->table(SubtaskTimeTrackingModel::TABLE)->eq('subtask_id', $subtaskId)->findAll() as $row) {
            $tracked += TimeReportModel::rowHours($row);
        }

        $entries = $this->db->table(self::TABLE)->eq('subtask_id', $subtaskId)->desc('entered_at')->desc('id')->findAll();
        $logged  = array_sum(array_map(static fn ($e) => (float) $e['hours'], $entries));

        $manual = max(0.0, round((float) $subtask['time_spent'] - $tracked, 2));
        $delta  = round($manual - $logged, 2);

        if ($delta >= 0.01) {
            $this->db->table(self::TABLE)->insert([
                'subtask_id' => $subtaskId,
                'user_id'    => $userId,
                'hours'      => $delta,
                'entered_at' => $now ?? time(),
            ]);
            return;
        }

        // A reduction comes off the newest entry first: correcting today's typo must
        // not eat into hours dated in an earlier (possibly invoiced) week.
        $remove = -$delta;
        foreach ($entries as $entry) {
            if ($remove < 0.01) {
                break;
            }
            $hours = (float) $entry['hours'];
            $left  = round($hours - $remove, 2);
            if ($left < 0.01) {
                $this->db->table(self::TABLE)->eq('id', $entry['id'])->remove();
            } else {
                $this->db->table(self::TABLE)->eq('id', $entry['id'])->update(['hours' => $left]);
            }
            $remove = round($remove - $hours, 2);
        }
    }
}
