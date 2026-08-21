<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;

/**
 * TimeReportModel — computes a deduped hours union and buckets it.
 *
 * The aggregation methods are pure (operate on plain arrays) so the union/dedup
 * and bucketing math is unit-testable without a database. Data gathering (the
 * report() method, added in a later task) normalizes Kanboard rows into the
 * shapes these pure methods consume.
 */
class TimeReportModel extends Base
{
    /**
     * Build the deduped flat contribution list.
     *
     * Source 1 (granular truth): subtask time rows for the user whose task is in
     * $projectId and whose start ts is in [$startTs,$endTs]. Each contributes
     * time_spent (hours) or (end-start)/3600 when time_spent is 0, dated by start.
     * Every such in-range task id is recorded in $subtaskTaskIds.
     *
     * Source 2 (fallback): tasks in $projectId owned by the user with time_spent>0
     * and date_completed in range whose id is NOT in $subtaskTaskIds. Contributes
     * the full time_spent, dated by date_completed.
     *
     * @return array{0: list<array{task_id:int,hours:float,date:string}>, 1: array<int,bool>}
     */
    public static function buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, int $userId): array
    {
        $contributions = [];
        $subtaskTaskIds = [];

        foreach ($subtaskRows as $row) {
            if ((int) $row['project_id'] !== $projectId || (int) $row['user_id'] !== $userId) {
                continue;
            }
            $start = (int) $row['start'];
            if ($start < $startTs || $start > $endTs) {
                continue;
            }
            $taskId = (int) $row['task_id'];
            $subtaskTaskIds[$taskId] = true;

            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $end = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => (float) $hours,
                'date'    => date('Y-m-d', $start),
            ];
        }

        foreach ($taskRows as $task) {
            if ((int) $task['project_id'] !== $projectId || (int) $task['owner_id'] !== $userId) {
                continue;
            }
            $timeSpent = (float) $task['time_spent'];
            if ($timeSpent <= 0) {
                continue;
            }
            $completed = (int) $task['date_completed'];
            if ($completed < $startTs || $completed > $endTs) {
                continue;
            }
            $taskId = (int) $task['id'];
            if (isset($subtaskTaskIds[$taskId])) {
                continue; // dedup: represented by source 1
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => $timeSpent,
                'date'    => date('Y-m-d', $completed),
            ];
        }

        return [$contributions, $subtaskTaskIds];
    }
}
