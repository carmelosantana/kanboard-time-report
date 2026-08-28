<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

class TimeReportModelTest extends Base
{
    // Range: 2026-03-01 00:00:00 .. 2026-03-31 23:59:59
    private int $startTs;
    private int $endTs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startTs = strtotime('2026-03-01 00:00:00');
        $this->endTs   = strtotime('2026-03-31 23:59:59');
    }

    private function ts(string $d): int { return strtotime($d); }

    public function testSubtaskEntryInRangeCountsFromSubtaskSourceOnly(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        // Task 10 also has task-level time_spent + completed in range — must be IGNORED (deduped).
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 8.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertTrue($subtaskTaskIds[10]);
        $this->assertCount(1, $contribs);
        $this->assertSame(10, $contribs[0]['task_id']);
        $this->assertSame(2.0, $contribs[0]['hours']);
        $this->assertSame('2026-03-10', $contribs[0]['date']);
    }

    public function testTaskLevelFallbackCountsWhenNoSubtaskTime(): void
    {
        $taskRows = [
            ['id' => 20, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 4.5, 'date_completed' => $this->ts('2026-03-15 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertCount(1, $contribs);
        $this->assertSame(20, $contribs[0]['task_id']);
        $this->assertSame(4.5, $contribs[0]['hours']);
        $this->assertSame('2026-03-15', $contribs[0]['date']);
    }

    public function testTaskCompletedOutsideRangeExcluded(): void
    {
        $taskRows = [
            ['id' => 30, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 3.0, 'date_completed' => $this->ts('2026-04-02 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1]);
        $this->assertSame([], $contribs);
    }

    public function testSubtaskEntryOutsideRangeDoesNotMarkDedup(): void
    {
        // Out-of-range subtask entry for task 40 must NOT suppress the task-level fallback.
        $subtaskRows = [
            ['task_id' => 40, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-02-20 09:00:00'), 'end' => $this->ts('2026-02-20 10:00:00'), 'time_spent' => 1.0],
        ];
        $taskRows = [
            ['id' => 40, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 6.0, 'date_completed' => $this->ts('2026-03-20 12:00:00')],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertArrayNotHasKey(40, $subtaskTaskIds);
        $this->assertCount(1, $contribs);
        $this->assertSame(6.0, $contribs[0]['hours']); // task-level, dated by completion
        $this->assertSame('2026-03-20', $contribs[0]['date']);
    }

    public function testWrongProjectAndWrongUserExcluded(): void
    {
        $subtaskRows = [
            ['task_id' => 50, 'project_id' => 99, 'user_id' => 1, 'start' => $this->ts('2026-03-05 09:00:00'), 'end' => 0, 'time_spent' => 2.0],
            ['task_id' => 51, 'project_id' => 5,  'user_id' => 2, 'start' => $this->ts('2026-03-05 09:00:00'), 'end' => 0, 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 52, 'project_id' => 99, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
            ['id' => 53, 'project_id' => 5,  'owner_id' => 2, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
        ];
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);
        $this->assertSame([], $contribs);
    }

    public function testSubtaskHoursFromEndMinusStartWhenTimeSpentZero(): void
    {
        $subtaskRows = [
            ['task_id' => 60, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => $this->ts('2026-03-08 12:30:00'), 'time_spent' => 0.0],
        ];
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1]);
        $this->assertSame(1, count($contribs));
        $this->assertEqualsWithDelta(3.5, $contribs[0]['hours'], 0.0001);
    }

    public function testZeroDurationSubtaskEntryIsIgnored(): void
    {
        // An instant or still-running timer (end == start, time_spent 0) logs no hours.
        // It must NOT mark the task for dedup and must add no contribution — otherwise it
        // would hide the task's real task-level time_spent (the bug hit in live testing).
        $subtaskRows = [
            ['task_id' => 61, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => 0, 'time_spent' => 0.0],
        ];
        // Same task ALSO has real task-level time_spent, completed in range, owned by the user.
        $taskRows = [
            ['id' => 61, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 7.17, 'date_completed' => $this->ts('2026-03-20 17:00:00')],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertArrayNotHasKey(61, $subtaskTaskIds, 'a zero-hour subtask entry must not mark the task for dedup');
        $this->assertCount(1, $contribs, 'only the task-level fallback should contribute');
        $this->assertSame(61, $contribs[0]['task_id']);
        $this->assertSame(7.17, $contribs[0]['hours'], 'task-level time_spent must count when only zero-hour subtask entries exist');
        $this->assertSame('2026-03-20', $contribs[0]['date']);
    }

    public function testInstantTimerToggleBelowPrecisionIsIgnored(): void
    {
        // Real-world case: a timer started and stopped ~1 second later. end-start = 1s =
        // 0.000277h, which is > 0 but rounds to 0.00 at the report's 2-dp precision. Two such
        // toggles must NOT mark the task or hide its real 7.17h task-level time_spent.
        $t = $this->ts('2026-03-08 09:00:00');
        $subtaskRows = [
            ['task_id' => 63, 'project_id' => 5, 'user_id' => 1, 'start' => $t,     'end' => $t + 1, 'time_spent' => 0.0],
            ['task_id' => 63, 'project_id' => 5, 'user_id' => 1, 'start' => $t + 1, 'end' => $t + 2, 'time_spent' => 0.0],
        ];
        $taskRows = [
            ['id' => 63, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 7.17, 'date_completed' => $this->ts('2026-03-20 17:00:00')],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertArrayNotHasKey(63, $subtaskTaskIds, 'sub-precision timer toggles must not mark the task for dedup');
        $this->assertCount(1, $contribs);
        $this->assertSame(7.17, $contribs[0]['hours'], 'the real task-level time must survive instant timer toggles');
    }

    public function testGenuineShortSubtaskEntryStillCounts(): void
    {
        // A real entry at/above the report's precision (0.25h = 15 min) must still count and
        // still dedup the task-level fallback — the noise filter must not swallow real work.
        $t = $this->ts('2026-03-08 09:00:00');
        $subtaskRows = [
            ['task_id' => 70, 'project_id' => 5, 'user_id' => 1, 'start' => $t, 'end' => $t + 900, 'time_spent' => 0.0], // 15 min
        ];
        $taskRows = [
            ['id' => 70, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 9.0, 'date_completed' => $this->ts('2026-03-20 17:00:00')],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertTrue($subtaskTaskIds[70], 'a real 15-minute entry counts as subtask time and dedups the fallback');
        $this->assertCount(1, $contribs);
        $this->assertEqualsWithDelta(0.25, $contribs[0]['hours'], 0.0001, 'the 15-minute subtask entry (not the 9.0 task-level) is what counts');
    }

    // ── Bucketing ────────────────────────────────────────────────────────────

    private function contrib(int $taskId, float $hours, string $date): array
    {
        return ['task_id' => $taskId, 'hours' => $hours, 'date' => $date];
    }

    public function testBucketByDaySumsPerCalendarDay(): void
    {
        $c = [
            $this->contrib(1, 2.0, '2026-03-10'),
            $this->contrib(2, 1.5, '2026-03-10'),
            $this->contrib(1, 3.0, '2026-03-11'),
        ];
        $out = TimeReportModel::bucket($c, 'day');
        $this->assertEqualsWithDelta(6.5, $out['total_hours'], 0.0001);
        $this->assertCount(2, $out['breakdown']);
        $this->assertSame('2026-03-10', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(3.5, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']); // tasks 1 and 2
        $this->assertSame('2026-03-11', $out['breakdown'][1]['key']);
        $this->assertSame(1, $out['breakdown'][1]['task_count']);
    }

    public function testBucketByTotalSingleRow(): void
    {
        $c = [$this->contrib(1, 2.0, '2026-03-10'), $this->contrib(1, 3.0, '2026-03-11'), $this->contrib(2, 1.0, '2026-03-12')];
        $out = TimeReportModel::bucket($c, 'total');
        $this->assertCount(1, $out['breakdown']);
        $this->assertSame('total', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(6.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']);
    }

    public function testBucketByTaskOneRowPerTaskWithLabel(): void
    {
        $c = [$this->contrib(7, 2.0, '2026-03-10'), $this->contrib(7, 1.0, '2026-03-11'), $this->contrib(9, 4.0, '2026-03-12')];
        $meta = [7 => ['reference' => 'ABC-7', 'title' => 'Build API'], 9 => ['reference' => 'ABC-9', 'title' => 'Write docs']];
        $out = TimeReportModel::bucket($c, 'task', $meta);
        $this->assertCount(2, $out['breakdown']);
        // sorted by reference: ABC-7 before ABC-9
        $this->assertSame('7', $out['breakdown'][0]['key']);
        $this->assertStringContainsString('Build API', $out['breakdown'][0]['label']);
        $this->assertEqualsWithDelta(3.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(1, $out['breakdown'][0]['task_count']);
    }

    public function testBucketByTaskFallsBackToHashIdLabelWithoutMeta(): void
    {
        $c = [$this->contrib(7, 2.0, '2026-03-10')];
        $out = TimeReportModel::bucket($c, 'task');
        $this->assertSame('#7', $out['breakdown'][0]['label']);
    }

    /** ISO-week boundary: 2025-12-29 (Mon) .. 2026-01-04 (Sun) is ISO week 2026-W01. */
    public function testBucketByWeekIsoBoundary(): void
    {
        $c = [
            $this->contrib(1, 2.0, '2025-12-29'), // Mon of ISO 2026-W01
            $this->contrib(2, 1.0, '2026-01-04'), // Sun of ISO 2026-W01
            $this->contrib(3, 5.0, '2026-01-05'), // Mon of ISO 2026-W02
        ];
        $out = TimeReportModel::bucket($c, 'week');
        $this->assertCount(2, $out['breakdown']);
        $this->assertSame('2026-W01', $out['breakdown'][0]['key']);
        $this->assertEqualsWithDelta(3.0, $out['breakdown'][0]['hours'], 0.0001);
        $this->assertSame(2, $out['breakdown'][0]['task_count']);
        $this->assertSame('2026-W02', $out['breakdown'][1]['key']);
        $this->assertStringContainsString('Dec 29', $out['breakdown'][0]['label']);
        $this->assertStringContainsString('Jan 04', $out['breakdown'][0]['label']);
    }

    public function testWeekKeyHelperOnIsoBoundary(): void
    {
        $this->assertSame('2026-W01', TimeReportModel::weekKey(strtotime('2025-12-29 08:00:00')));
        $this->assertSame('2026-W01', TimeReportModel::weekKey(strtotime('2026-01-04 20:00:00')));
        $this->assertSame('2026-W02', TimeReportModel::weekKey(strtotime('2026-01-05 00:00:00')));
    }

    // ── Access guard ─────────────────────────────────────────────────────────

    public function testAssertProjectAccessThrowsForInaccessibleProject(): void
    {
        $model = new TimeReportModel($this->container);
        // No projects created/assigned to user 1 → getActiveProjectIds is empty.
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $model->assertProjectAccess(4242, 1);
    }

    public function testAssertProjectAccessPassesForAccessibleProject(): void
    {
        // Create a project (creator becomes owner/member → appears in getActiveProjectIds).
        $projectId = $this->container['projectModel']->create(['name' => 'Acme'], 1, true);
        $model = new TimeReportModel($this->container);
        $model->assertProjectAccess((int) $projectId, 1);
        $this->assertTrue(true); // no exception
    }

    public function testReportRefusesInaccessibleProject(): void
    {
        $model = new TimeReportModel($this->container);
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $model->report(4242, '2026-03-01', '2026-03-31', 'day', false, 1);
    }

    // ── Untracked subtask time (difference-based) ────────────────────────────

    public function testUntrackedFullyManualSubtaskFlaggedWithFullAmount(): void
    {
        $records = [['subtask_id' => 1, 'task_id' => 63, 'time_spent' => 1.0]];
        $tracked = []; // nothing tracked
        $meta    = [63 => ['reference' => 'ABC-63', 'title' => 'hgello']];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, $meta);

        $this->assertSame(1, $u['task_count']);
        $this->assertSame(1, $u['subtask_count']);
        $this->assertSame(1.0, $u['total_hours']);
        $this->assertSame(63, $u['tasks'][0]['task_id']);
        $this->assertSame('ABC-63', $u['tasks'][0]['reference']);
        $this->assertSame('hgello', $u['tasks'][0]['title']);
        $this->assertSame(1.0, $u['tasks'][0]['hours']);
    }

    public function testUntrackedPartialFlagsOnlyTheDifference(): void
    {
        // Recorded 1.5, tracked 0.5 → 1.0 untracked (the manual portion).
        $records = [['subtask_id' => 2, 'task_id' => 70, 'time_spent' => 1.5]];
        $tracked = [2 => 0.5];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, [70 => ['reference' => '', 'title' => 'Task 70']]);

        $this->assertSame(1, $u['subtask_count']);
        $this->assertSame(1.0, $u['total_hours']);
        $this->assertSame(1.0, $u['tasks'][0]['hours']);
    }

    public function testUntrackedFullyTrackedNotFlagged(): void
    {
        $records = [['subtask_id' => 3, 'task_id' => 80, 'time_spent' => 2.0]];
        $tracked = [3 => 2.0];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, []);
        $this->assertSame(0, $u['task_count']);
        $this->assertSame(0, $u['subtask_count']);
        $this->assertSame(0.0, $u['total_hours']);
        $this->assertSame([], $u['tasks']);
    }

    public function testUntrackedSubPrecisionDifferenceNotFlagged(): void
    {
        // 1.50 recorded, 1.499 tracked → 0.001 → rounds to 0.00 → not flagged.
        $records = [['subtask_id' => 4, 'task_id' => 81, 'time_spent' => 1.50]];
        $tracked = [4 => 1.499];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, []);
        $this->assertSame(0, $u['subtask_count']);
    }

    public function testUntrackedGroupsMultipleSubtasksPerTaskAndSorts(): void
    {
        $records = [
            ['subtask_id' => 10, 'task_id' => 90, 'time_spent' => 1.0],  // untracked 1.0
            ['subtask_id' => 11, 'task_id' => 90, 'time_spent' => 2.0],  // untracked 2.0
            ['subtask_id' => 12, 'task_id' => 85, 'time_spent' => 0.5],  // untracked 0.5
        ];
        $tracked = [];
        $meta = [90 => ['reference' => 'REF-90', 'title' => 'Ninety'], 85 => ['reference' => 'REF-85', 'title' => 'Eighty-five']];
        $u = TimeReportModel::findUntrackedSubtaskTime($records, $tracked, $meta);

        $this->assertSame(2, $u['task_count']);
        $this->assertSame(3, $u['subtask_count']);
        $this->assertSame(3.5, $u['total_hours']);
        // sorted by reference: REF-85 before REF-90
        $this->assertSame(85, $u['tasks'][0]['task_id']);
        $this->assertSame(0.5, $u['tasks'][0]['hours']);
        $this->assertSame(90, $u['tasks'][1]['task_id']);
        $this->assertSame(3.0, $u['tasks'][1]['hours']); // 1.0 + 2.0
    }

    public function testUntrackedEmptyInput(): void
    {
        $u = TimeReportModel::findUntrackedSubtaskTime([], [], []);
        $this->assertSame(['task_count' => 0, 'subtask_count' => 0, 'total_hours' => 0.0, 'tasks' => []], $u);
    }

    // ── report() wires the untracked aggregate ─────────────────────────────

    public function testReportAttachesUntrackedAggregateFromRealData(): void
    {
        // Project the user (1) can access.
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Untracked Demo'], 1, true);

        // A task assigned to the user, and a subtask assigned to the user.
        $taskId = (int) $this->container['taskCreationModel']->create([
            'project_id' => $projectId, 'title' => 'Has manual subtask time', 'owner_id' => 1,
        ]);
        $subId = (int) $this->container['subtaskModel']->create([
            'task_id' => $taskId, 'title' => 'typed in', 'user_id' => 1,
        ]);

        // Apply the recorded values LAST via direct DB writes: creating the subtask fires
        // Kanboard's updateTaskTimeTracking(), which recalculates tasks.time_spent from the
        // (then-zero) subtask — so set both AFTER the subtask exists, and there are no
        // tracking rows to recalc them again.
        $this->container['db']->table('subtasks')->eq('id', $subId)->update(['time_spent' => 1.25]);
        $this->container['db']->table('tasks')->eq('id', $taskId)->update([
            'owner_id' => 1, 'time_spent' => 3.0, 'is_active' => 0, 'date_completed' => strtotime('2026-03-10 12:00:00'),
        ]);

        $model = new TimeReportModel($this->container);
        $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'task', true, 1);

        $this->assertArrayHasKey('untracked', $report);
        $this->assertSame(1, $report['untracked']['task_count']);
        $this->assertSame(1, $report['untracked']['subtask_count']);
        $this->assertSame(1.25, $report['untracked']['total_hours']);
        $this->assertSame($taskId, $report['untracked']['tasks'][0]['task_id']);
        $this->assertSame(1.25, $report['untracked']['tasks'][0]['hours']);

        // Counted totals are unaffected by the untracked warning.
        $this->assertSame(3.0, $report['total_hours']);
    }

    public function testReportUntrackedEmptyWhenNothingManual(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Clean Demo'], 1, true);
        $model = new TimeReportModel($this->container);
        $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'day', false, 1);
        $this->assertSame(0, $report['untracked']['task_count']);
        $this->assertSame([], $report['untracked']['tasks']);
    }

    // --- sanitizeSubjectUserIds (pure) ---

    public function testNullRequestMeansSelfOnlyAndIsNotDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, false, 7, [7, 8, 9], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testWithoutPermissionRequestingOthersNarrowsToSelfAndFlagsDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7, 8], false, 7, [7, 8], false);
        $this->assertSame([7], $ids);
        $this->assertTrue($denied, 'asking for another user without permission must be visible');
    }

    /** The regression for the "scope=all silently narrows" hole. */
    public function testWithoutPermissionScopeAllIsDeniedNotSilentlySelfOnly(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, true, 7, [], false);
        $this->assertSame([7], $ids);
        $this->assertTrue($denied, 'asking for ALL users without permission must be flagged, not silently narrowed');
    }

    public function testWithoutPermissionRequestingOnlySelfIsNotDenied(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7], false, 7, [7], false);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testWithPermissionScopeAllResolvesToEveryParticipant(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds(null, true, 7, [9, 7, 8], true);
        $this->assertSame([7, 8, 9], $ids);
        $this->assertFalse($denied);
    }

    public function testWithPermissionRequestedSetIsHonoredAndSorted(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([9, 7], false, 7, [7, 8, 9], true);
        $this->assertSame([7, 9], $ids);
        $this->assertFalse($denied);
    }

    public function testNonParticipantIdsAreDropped(): void
    {
        // 42 never logged time here — must not be reported on, and must not error.
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([7, 42], false, 7, [7, 8], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testEmptyResolvedSetFallsBackToSelfNotEveryone(): void
    {
        [$ids, $denied] = TimeReportModel::sanitizeSubjectUserIds([42], false, 7, [7, 8], true);
        $this->assertSame([7], $ids);
        $this->assertFalse($denied);
    }

    public function testDuplicateAndStringIdsAreNormalized(): void
    {
        [$ids] = TimeReportModel::sanitizeSubjectUserIds(['8', 8, '7'], false, 7, [7, 8], true);
        $this->assertSame([7, 8], $ids);
    }

    // --- multi-user contributions ---

    public function testContributionsCarryUserAttribution(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1]);

        $this->assertSame(1, $contribs[0]['user_id']);
    }

    public function testTwoUsersBothContributeWhenBothSelected(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-11 09:00:00'), 'end' => $this->ts('2026-03-11 12:00:00'), 'time_spent' => 3.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(2, $contribs);
        $this->assertSame(5.0, array_sum(array_column($contribs, 'hours')));
    }

    public function testUnselectedUsersAreExcluded(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-11 09:00:00'), 'end' => $this->ts('2026-03-11 12:00:00'), 'time_spent' => 3.0],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, [1]);

        $this->assertCount(1, $contribs);
        $this->assertSame(1, $contribs[0]['user_id']);
    }

    /**
     * REGRESSION: an excluded user's tracked time must never resurface as the task
     * owner's task-level fallback. tasks.time_spent is SUM(subtasks.time_spent) over
     * ALL users, so suppression must ignore the user filter.
     */
    public function testExcludedLoggersTimeIsNotRebilledToTheTaskOwner(): void
    {
        // Bob (2) logged every hour. Alice (1) merely owns the task.
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertSame([], $contribs, "Alice logged nothing; Bob's hours must not be billed to her");
    }

    /**
     * REGRESSION: the same defect across a period boundary. tasks.time_spent is an
     * ALL-TIME pool, so tracked time outside the report range still disqualifies the
     * task-level fallback inside it.
     */
    public function testTrackedTimeOutsideTheRangeStillSuppressesTheFallback(): void
    {
        // Bob tracked in February; the Alice-owned task completed in March.
        $februaryRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-02-10 09:00:00'), 'end' => $this->ts('2026-02-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        $suppressed = TimeReportModel::suppressedTaskIdsFromRows($februaryRows);

        // The March query returns no tracking rows at all — suppression must come from
        // the all-time set, not from the in-range rows.
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1], $suppressed);

        $this->assertSame([], $contribs, "February's tracked time must still disqualify March's fallback");
    }

    public function testSuppressionIgnoresRowsThatRoundToZero(): void
    {
        // A two-second timer toggle is not work and must not suppress a real fallback.
        $rows = [
            ['task_id' => 10, 'start' => $this->ts('2026-02-10 09:00:00'), 'end' => $this->ts('2026-02-10 09:00:02'), 'time_spent' => 0.0],
        ];

        $this->assertSame([], TimeReportModel::suppressedTaskIdsFromRows($rows));
    }

    /** Deselecting a user must only ever REMOVE hours. */
    public function testNarrowingTheUserSetNeverIncreasesTheTotal(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 2.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$both] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);
        [$one]  = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1]);

        $this->assertLessThanOrEqual(
            array_sum(array_column($both, 'hours')),
            array_sum(array_column($one, 'hours'))
        );
    }

    /** Unassigned tasks (owner_id 0) carry no attributable time. */
    public function testUnassignedTaskFallbackIsNeverEmitted(): void
    {
        $taskRows = [
            ['id' => 77, 'project_id' => 5, 'owner_id' => 0, 'time_spent' => 9.0, 'date_completed' => $this->ts('2026-03-05 12:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [0, 1]);

        $this->assertSame([], $contribs, 'user 0 is "unassigned", not a person to bill');
    }

    public function testTaskLevelFallbackIsSuppressedByAnyUsersSubtaskTime(): void
    {
        $subtaskRows = [
            ['task_id' => 10, 'project_id' => 5, 'user_id' => 2, 'start' => $this->ts('2026-03-10 09:00:00'), 'end' => $this->ts('2026-03-10 11:00:00'), 'time_spent' => 2.0],
        ];
        $taskRows = [
            ['id' => 10, 'project_id' => 5, 'owner_id' => 1, 'time_spent' => 8.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(1, $contribs);
        $this->assertSame(2.0, $contribs[0]['hours']);
        $this->assertSame(2, $contribs[0]['user_id']);
    }

    /** With no tracked time at all, the task-level pool is attributed to the owner. */
    public function testTaskLevelFallbackIsAttributedToTheTaskOwner(): void
    {
        $taskRows = [
            ['id' => 11, 'project_id' => 5, 'owner_id' => 2, 'time_spent' => 4.0, 'date_completed' => $this->ts('2026-03-12 17:00:00')],
        ];

        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, [1, 2]);

        $this->assertCount(1, $contribs);
        $this->assertSame(2, $contribs[0]['user_id']);
        $this->assertSame(4.0, $contribs[0]['hours']);
    }
}
