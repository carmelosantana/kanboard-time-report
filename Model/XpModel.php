<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use PDO;
use PicoDb\SQLException;

/**
 * XP rules (spec Kanboard #5379): a done subtask earns 10 for its assignee; a
 * closed task earns 25 + 10 × score for its assignee. Unassigned work and
 * logged hours earn nothing. Amounts are derived from live rows, so reopening
 * removes XP by construction. Ranges date subtasks by completion stamp and
 * tasks by date_completed.
 */
class XpModel extends Base
{
    public const SUBTASK_XP = 10;
    public const TASK_XP    = 25;
    public const SCORE_XP   = 10;

    public static function xpForLevel(int $level): int
    {
        return 50 * $level * ($level - 1);
    }

    public static function levelFor(int $xp): int
    {
        $level = 1;
        while (self::xpForLevel($level + 1) <= $xp) {
            $level++;
        }
        return $level;
    }

    public static function nextFor(int $xp): int
    {
        return self::xpForLevel(self::levelFor($xp) + 1) - $xp;
    }

    /** @return array<int,int> project_id => xp */
    public function deriveUser(int $userId, ?int $startTs = null, ?int $endTs = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        [$subRange, $subArgs]   = $this->subtaskRange($startTs, $endTs);
        [$taskRange, $taskArgs] = $this->taskRange($startTs, $endTs);

        $subtasks = $this->db->execute(
            "SELECT t.project_id AS k, COUNT(*) AS n
             FROM subtasks s JOIN tasks t ON t.id = s.task_id {$subRange['join']}
             WHERE s.user_id = ? AND s.status = 2 {$subRange['where']}
             GROUP BY t.project_id",
            array_merge([$userId], $subArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        $tasks = $this->db->execute(
            "SELECT project_id AS k, COUNT(*) AS n, COALESCE(SUM(score), 0) AS score
             FROM tasks WHERE owner_id = ? AND is_active = '0' {$taskRange}
             GROUP BY project_id",
            array_merge([$userId], $taskArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::combine($subtasks, $tasks);
    }

    /** @return array<int,int> user_id => xp within one project */
    public function byUser(int $projectId, ?int $startTs = null, ?int $endTs = null): array
    {
        [$subRange, $subArgs]   = $this->subtaskRange($startTs, $endTs);
        [$taskRange, $taskArgs] = $this->taskRange($startTs, $endTs);

        $subtasks = $this->db->execute(
            "SELECT s.user_id AS k, COUNT(*) AS n
             FROM subtasks s JOIN tasks t ON t.id = s.task_id {$subRange['join']}
             WHERE t.project_id = ? AND s.status = 2 AND s.user_id > 0 {$subRange['where']}
             GROUP BY s.user_id",
            array_merge([$projectId], $subArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        $tasks = $this->db->execute(
            "SELECT owner_id AS k, COUNT(*) AS n, COALESCE(SUM(score), 0) AS score
             FROM tasks WHERE project_id = ? AND is_active = '0' AND owner_id > 0 {$taskRange}
             GROUP BY owner_id",
            array_merge([$projectId], $taskArgs)
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::combine($subtasks, $tasks);
    }

    /** @return array<int,int> agent_user_id => owner_user_id; [] without the Agents plugin */
    public function agentOwnerMap(): array
    {
        try {
            $rows = $this->db->table('agents')->columns('agent_user_id', 'owner_user_id')->findAll();
        } catch (SQLException $e) {
            return [];
        }

        $map = [];
        foreach ($rows ?: [] as $r) {
            $map[(int) $r['agent_user_id']] = (int) $r['owner_user_id'];
        }
        return $map;
    }

    private function subtaskRange(?int $startTs, ?int $endTs): array
    {
        if ($startTs === null || $endTs === null) {
            return [['join' => '', 'where' => ''], []];
        }
        return [[
            'join'  => 'JOIN ' . SubtaskCompletionStamp::TABLE . ' c ON c.subtask_id = s.id',
            'where' => 'AND c.completed_at BETWEEN ? AND ?',
        ], [$startTs, $endTs]];
    }

    private function taskRange(?int $startTs, ?int $endTs): array
    {
        if ($startTs === null || $endTs === null) {
            return ['', []];
        }
        return ['AND date_completed BETWEEN ? AND ?', [$startTs, $endTs]];
    }

    private static function combine(array $subtasks, array $tasks): array
    {
        $xp = [];
        foreach ($subtasks as $r) {
            $xp[(int) $r['k']] = ($xp[(int) $r['k']] ?? 0) + (int) $r['n'] * self::SUBTASK_XP;
        }
        foreach ($tasks as $r) {
            $xp[(int) $r['k']] = ($xp[(int) $r['k']] ?? 0) + (int) $r['n'] * self::TASK_XP + (int) $r['score'] * self::SCORE_XP;
        }
        ksort($xp);
        return $xp;
    }
}
