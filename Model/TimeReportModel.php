<?php

namespace Kanboard\Plugin\TimeReport\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;

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

            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $end = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }

            // An entry that rounds to 0.00h at the report's own 2-dp precision represents no
            // logged work — a still-running timer, or an instant start/stop toggle that spans
            // only a second or two. It does NOT count as subtask time for dedup and adds no
            // contribution, so it can never hide a task's real task-level time_spent fallback.
            if (round($hours, 2) <= 0) {
                continue;
            }

            $taskId = (int) $row['task_id'];
            $subtaskTaskIds[$taskId] = true;

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

    /**
     * Detect subtask time recorded on the subtask but not date-tracked (so it is not
     * counted in the report). Untracked = recorded time_spent − the user's tracked hours
     * for that subtask (clamped >= 0), flagged when >= 0.01h. Grouped per task.
     *
     * @param  list<array{subtask_id:int,task_id:int,time_spent:float}> $subtaskRecords
     * @param  array<int,float>                                         $trackedBySubtask
     * @param  array<int,array{reference:string,title:string}>          $taskMeta
     * @return array{task_count:int, subtask_count:int, total_hours:float, tasks: list<array{task_id:int,reference:string,title:string,hours:float}>}
     */
    public static function findUntrackedSubtaskTime(array $subtaskRecords, array $trackedBySubtask, array $taskMeta): array
    {
        $byTask = [];       // task_id => hours (untracked sum)
        $subtaskCount = 0;
        $total = 0.0;

        foreach ($subtaskRecords as $rec) {
            $recorded  = round((float) $rec['time_spent'], 2);
            $tracked   = round((float) ($trackedBySubtask[(int) $rec['subtask_id']] ?? 0.0), 2);
            $untracked = round($recorded - $tracked, 2);
            if ($untracked < 0.01) {
                continue;
            }
            $taskId = (int) $rec['task_id'];
            $byTask[$taskId] = round(($byTask[$taskId] ?? 0.0) + $untracked, 2);
            $subtaskCount++;
            $total = round($total + $untracked, 2);
        }

        $tasks = [];
        foreach ($byTask as $taskId => $hours) {
            $ref = (string) ($taskMeta[$taskId]['reference'] ?? '');
            $tasks[] = [
                'task_id'   => $taskId,
                'reference' => $ref,
                'title'     => (string) ($taskMeta[$taskId]['title'] ?? ''),
                'hours'     => (float) $hours,
                '_sort'     => $ref !== '' ? $ref : str_pad((string) $taskId, 12, '0', STR_PAD_LEFT),
            ];
        }
        usort($tasks, static fn ($a, $b) => [$a['_sort'], $a['task_id']] <=> [$b['_sort'], $b['task_id']]);
        foreach ($tasks as &$t) {
            unset($t['_sort']);
        }
        unset($t);

        return [
            'task_count'    => count($tasks),
            'subtask_count' => $subtaskCount,
            'total_hours'   => (float) $total,
            'tasks'         => $tasks,
        ];
    }

    /**
     * May $userId include OTHER users' hours in reports for $projectId?
     *
     * App administrators always may. Otherwise the requester must hold the
     * project-manager role on this specific project. Note that app-manager is
     * deliberately NOT sufficient: it confers project creation, not visibility
     * into an existing project's time.
     */
    public function canReportOnOthers(int $projectId, int $userId): bool
    {
        if ($this->userSession->isAdmin()) {
            return true;
        }

        return $this->projectUserRoleModel->getUserRole($projectId, $userId) === Role::PROJECT_MANAGER;
    }

    /**
     * Resolve the requested scope into a subject set that is safe to report on.
     *
     * $allUsers carries the "every participant" INTENT rather than a pre-resolved id
     * list. That distinction is what makes denial visible: an unauthorized request for
     * the whole team must raise $scopeDenied, not quietly collapse to a set of one that
     * looks like it was never asking for anyone else.
     *
     * Fail-closed: without permission the set collapses to the requester alone and
     * $scopeDenied is raised so the UI can say so out loud (silently narrowing would
     * under-bill with no signal). Requested ids are also intersected with actual
     * participants, so a tampered user_ids[] can neither read a stranger's hours nor
     * probe for the existence of a user id.
     *
     * @param  ?array $requested       Explicit ids from the request; null when none given.
     * @param  bool   $allUsers        The scope=all intent.
     * @param  array  $participantIds  Ids with hours in this project + range.
     * @return array{0: list<int>, 1: bool}  [subject ids, scope denied]
     */
    public static function sanitizeSubjectUserIds(?array $requested, bool $allUsers, int $requestingUserId, array $participantIds, bool $canReportOnOthers): array
    {
        // 0 is Kanboard's "unassigned" sentinel, never a person to report on.
        $requestedIds = $requested === null
            ? null
            : array_values(array_unique(array_filter(array_map('intval', $requested), static fn ($id) => $id > 0)));

        $wantsOthers = $allUsers
            || ($requestedIds !== null && $requestedIds !== [] && array_diff($requestedIds, [$requestingUserId]) !== []);

        if (! $canReportOnOthers) {
            return [[$requestingUserId], $wantsOthers];
        }

        $participants = array_values(array_unique(array_map('intval', $participantIds)));

        if ($allUsers) {
            $resolved = $participants;
        } elseif ($requestedIds === null) {
            return [[$requestingUserId], false];
        } else {
            $resolved = array_values(array_intersect($requestedIds, $participants));
        }

        if ($resolved === []) {
            return [[$requestingUserId], false];
        }

        sort($resolved);

        return [$resolved, false];
    }

    /** Throw unless $projectId is one the user may access. Always call first. */
    public function assertProjectAccess(int $projectId, int $userId): void
    {
        $allowed = $this->projectPermissionModel->getActiveProjectIds($userId);
        if (! in_array($projectId, array_map('intval', $allowed), true)) {
            throw new AccessForbiddenException(t('You are not allowed to access this project.'));
        }
    }

    /**
     * Compute the full report aggregate for one project + range for $userId.
     * AI is not attached here (ai => null); the controller adds it when enabled.
     *
     * @return array Report aggregate (see plan Data shapes).
     */
    public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId): array
    {
        $this->assertProjectAccess($projectId, $userId);

        $startTs = (int) strtotime($startDate . ' 00:00:00');
        $endTs   = (int) strtotime($endDate . ' 23:59:59');

        // Source 1: normalize the user's subtask time rows → map subtask→task→project.
        $subtaskRows = $this->gatherSubtaskRows($userId, $projectId);
        // Source 2: completed tasks assigned to the user in this project.
        $taskRows = $this->gatherCompletedTaskRows($projectId, $userId);

        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $userId);

        // Task meta for `task` granularity labels.
        $taskMeta = [];
        foreach ($taskRows as $t) {
            $taskMeta[(int) $t['id']] = ['reference' => (string) ($t['reference'] ?? ''), 'title' => (string) ($t['title'] ?? '')];
        }

        $bucketed = self::bucket($contributions, $granularity, $taskMeta);

        $project = $this->projectModel->getById($projectId);

        $report = [
            'project_id'     => $projectId,
            'project_name'   => (string) ($project['name'] ?? ('#' . $projectId)),
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'granularity'    => $granularity,
            'total_hours'    => $bucketed['total_hours'],
            'breakdown'      => $bucketed['breakdown'],
            'include_detail' => $includeDetail,
            'detail'         => [],
            'ai'             => null,
        ];

        if ($includeDetail) {
            $report['detail'] = $this->buildDetail($contributions, $taskRows, $startTs, $endTs);
        }

        [$untrackedRecords, $trackedBySubtask, $untrackedTaskMeta] = $this->gatherUntrackedInputs($projectId, $userId);
        $report['untracked'] = self::findUntrackedSubtaskTime($untrackedRecords, $trackedBySubtask, $untrackedTaskMeta);

        return $report;
    }

    /** Normalize the user's subtask time rows into the buildContributions shape (task_id + project_id resolved). */
    private function gatherSubtaskRows(int $userId, int $projectId): array
    {
        // getUserQuery joins subtasks→tasks, exposing task_id and project_id.
        $rows = $this->subtaskTimeTrackingModel->getUserQuery($userId)->findAll();
        $normalized = [];
        foreach ($rows as $r) {
            $taskId = (int) $r['task_id'];
            $normalized[] = [
                'task_id'    => $taskId,
                'project_id' => (int) $r['project_id'],
                'user_id'    => $userId,
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }
        return $normalized;
    }

    /**
     * Inputs for findUntrackedSubtaskTime(): the user's subtasks in the project that
     * carry a recorded time_spent, and the user's tracked hours per subtask.
     *
     * @return array{0: list<array{subtask_id:int,task_id:int,time_spent:float}>, 1: array<int,float>, 2: array<int,array{reference:string,title:string}>}
     */
    private function gatherUntrackedInputs(int $projectId, int $userId): array
    {
        $rows = $this->db->table(\Kanboard\Model\SubtaskModel::TABLE)
            ->columns(
                \Kanboard\Model\SubtaskModel::TABLE . '.id',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\SubtaskModel::TABLE . '.time_spent',
                \Kanboard\Model\TaskModel::TABLE . '.reference',
                \Kanboard\Model\TaskModel::TABLE . '.title'
            )
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id')
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->eq(\Kanboard\Model\SubtaskModel::TABLE . '.user_id', $userId)
            ->gt(\Kanboard\Model\SubtaskModel::TABLE . '.time_spent', 0)
            ->findAll();

        $records  = [];
        $taskMeta = [];
        foreach ($rows as $r) {
            $records[] = [
                'subtask_id' => (int) $r['id'],
                'task_id'    => (int) $r['task_id'],
                'time_spent' => (float) $r['time_spent'],
            ];
            $taskMeta[(int) $r['task_id']] = [
                'reference' => (string) ($r['reference'] ?? ''),
                'title'     => (string) ($r['title'] ?? ''),
            ];
        }

        // Sum the user's tracked hours per subtask (same hours math as the report).
        $trackedBySubtask = [];
        foreach ($this->subtaskTimeTrackingModel->getUserQuery($userId)->findAll() as $tt) {
            $sid       = (int) $tt['subtask_id'];
            $timeSpent = (float) $tt['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $start = (int) $tt['start'];
                $end   = (int) $tt['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }
            $trackedBySubtask[$sid] = ($trackedBySubtask[$sid] ?? 0.0) + $hours;
        }

        return [$records, $trackedBySubtask, $taskMeta];
    }

    /** Completed tasks assigned to $userId in $projectId (all statuses so completed/closed are included). */
    private function gatherCompletedTaskRows(int $projectId, int $userId): array
    {
        $rows = $this->taskFinderModel->getExtendedQuery()
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.owner_id', $userId)
            ->findAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'project_id'     => (int) $r['project_id'],
                'owner_id'       => (int) $r['owner_id'],
                'time_spent'     => (float) $r['time_spent'],
                'date_completed' => (int) $r['date_completed'],
                'reference'      => (string) $r['reference'],
                'title'          => (string) $r['title'],
                'category_id'    => (int) $r['category_id'],
                'category'       => (string) ($r['category_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Completed-task detail set: tasks assigned to the user with date_completed in
     * range, each with its hours from the contribution union (0 if none), category, tags.
     */
    private function buildDetail(array $contributions, array $taskRows, int $startTs, int $endTs): array
    {
        $hoursByTask = [];
        foreach ($contributions as $c) {
            $hoursByTask[(int) $c['task_id']] = ($hoursByTask[(int) $c['task_id']] ?? 0.0) + (float) $c['hours'];
        }

        $completed = [];
        foreach ($taskRows as $t) {
            $completedTs = (int) $t['date_completed'];
            if ($completedTs < $startTs || $completedTs > $endTs) {
                continue;
            }
            $completed[(int) $t['id']] = $t;
        }

        $ids = array_keys($completed);
        $tagsByTask = empty($ids) ? [] : $this->taskTagModel->getTagsByTaskIds($ids);

        $detail = [];
        foreach ($completed as $id => $t) {
            $tagNames = array_map(static fn ($tag) => (string) $tag['name'], $tagsByTask[$id] ?? []);
            $detail[] = [
                'task_id'        => $id,
                'reference'      => (string) $t['reference'],
                'title'          => (string) $t['title'],
                'hours'          => (float) ($hoursByTask[$id] ?? 0.0),
                'date_completed' => date('Y-m-d', (int) $t['date_completed']),
                'category'       => (string) $t['category'],
                'tags'           => $tagNames,
            ];
        }
        // Sort by completion date then reference for stable output.
        usort($detail, static fn ($a, $b) => [$a['date_completed'], $a['reference']] <=> [$b['date_completed'], $b['reference']]);
        return $detail;
    }
}
