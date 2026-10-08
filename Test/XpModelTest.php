<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\XpModel;
use Kanboard\Plugin\TimeReport\Model\SubtaskCompletionStamp;

class XpModelTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
    }

    private function xp(): XpModel
    {
        return new XpModel($this->container);
    }

    public function testLevelCurveBoundaries(): void
    {
        $cases = [[0, 1, 100], [99, 1, 1], [100, 2, 200], [299, 2, 1], [1000, 5, 500], [4500, 10, 1000], [19000, 20, 2000]];
        foreach ($cases as [$xp, $level, $next]) {
            $this->assertSame($level, XpModel::levelFor($xp), "level for $xp");
            $this->assertSame($next, XpModel::nextFor($xp), "next for $xp");
        }
        $this->assertSame(0, XpModel::xpForLevel(1));
        $this->assertSame(19000, XpModel::xpForLevel(20));
    }

    public function testSubtaskAndTaskXpGoToTheirAssignees(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice, 'score' => 3]);
        $this->subtask($t, $bob, 2);
        $this->subtask($t, $bob, 2);
        $this->subtask($t, $alice, 0);

        $this->assertSame([], $this->xp()->deriveUser($alice));           // open task, todo subtask
        $this->assertSame([$p => 20], $this->xp()->deriveUser($bob));     // 2 × 10

        $this->close($t);   // core closeAll() also marks Alice's todo subtask done

        $this->assertSame([$p => 65], $this->xp()->deriveUser($alice));   // 25 + 10×3 + 10
        $this->assertSame([$p => 20], $this->xp()->deriveUser($bob));     // 2 × 10
        $this->assertSame([$alice => 65, $bob => 20], $this->xp()->byUser($p));
    }

    public function testUnassignedWorkEarnsNobody(): void
    {
        $p = $this->project();
        $t = $this->task($p);
        $this->subtask($t, 0, 2);
        $this->close($t);

        $this->assertSame([], $this->xp()->byUser($p));
        $this->assertSame([], $this->xp()->deriveUser(0));
    }

    public function testOpenTasksEarnNoTaskXp(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $this->task($p, ['owner_id' => $alice, 'score' => 5]);

        $this->assertSame([], $this->xp()->deriveUser($alice));
    }

    public function testRangeUsesStampsAndCompletionDates(): void
    {
        $alice = $this->user('alice');
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => $alice]);
        $inRange = $this->subtask($t, $alice, 2);
        $outRange = $this->subtask($t, $alice, 2);
        $stamp = new SubtaskCompletionStamp($this->container);
        $stamp->sync($inRange, 2, $alice, 1500);
        $stamp->sync($outRange, 2, $alice, 9000);
        $this->close($t);
        $this->container['db']->table('tasks')->eq('id', $t)->update(['date_completed' => 1600]);

        $this->assertSame([$p => 35], $this->xp()->deriveUser($alice, 1000, 2000));   // 10 + 25
        $this->assertSame([$alice => 35], $this->xp()->byUser($p, 1000, 2000));
        $this->assertSame([$p => 45], $this->xp()->deriveUser($alice));                // lifetime: 20 + 25
    }

    public function testStampKeepsFirstCompletionAndDeletesOnReopen(): void
    {
        $t = $this->task($this->project());
        $s = $this->subtask($t, 1, 2);
        $stamp = new SubtaskCompletionStamp($this->container);

        $stamp->sync($s, 2, 1, 100);
        $stamp->sync($s, 2, 2, 200);
        $row = $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->findOne();
        $this->assertSame(100, (int) $row['completed_at']);
        $this->assertSame(2, (int) $row['user_id']);

        $stamp->sync($s, 1, 2, 300);
        $this->assertSame(0, $this->container['db']->table('timereport_subtask_completions')->eq('subtask_id', $s)->count());
    }

    public function testAgentOwnerMapIsEmptyWithoutAgentsTable(): void
    {
        $this->assertSame([], $this->xp()->agentOwnerMap());
    }

    public function testAgentOwnerMapReadsTheAgentsTable(): void
    {
        $owner = $this->user('owner');
        $bot = $this->user('owner.claude');
        $this->agentsTable([$bot => $owner]);

        $this->assertSame([$bot => $owner], $this->xp()->agentOwnerMap());
    }
}
