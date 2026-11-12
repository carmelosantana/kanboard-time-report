<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\ManualTimeLog;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/** Manual subtask time in the report: counted on the day it was entered, in range only. */
class ManualTimeReportTest extends Base
{
    use ProgressFixture;

    private int $projectId;
    private int $taskId;
    private int $subtaskId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->projectId = $this->project('Manual');
        $this->taskId    = $this->task($this->projectId, ['owner_id' => 1, 'title' => 'Build']);
        $this->subtaskId = $this->subtask($this->taskId, 1);
    }

    private function manual(float $hours, string $when, int $userId = 1, ?int $subtaskId = null): void
    {
        $sid = $subtaskId ?? $this->subtaskId;
        $this->container['db']->table(ManualTimeLog::TABLE)->insert([
            'subtask_id' => $sid, 'user_id' => $userId, 'hours' => $hours, 'entered_at' => $when === '' ? 0 : strtotime($when),
        ]);
        $current = (float) $this->container['db']->table('subtasks')->eq('id', $sid)->findOneColumn('time_spent');
        $this->container['db']->table('subtasks')->eq('id', $sid)->update(['time_spent' => $current + $hours]);
    }

    private function tracked(float $hours, string $start, int $userId = 1): void
    {
        $ts = strtotime($start);
        $this->container['db']->table('subtask_time_tracking')->insert([
            'user_id' => $userId, 'subtask_id' => $this->subtaskId, 'start' => $ts, 'end' => $ts + (int) ($hours * 3600), 'time_spent' => $hours,
        ]);
        $current = (float) $this->container['db']->table('subtasks')->eq('id', $this->subtaskId)->findOneColumn('time_spent');
        $this->container['db']->table('subtasks')->eq('id', $this->subtaskId)->update(['time_spent' => $current + $hours]);
    }

    private function report(string $granularity = 'day', string $start = '2026-03-01', string $end = '2026-03-31', bool $allUsers = false): array
    {
        return (new TimeReportModel($this->container))->report($this->projectId, $start, $end, $granularity, false, 1, null, $allUsers);
    }

    public function testBucketSplitsTrackedAndManualHours(): void
    {
        $out = TimeReportModel::bucket([
            ['task_id' => 1, 'hours' => 2.0, 'date' => '2026-03-10', 'user_id' => 1, 'source' => 'tracked'],
            ['task_id' => 1, 'hours' => 0.5, 'date' => '2026-03-10', 'user_id' => 1, 'source' => 'manual'],
            ['task_id' => 2, 'hours' => 1.0, 'date' => '2026-03-11', 'user_id' => 1, 'source' => 'manual'],
        ], 'day');

        $this->assertSame(2.0, $out['breakdown'][0]['tracked_hours']);
        $this->assertSame(0.5, $out['breakdown'][0]['manual_hours']);
        $this->assertSame(2.5, $out['breakdown'][0]['hours']);
        $this->assertSame(0.0, $out['breakdown'][1]['tracked_hours']);
        $this->assertSame(1.0, $out['breakdown'][1]['manual_hours']);
        $this->assertSame(2.0, $out['tracked_hours']);
        $this->assertSame(1.5, $out['manual_hours']);
    }

    public function testManualTimeCountsOnTheDayItWasEntered(): void
    {
        $this->tracked(2.0, '2026-03-10 09:00:00');
        $this->manual(0.5, '2026-03-10 17:00:00');
        $this->manual(1.0, '2026-03-12 10:00:00');

        $report = $this->report();

        $this->assertSame(['2026-03-10', '2026-03-12'], array_column($report['breakdown'], 'key'));
        $this->assertSame([2.0, 0.0], array_column($report['breakdown'], 'tracked_hours'));
        $this->assertSame([0.5, 1.0], array_column($report['breakdown'], 'manual_hours'));
        $this->assertSame([2.5, 1.0], array_column($report['breakdown'], 'hours'));
        $this->assertSame(3.5, $report['total_hours']);
        $this->assertSame(2.0, $report['tracked_hours']);
        $this->assertSame(1.5, $report['manual_hours']);
    }

    public function testManualTimeEnteredOutsideTheRangeIsNotShownAtAll(): void
    {
        $this->manual(4.0, '2026-02-20 10:00:00');
        $this->manual(1.0, '2026-03-12 10:00:00');

        $report = $this->report();

        $this->assertSame(1.0, $report['total_hours']);
        $this->assertSame(0, $report['untracked']['task_count'], 'dated manual time is not "untracked"');
    }

    public function testUndatedManualTimeIsNotCountedAndStaysInTheNotice(): void
    {
        $this->manual(2.0, '');
        $this->manual(1.0, '2026-03-12 10:00:00');

        $report = $this->report();

        $this->assertSame(1.0, $report['total_hours']);
        $this->assertSame(2.0, $report['untracked']['total_hours']);
        $this->assertSame(1, $report['untracked']['subtask_count']);
    }

    public function testCompletedTaskTotalDoesNotDoubleCountDatedManualTime(): void
    {
        $this->manual(1.0, '2026-03-12 10:00:00');
        // Core mirrors subtask time onto the task; the task is then completed in range.
        $this->container['db']->table('tasks')->eq('id', $this->taskId)->update([
            'time_spent' => 1.0, 'is_active' => 0, 'date_completed' => strtotime('2026-03-20 12:00:00'),
        ]);

        $this->assertSame(1.0, $this->report()['total_hours']);
    }

    public function testManualTimeDatedInAnotherPeriodStillBlocksTheCompletedTaskTotal(): void
    {
        $this->manual(1.0, '2026-02-12 10:00:00');
        $this->container['db']->table('tasks')->eq('id', $this->taskId)->update([
            'time_spent' => 1.0, 'is_active' => 0, 'date_completed' => strtotime('2026-03-20 12:00:00'),
        ]);

        $this->assertSame(0.0, $this->report()['total_hours']);
    }

    public function testManualTimeBelongsToWhoeverTypedIt(): void
    {
        $other = $this->user('other');
        $this->manual(1.0, '2026-03-12 10:00:00', 1);
        $this->manual(3.0, '2026-03-13 10:00:00', $other);

        $this->assertSame(1.0, $this->report()['total_hours'], 'just me');

        $all = $this->report('user', '2026-03-01', '2026-03-31', true);
        $this->assertSame(4.0, $all['total_hours']);
        $this->assertSame(3.0, $all['participants'][$other]['hours']);
    }

    public function testCompletedTaskTotalWithNoDatedTimeCountsAsManual(): void
    {
        $this->container['db']->table('tasks')->eq('id', $this->taskId)->update([
            'time_spent' => 2.0, 'is_active' => 0, 'date_completed' => strtotime('2026-03-20 12:00:00'),
        ]);

        $report = $this->report();

        $this->assertSame(2.0, $report['manual_hours']);
        $this->assertSame(0.0, $report['tracked_hours']);
    }
}
