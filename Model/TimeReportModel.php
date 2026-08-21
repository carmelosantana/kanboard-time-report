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

    public static function dayKey(int $ts): string
    {
        return date('Y-m-d', $ts);
    }

    /** ISO-8601 week key: <ISO-year>-W<2-digit ISO week>. */
    public static function weekKey(int $ts): string
    {
        return date('o', $ts) . '-W' . date('W', $ts);
    }

    /** "Mon d – Sun d" span for the ISO week containing $ts. */
    public static function weekLabel(int $ts): string
    {
        $monday = strtotime('monday this week', $ts);
        $sunday = strtotime('sunday this week', $ts);
        return date('M d', $monday) . ' – ' . date('M d', $sunday);
    }

    /**
     * Sum contributions into breakdown rows per the chosen granularity.
     *
     * @param  list<array{task_id:int,hours:float,date:string}> $contributions
     * @param  array<int,array{reference:string,title:string}>  $taskMeta
     * @return array{total_hours:float, breakdown: list<array{key:string,label:string,hours:float,task_count:int}>}
     */
    public static function bucket(array $contributions, string $granularity, array $taskMeta = []): array
    {
        $buckets = []; // key => ['label'=>, 'hours'=>, 'tasks'=>[id=>true], 'sort'=>]
        $total = 0.0;

        foreach ($contributions as $c) {
            $hours  = (float) $c['hours'];
            $taskId = (int) $c['task_id'];
            $total += $hours;
            $ts = strtotime($c['date'] . ' 12:00:00');

            switch ($granularity) {
                case 'week':
                    $key   = self::weekKey($ts);
                    $label = self::weekLabel($ts);
                    $sort  = $key;
                    break;
                case 'task':
                    $key   = (string) $taskId;
                    $ref   = $taskMeta[$taskId]['reference'] ?? '';
                    $title = $taskMeta[$taskId]['title'] ?? '';
                    $label = $title !== '' ? ('#' . $taskId . ' ' . $title) : ('#' . $taskId);
                    $sort  = ($ref !== '' ? $ref : str_pad((string) $taskId, 12, '0', STR_PAD_LEFT));
                    break;
                case 'total':
                    $key   = 'total';
                    $label = t('Total');
                    $sort  = 'total';
                    break;
                case 'day':
                default:
                    $key   = self::dayKey($ts);
                    $label = $key;
                    $sort  = $key;
                    break;
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'hours' => 0.0, 'tasks' => [], 'sort' => $sort];
            }
            $buckets[$key]['hours'] += $hours;
            $buckets[$key]['tasks'][$taskId] = true;
        }

        uasort($buckets, static fn ($a, $b) => strcmp((string) $a['sort'], (string) $b['sort']));

        $breakdown = [];
        foreach ($buckets as $key => $b) {
            $breakdown[] = [
                'key'        => (string) $key,
                'label'      => $b['label'],
                'hours'      => (float) $b['hours'],
                'task_count' => count($b['tasks']),
            ];
        }

        return ['total_hours' => (float) $total, 'breakdown' => $breakdown];
    }
}
