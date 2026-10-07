<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;

class ProgressSchemaTest extends Base
{
    use ProgressFixture;

    private function stamp(int $subtaskId): ?array
    {
        $row = $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $subtaskId)->findOne();
        return $row ?: null;
    }

    public function testVersionIsTwo(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $this->assertSame(2, \Kanboard\Plugin\TimeReport\Schema\VERSION);
    }

    public function testVersion2CreatesBothTables(): void
    {
        $this->createProgressSchema();
        $db = $this->container['db'];
        $s = $this->subtask($this->task($this->project()), 1, 0);
        $this->assertNotFalse($db->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => 5]));
        $this->assertSame(0, $db->table('timereport_xp_cache')->count());
        $db->table('timereport_xp_cache')->insert(['user_id' => 1, 'project_id' => 0, 'xp' => 10, 'party_xp' => null, 'computed_at' => 1]);
        $this->assertSame(1, $db->table('timereport_xp_cache')->count());
    }

    public function testBackfillPrefersLastTimerEnd(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $p = $this->project();
        $t = $this->task($p);
        $s = $this->subtask($t, 1, 2);
        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $s, 'start' => 1000, 'end' => 4000, 'time_spent' => 0.8]);
        $this->container['db']->table('subtask_time_tracking')->insert(['user_id' => 1, 'subtask_id' => $s, 'start' => 5000, 'end' => 9000, 'time_spent' => 1.1]);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertSame(9000, (int) $this->stamp($s)['completed_at']);
        $this->assertSame(1, (int) $this->stamp($s)['user_id']);
    }

    public function testBackfillFallsBackToTaskCompletionThenModification(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $p = $this->project();
        $closed = $this->task($p);
        $open = $this->task($p);
        $sClosed = $this->subtask($closed, 1, 2);
        $sOpen = $this->subtask($open, 1, 2);
        $this->container['db']->table('tasks')->eq('id', $closed)->update(['date_completed' => 7000, 'date_modification' => 7100]);
        $this->container['db']->table('tasks')->eq('id', $open)->update(['date_completed' => null, 'date_modification' => 6100]);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertSame(7000, (int) $this->stamp($sClosed)['completed_at']);
        $this->assertSame(6100, (int) $this->stamp($sOpen)['completed_at']);
    }

    public function testBackfillSkipsSubtasksThatAreNotDone(): void
    {
        require_once dirname(__DIR__) . '/Schema/Sqlite.php';
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\TimeReport\Schema\version_1($pdo);

        $t = $this->task($this->project());
        $todo = $this->subtask($t, 1, 0);
        $doing = $this->subtask($t, 1, 1);

        \Kanboard\Plugin\TimeReport\Schema\version_2($pdo);

        $this->assertNull($this->stamp($todo));
        $this->assertNull($this->stamp($doing));
    }

    public function testStampCascadesWithSubtask(): void
    {
        $this->createProgressSchema();
        $t = $this->task($this->project());
        $s = $this->subtask($t, 1, 0);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => 5]);

        $this->container['subtaskModel']->remove($s);

        $this->assertNull($this->stamp($s));
    }
}
