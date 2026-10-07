<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use PDO;

/**
 * Progress figures (spec Kanboard #5377/#5378). Task meters are pure over the
 * board row core already selects (nb_subtasks, nb_completed_subtasks,
 * time_spent, time_estimated), so a card costs no query. Project progress is
 * two queries: one COUNT pair, one grouped milestone query.
 */
class ProgressModel extends Base
{
    public const MILESTONE_LABEL = 'is a milestone of';

    public static function pct(int $done, int $total): int
    {
        return $total > 0 ? (int) round($done * 100 / $total) : 0;
    }

    public static function taskMeters(array $task): array
    {
        $total = (int) ($task['nb_subtasks'] ?? 0);
        $done  = (int) ($task['nb_completed_subtasks'] ?? 0);
        $spent = (float) ($task['time_spent'] ?? 0);
        $est   = (float) ($task['time_estimated'] ?? 0);
        $hasEstimate = $est > 0;

        return [
            'task_id'        => (int) ($task['id'] ?? 0),
            'subtasks_done'  => $done,
            'subtasks_total' => $total,
            'pct'            => self::pct($done, $total),
            'time_spent'     => $spent,
            'time_estimated' => $est,
            'time_pct'       => $hasEstimate ? (int) min(100, round($spent * 100 / $est)) : 0,
            'time_state'     => $hasEstimate ? ($spent > $est ? 'over' : 'ok') : 'none',
            'has_subtasks'   => $total > 0,
            'has_estimate'   => $hasEstimate,
        ];
    }

    public function taskProgress(int $taskId): ?array
    {
        $row = $this->taskFinderModel->getExtendedQuery()->eq('tasks.id', $taskId)->findOne();
        return $row ? self::taskMeters($row) : null;
    }

    public function projectProgress(int $projectId): array
    {
        $counts = $this->db->execute(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS closed FROM tasks WHERE project_id = ?',
            [$projectId]
        )->fetch(PDO::FETCH_ASSOC);

        $rows = $this->db->execute(
            'SELECT m.id AS task_id, m.title, m.date_due,
                    COUNT(mem.id) AS total,
                    SUM(CASE WHEN mem.is_active = 0 THEN 1 ELSE 0 END) AS closed
             FROM tasks m
             JOIN task_has_links tl ON tl.task_id = m.id
             JOIN links l ON l.id = tl.link_id AND l.label = ?
             JOIN tasks mem ON mem.id = tl.opposite_task_id AND mem.project_id = m.project_id
             WHERE m.project_id = ? AND m.is_active = 1
             GROUP BY m.id, m.title, m.date_due',
            [self::MILESTONE_LABEL, $projectId]
        )->fetchAll(PDO::FETCH_ASSOC);

        $milestones = array_map(fn (array $r) => self::milestoneRow($r), $rows);
        usort($milestones, static function (array $a, array $b): int {
            if (($a['due'] === '') !== ($b['due'] === '')) {
                return $a['due'] === '' ? 1 : -1;
            }
            return [$a['due'], $a['task_id']] <=> [$b['due'], $b['task_id']];
        });

        $total  = (int) ($counts['total'] ?? 0);
        $closed = (int) ($counts['closed'] ?? 0);

        return ['project_id' => $projectId, 'closed' => $closed, 'total' => $total, 'pct' => self::pct($closed, $total), 'milestones' => $milestones];
    }

    public function milestoneProgress(int $milestoneTaskId): ?array
    {
        $rows = $this->db->execute(
            'SELECT m.id AS task_id, m.title, m.date_due, mem.id AS member_id, mem.is_active
             FROM tasks m
             JOIN task_has_links tl ON tl.task_id = m.id
             JOIN links l ON l.id = tl.link_id AND l.label = ?
             JOIN tasks mem ON mem.id = tl.opposite_task_id AND mem.project_id = m.project_id
             WHERE m.id = ?
             ORDER BY mem.id',
            [self::MILESTONE_LABEL, $milestoneTaskId]
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return null;
        }

        $members = array_map(fn (array $r) => (int) $r['member_id'], $rows);
        $closed  = count(array_filter($rows, fn (array $r) => (int) $r['is_active'] === 0));

        return self::milestoneRow(['task_id' => $rows[0]['task_id'], 'title' => $rows[0]['title'], 'date_due' => $rows[0]['date_due'],
            'total' => count($rows), 'closed' => $closed]) + ['members' => $members];
    }

    private static function milestoneRow(array $r): array
    {
        $total  = (int) $r['total'];
        $closed = (int) $r['closed'];
        $due    = (int) ($r['date_due'] ?? 0);

        return [
            'task_id' => (int) $r['task_id'],
            'title'   => (string) $r['title'],
            'closed'  => $closed,
            'total'   => $total,
            'pct'     => self::pct($closed, $total),
            'due'     => $due > 0 ? date('Y-m-d', $due) : '',
        ];
    }
}
