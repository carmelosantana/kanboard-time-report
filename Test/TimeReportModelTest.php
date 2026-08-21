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

    public function testRunningSubtaskTimerContributesZeroHours(): void
    {
        $subtaskRows = [
            ['task_id' => 61, 'project_id' => 5, 'user_id' => 1, 'start' => $this->ts('2026-03-08 09:00:00'), 'end' => 0, 'time_spent' => 0.0],
        ];
        [$contribs, $subtaskTaskIds] = TimeReportModel::buildContributions($subtaskRows, [], $this->startTs, $this->endTs, 5, 1);
        $this->assertTrue($subtaskTaskIds[61]); // still marks the task as having an in-range entry
        $this->assertSame(0.0, $contribs[0]['hours']);
    }
}
