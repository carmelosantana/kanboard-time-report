<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\ManualTimeLog;
use Kanboard\Plugin\TimeReport\Subscriber\ProgressSubscriber;

/**
 * The manual-time ledger: core keeps only a running total for time typed into a
 * subtask, so the plugin dates each manual change as it happens.
 */
class ManualTimeLogTest extends Base
{
    use ProgressFixture;

    private int $subtaskId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->subtaskId = $this->subtask($this->task($this->project()), 1);
    }

    private function setSpent(float $hours): void
    {
        $this->container['db']->table('subtasks')->eq('id', $this->subtaskId)->update(['time_spent' => $hours]);
    }

    /** @return list<array{hours:float,entered_at:int,user_id:int}> oldest first */
    private function rows(): array
    {
        $rows = $this->container['db']->table(ManualTimeLog::TABLE)->eq('subtask_id', $this->subtaskId)->asc('id')->findAll();
        return array_map(static fn ($r) => ['hours' => (float) $r['hours'], 'entered_at' => (int) $r['entered_at'], 'user_id' => (int) $r['user_id']], $rows);
    }

    private function log(): ManualTimeLog
    {
        return new ManualTimeLog($this->container);
    }

    public function testTypedTimeIsLoggedWithWhoAndWhen(): void
    {
        $this->setSpent(1.5);
        $this->log()->sync($this->subtaskId, 7, 5000);

        $this->assertSame([['hours' => 1.5, 'entered_at' => 5000, 'user_id' => 7]], $this->rows());
    }

    public function testSyncWithNoChangeLogsNothing(): void
    {
        $this->setSpent(1.5);
        $this->log()->sync($this->subtaskId, 7, 5000);
        $this->log()->sync($this->subtaskId, 7, 6000);

        $this->assertCount(1, $this->rows());
    }

    public function testALaterIncreaseLogsOnlyTheDifference(): void
    {
        $this->setSpent(1.5);
        $this->log()->sync($this->subtaskId, 7, 5000);
        $this->setSpent(2.25);
        $this->log()->sync($this->subtaskId, 7, 9000);

        $this->assertSame([1.5, 0.75], array_column($this->rows(), 'hours'));
        $this->assertSame([5000, 9000], array_column($this->rows(), 'entered_at'));
    }

    public function testTimerTrackedTimeIsNotManual(): void
    {
        // The timer wrote 2h of tracking and added it to the subtask total.
        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $this->subtaskId, 'start' => 1000, 'end' => 8200, 'time_spent' => 2.0]);
        $this->setSpent(2.5);
        $this->log()->sync($this->subtaskId, 7, 9000);

        $this->assertSame([0.5], array_column($this->rows(), 'hours'));
    }

    public function testAReductionComesOffTheNewestEntryFirst(): void
    {
        $this->setSpent(1.0);
        $this->log()->sync($this->subtaskId, 7, 5000);
        $this->setSpent(11.0);                       // typo: meant 2
        $this->log()->sync($this->subtaskId, 7, 9000);
        $this->setSpent(2.0);
        $this->log()->sync($this->subtaskId, 7, 9500);

        $this->assertSame([1.0, 1.0], array_column($this->rows(), 'hours'));
        $this->assertSame([5000, 9000], array_column($this->rows(), 'entered_at'));
    }

    public function testClearingTheTimeRemovesEveryEntry(): void
    {
        $this->setSpent(1.0);
        $this->log()->sync($this->subtaskId, 7, 5000);
        $this->setSpent(3.0);
        $this->log()->sync($this->subtaskId, 7, 9000);
        $this->setSpent(0.0);
        $this->log()->sync($this->subtaskId, 7, 9500);

        $this->assertSame([], $this->rows());
    }

    public function testEditingASubtaskThroughCoreLogsTheManualTime(): void
    {
        $this->container['dispatcher']->addSubscriber(new ProgressSubscriber($this->container));

        $taskId = (int) $this->container['subtaskModel']->getById($this->subtaskId)['task_id'];
        $this->container['subtaskModel']->update(['id' => $this->subtaskId, 'task_id' => $taskId, 'time_spent' => 1.25]);

        $rows = $this->rows();
        $this->assertSame([1.25], array_column($rows, 'hours'));
        $this->assertGreaterThan(time() - 60, $rows[0]['entered_at']);
    }

    public function testMigrationRecordsExistingManualTimeAsUndated(): void
    {
        $pdo = $this->container['db']->getConnection();
        $pdo->exec('DROP TABLE ' . ManualTimeLog::TABLE);

        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $this->subtaskId, 'start' => 1000, 'end' => 4600, 'time_spent' => 1.0]);
        $this->setSpent(3.0);
        $clean = $this->subtask($this->task($this->project()), 1);

        \Kanboard\Plugin\TimeReport\Schema\version_3($pdo);

        $this->assertSame([['hours' => 2.0, 'entered_at' => 0, 'user_id' => 1]], $this->rows());
        $this->assertSame(0, $this->container['db']->table(ManualTimeLog::TABLE)->eq('subtask_id', $clean)->count());

        // A later edit dates only the new hours; the old 2h stay undated.
        $this->setSpent(3.5);
        $this->log()->sync($this->subtaskId, 1, 9000);
        $this->assertSame([2.0, 0.5], array_column($this->rows(), 'hours'));
    }
}
