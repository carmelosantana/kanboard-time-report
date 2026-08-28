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
     * Task ids disqualified from the task-level fallback, from lean tracking rows.
     *
     * Fed by an UNRANGED query: tasks.time_spent is an all-time pool, so tracked time
     * from any period and any user disqualifies the fallback in every report window.
     * Applies the same round-to-2dp rule as the contribution math, so a two-second
     * timer toggle neither counts as work nor hides a real fallback.
     *
     * @param  list<array{task_id:int,start:int,end:int,time_spent:float}> $rows
     * @return array<int,bool>
     */
    public static function suppressedTaskIdsFromRows(array $rows): array
    {
        $suppressed = [];
        foreach ($rows as $row) {
            $timeSpent = (float) $row['time_spent'];
            if ($timeSpent > 0) {
                $hours = $timeSpent;
            } else {
                $start = (int) $row['start'];
                $end   = (int) $row['end'];
                $hours = $end > $start ? ($end - $start) / 3600 : 0.0;
            }
            if (round($hours, 2) > 0) {
                $suppressed[(int) $row['task_id']] = true;
            }
        }

        return $suppressed;
    }

    /**
     * Build the deduped flat contribution list for the SELECTED users.
     *
     * $subtaskRows and $taskRows are PROJECT-WIDE (every user). $userIds governs only
     * which contributions are emitted — never which rows are seen. That separation is
     * load-bearing: tasks.time_spent is SUM(subtasks.time_spent) across all users and
     * all time, so building suppression from the selected users' in-range rows alone
     * lets an excluded user's time resurface as the owner's fallback — which would make
     * narrowing the set INCREASE the total.
     *
     * $suppressedTaskIds supplies all-time eligibility from an unranged query; rows
     * seen in range are merged into it. Pass it whenever the report window could
     * exclude tracking that still disqualifies a fallback.
     *
     * @return array{0: list<array{task_id:int,hours:float,date:string,user_id:int}>, 1: array<int,bool>}
     */
    public static function buildContributions(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, array $userIds, array $suppressedTaskIds = []): array
    {
        $contributions = [];
        $suppressed    = $suppressedTaskIds;
        $selected      = array_flip(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0));

        foreach ($subtaskRows as $row) {
            if ((int) $row['project_id'] !== $projectId) {
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

            // An entry that rounds to 0.00h at the report's own precision represents no
            // logged work — a still-running timer, or an instant start/stop toggle.
            if (round($hours, 2) <= 0) {
                continue;
            }

            $taskId = (int) $row['task_id'];

            // Suppression deliberately precedes the user filter.
            $suppressed[$taskId] = true;

            if (! isset($selected[(int) $row['user_id']])) {
                continue;
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => (float) $hours,
                'date'    => date('Y-m-d', $start),
                'user_id' => (int) $row['user_id'],
            ];
        }

        foreach ($taskRows as $task) {
            $ownerId = (int) $task['owner_id'];
            // owner_id 0 means UNASSIGNED, not a person: its pool is unattributable.
            if ((int) $task['project_id'] !== $projectId || $ownerId <= 0 || ! isset($selected[$ownerId])) {
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
            if (isset($suppressed[$taskId])) {
                continue; // dedup: already represented by tracked subtask time
            }

            $contributions[] = [
                'task_id' => $taskId,
                'hours'   => $timeSpent,
                'date'    => date('Y-m-d', $completed),
                'user_id' => $ownerId,
            ];
        }

        return [$contributions, $suppressed];
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
     * @param  list<array{task_id:int,hours:float,date:string,user_id?:int}> $contributions
     * @param  array<int,array{reference:string,title:string}>  $taskMeta
     * @param  array<int,array{name:string}>                    $userMeta
     * @return array{total_hours:float, breakdown: list<array{key:string,label:string,hours:float,task_count:int}>}
     */
    public static function bucket(array $contributions, string $granularity, array $taskMeta = [], array $userMeta = []): array
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
                case 'user':
                    $uid   = (int) ($c['user_id'] ?? 0);
                    $key   = 'u' . $uid;
                    $label = (string) ($userMeta[$uid]['name'] ?? ('#' . $uid));
                    $sort  = $label;
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

    /** Every POSITIVE user id in the gathered rows, as a logger or a task owner. */
    public static function allUserIds(array $subtaskRows, array $taskRows): array
    {
        $ids = array_merge(
            array_map('intval', array_column($subtaskRows, 'user_id')),
            array_map('intval', array_column($taskRows, 'owner_id'))
        );

        // 0 is Kanboard's "unassigned" sentinel for both columns, not a person.
        return array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
    }

    /**
     * Sum contribution hours per user.
     *
     * @return array<int,float>
     */
    public static function hoursByUser(array $contributions): array
    {
        $byUser = [];
        foreach ($contributions as $c) {
            $uid = (int) ($c['user_id'] ?? 0);
            $byUser[$uid] = round(($byUser[$uid] ?? 0.0) + (float) $c['hours'], 2);
        }

        return $byUser;
    }

    /**
     * Display names for the given ids, preferring the full name and falling back to
     * the username, then to "#id" for a deleted user still referenced by old rows.
     *
     * @return array<int,string>
     */
    public function userNames(array $userIds): array
    {
        $names = [];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $user = $this->userModel->getById($uid);
            if (empty($user)) {
                continue;
            }
            $name = trim((string) ($user['name'] ?? ''));
            $names[$uid] = $name !== '' ? $name : (string) ($user['username'] ?? ('#' . $uid));
        }

        return $names;
    }

    /**
     * Every user with hours in $projectId over the range, with their totals.
     *
     * Public entry point for callers outside report(); report() computes the same thing
     * from rows it has already gathered, so a single request never runs discovery twice.
     *
     * @return array<int, array{name:string, hours:float}> Ordered by hours desc, then name.
     */
    public function participants(int $projectId, string $startDate, string $endDate, int $requestingUserId): array
    {
        $this->assertProjectAccess($projectId, $requestingUserId);

        $startTs = (int) strtotime($startDate . ' 00:00:00');
        $endTs   = (int) strtotime($endDate . ' 23:59:59');

        $subtaskRows = $this->gatherSubtaskRows($projectId, $startTs, $endTs);
        $taskRows    = $this->gatherRangeTaskRows($projectId, $startTs, $endTs);
        $suppressed  = self::suppressedTaskIdsFromRows($this->gatherSuppressionRows($projectId));

        $ids = $this->canReportOnOthers($projectId, $requestingUserId)
            ? self::allUserIds($subtaskRows, $taskRows)
            : [$requestingUserId];

        return $this->buildParticipants($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $ids, $suppressed);
    }

    /**
     * Group already-gathered rows into the participant panel shape.
     *
     * Reuses the SAME contribution union the report itself uses, so the panel's
     * per-user totals and the report's numbers agree by construction. A divergence
     * between the two would be a billing bug, not a cosmetic one.
     *
     * @return array<int, array{name:string, hours:float}>
     */
    private function buildParticipants(array $subtaskRows, array $taskRows, int $startTs, int $endTs, int $projectId, array $userIds, array $suppressedTaskIds): array
    {
        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, $userIds, $suppressedTaskIds);

        $hours = self::hoursByUser($contributions);
        $names = $this->userNames(array_keys($hours));

        $out = [];
        foreach ($hours as $uid => $h) {
            if ($uid <= 0) {
                continue;
            }
            $out[$uid] = ['name' => $names[$uid] ?? ('#' . $uid), 'hours' => (float) $h];
        }

        uasort($out, static fn ($a, $b) => [$b['hours'], $a['name']] <=> [$a['hours'], $b['name']]);

        return $out;
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

        // Source 1: project-wide subtask time rows in range (self-only filtering happens
        // in buildContributions via the [$userId] subject set below).
        $subtaskRows = $this->gatherSubtaskRows($projectId, $startTs, $endTs);
        // Source 2: tasks completed in range (every owner; the subject set scopes them).
        $taskRows = $this->gatherRangeTaskRows($projectId, $startTs, $endTs);

        [$contributions] = self::buildContributions($subtaskRows, $taskRows, $startTs, $endTs, $projectId, [$userId]);

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

    /**
     * In-range subtask time rows for the whole project, every user.
     *
     * Hand-rolled rather than SubtaskTimeTrackingModel::getUserQuery(), which is scoped
     * to one user, omits user_id, and applies no range at all. The range predicate is
     * in SQL because this query is project-wide.
     */
    private function gatherSubtaskRows(int $projectId, int $startTs, int $endTs): array
    {
        $table = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $rows = $this->db->table($table)
            ->columns(
                $table . '.user_id',
                $table . '.start',
                $table . '.end',
                $table . '.time_spent',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\TaskModel::TABLE . '.project_id'
            )
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $table)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->gte($table . '.start', $startTs)
            ->lte($table . '.start', $endTs)
            ->findAll();

        $normalized = [];
        foreach ($rows as $r) {
            $normalized[] = [
                'task_id'    => (int) $r['task_id'],
                'project_id' => (int) $r['project_id'],
                'user_id'    => (int) $r['user_id'],
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }

        return $normalized;
    }

    /**
     * Lean, UNRANGED tracking rows used only to decide task-level fallback eligibility.
     *
     * Deliberately not range-bounded: tasks.time_spent is an all-time pool, so tracked
     * time from any period disqualifies the fallback in every window. Four small
     * columns from one indexed table — this is the cheap query, not the expensive one.
     */
    private function gatherSuppressionRows(int $projectId): array
    {
        $table = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $rows = $this->db->table($table)
            ->columns($table . '.start', $table . '.end', $table . '.time_spent', \Kanboard\Model\SubtaskModel::TABLE . '.task_id')
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $table)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'task_id'    => (int) $r['task_id'],
                'start'      => (int) $r['start'],
                'end'        => (int) $r['end'],
                'time_spent' => (float) $r['time_spent'],
            ];
        }

        return $out;
    }

    /**
     * Inputs for findUntrackedSubtaskTime().
     *
     * $ownUserId null = project-wide (the requester may see others' time); otherwise
     * only that user's own subtasks. Scoped by PERMISSION, never by the subject set,
     * so the warning is selection-invariant.
     *
     * The tracked side is deliberately unfiltered by user and unranged: subtasks.
     * time_spent is a pool contributed to by every logger over all time, so offsetting
     * it with one user's tracked hours would invent untracked time that does not exist.
     *
     * @return array{0: list<array{subtask_id:int,task_id:int,time_spent:float}>, 1: array<int,float>, 2: array<int,array{reference:string,title:string}>}
     */
    private function gatherUntrackedInputs(int $projectId, ?int $ownUserId): array
    {
        $query = $this->db->table(\Kanboard\Model\SubtaskModel::TABLE)
            ->columns(
                \Kanboard\Model\SubtaskModel::TABLE . '.id',
                \Kanboard\Model\SubtaskModel::TABLE . '.task_id',
                \Kanboard\Model\SubtaskModel::TABLE . '.time_spent',
                \Kanboard\Model\TaskModel::TABLE . '.reference',
                \Kanboard\Model\TaskModel::TABLE . '.title'
            )
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id')
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->gt(\Kanboard\Model\SubtaskModel::TABLE . '.time_spent', 0);

        if ($ownUserId !== null) {
            $query->eq(\Kanboard\Model\SubtaskModel::TABLE . '.user_id', $ownUserId);
        }

        $rows = $query->findAll();

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

        // Tracked hours per subtask across ALL loggers and ALL time — see the docblock.
        $trackedBySubtask = [];
        $ttTable = \Kanboard\Model\SubtaskTimeTrackingModel::TABLE;

        $ttRows = $this->db->table($ttTable)
            ->columns($ttTable . '.subtask_id', $ttTable . '.start', $ttTable . '.end', $ttTable . '.time_spent')
            ->join(\Kanboard\Model\SubtaskModel::TABLE, 'id', 'subtask_id', $ttTable)
            ->join(\Kanboard\Model\TaskModel::TABLE, 'id', 'task_id', \Kanboard\Model\SubtaskModel::TABLE)
            ->eq(\Kanboard\Model\TaskModel::TABLE . '.project_id', $projectId)
            ->findAll();

        foreach ($ttRows as $tt) {
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

    /**
     * Tasks COMPLETED in range, every owner, as a lean projection.
     *
     * Replaces getExtendedQuery(), which carries seven correlated COUNT(*) subqueries
     * and ~35 columns for the nine fields this report needs. Owner-agnostic, because it
     * feeds both the task-level fallback and the completed-task detail, and a selected
     * user can have worked on a task somebody else owns.
     */
    private function gatherRangeTaskRows(int $projectId, int $startTs, int $endTs): array
    {
        $t = \Kanboard\Model\TaskModel::TABLE;

        $rows = $this->db->table($t)
            ->columns(
                $t . '.id', $t . '.project_id', $t . '.owner_id', $t . '.time_spent',
                $t . '.date_completed', $t . '.reference', $t . '.title', $t . '.category_id'
            )
            ->eq($t . '.project_id', $projectId)
            ->gte($t . '.date_completed', $startTs)
            ->lte($t . '.date_completed', $endTs)
            ->findAll();

        // Category names resolved from a small per-project map rather than a join, so
        // the task query stays a single-table projection.
        $categories = [];
        foreach ($this->categoryModel->getAll($projectId) as $c) {
            $categories[(int) $c['id']] = (string) $c['name'];
        }

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
                'category'       => $categories[(int) $r['category_id']] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Reference + title for specific task ids.
     *
     * Used for tasks a selected user contributed to that fall outside the completed-in-
     * range set, so their breakdown label is a real title instead of "#id".
     *
     * @return array<int,array{reference:string,title:string}>
     */
    private function gatherTaskMeta(int $projectId, array $taskIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $taskIds)));
        if ($ids === []) {
            return [];
        }

        $t = \Kanboard\Model\TaskModel::TABLE;

        $rows = $this->db->table($t)
            ->columns($t . '.id', $t . '.reference', $t . '.title')
            ->eq($t . '.project_id', $projectId)
            ->in($t . '.id', $ids)
            ->findAll();

        $meta = [];
        foreach ($rows as $r) {
            $meta[(int) $r['id']] = ['reference' => (string) $r['reference'], 'title' => (string) $r['title']];
        }

        return $meta;
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
