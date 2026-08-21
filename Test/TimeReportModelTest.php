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

        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);

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
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, 1);

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
        [$contribs] = TimeReportModel::buildContributions([], $taskRows, $this->startTs, $this->endTs, 5, 1);
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
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);

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
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);
        $this->assertSame([], $contribs);
    }

    public function testSubtaskHoursFromEndMinusStartWhenTimeSpentZero(): void
    {
        $subtaskRows = [
            ['task_id' => 60, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => $this->ts('2026-03-08 12:30:00'), 'time_spent' => 0.0],
        ];
        [$contribs] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, 1);
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
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, $taskRows, $this->startTs, $this->endTs, 5, 1);

        $this->assertArrayNotHasKey(61, $subtaskTaskIds, 'a zero-hour subtask entry must not mark the task for dedup');
        $this->assertCount(1, $contribs, 'only the task-level fallback should contribute');
        $this->assertSame(61, $contribs[0]['task_id']);
        $this->assertSame(7.17, $contribs[0]['hours'], 'task-level time_spent must count when only zero-hour subtask entries exist');
        $this->assertSame('2026-03-20', $contribs[0]['date']);
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
}
