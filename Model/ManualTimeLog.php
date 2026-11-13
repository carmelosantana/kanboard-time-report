<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\SubtaskTimeTrackingModel;
use Kanboard\Model\TaskModel;

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
        // Compare-then-write must be atomic per subtask: two requests that both read an
        // empty ledger would both log the same hours, and the report would bill them twice.
        $ownsTransaction = ! $this->db->getConnection()->inTransaction();
        if ($ownsTransaction) {
            $this->db->startTransaction();
        }

        try {
            // A no-op write: it takes the row lock (MySQL, Postgres) or the database
            // write lock (SQLite) before anything is read, so a concurrent sync of the
            // same subtask waits here and then sees this one's entries.
            $this->db->execute('UPDATE ' . SubtaskModel::TABLE . ' SET id = id WHERE id = ?', [$subtaskId]);
            $this->reconcile($subtaskId, $userId, $now ?? time());
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->cancelTransaction();
            }
            throw $e;
        }

        if ($ownsTransaction) {
            $this->db->closeTransaction();
        }
    }

    /**
     * Give the undated manual time of the chosen tasks an entry date, so it is reported
     * on that day. Only ledger rows with no date are touched, and only on tasks of
     * $projectId; $ownUserId limits it to that person's subtasks.
     *
     * @param  int[] $taskIds
     * @return float the hours that were dated
     */
    public function dateUndated(int $projectId, array $taskIds, int $enteredAt, ?int $ownUserId = null): float
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === [] || $enteredAt <= 0) {
            return 0.0;
        }

        $query = $this->db->table(self::TABLE)
            ->columns(self::TABLE . '.id', self::TABLE . '.hours')
            ->join(SubtaskModel::TABLE, 'id', 'subtask_id')
            ->join(TaskModel::TABLE, 'id', 'task_id', SubtaskModel::TABLE)
            ->eq(self::TABLE . '.entered_at', 0)
            ->eq(TaskModel::TABLE . '.project_id', $projectId)
            ->in(SubtaskModel::TABLE . '.task_id', $taskIds);
        if ($ownUserId !== null) {
            $query->eq(SubtaskModel::TABLE . '.user_id', $ownUserId);
        }

        $rows = $query->findAll();
        if ($rows === []) {
            return 0.0;
        }

        $this->db->table(self::TABLE)->in('id', array_column($rows, 'id'))->update(['entered_at' => $enteredAt]);

        return round(array_sum(array_map(static fn ($r) => (float) $r['hours'], $rows)), 2);
    }

    private function reconcile(int $subtaskId, int $userId, int $now): void
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
                'entered_at' => $now,
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
