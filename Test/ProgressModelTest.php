<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Model\ProgressModel;

class ProgressModelTest extends Base
{
    use ProgressFixture;

    private function row(array $o): array
    {
        return $o + ['id' => 42, 'nb_subtasks' => 0, 'nb_completed_subtasks' => 0, 'time_spent' => 0, 'time_estimated' => 0];
    }

    public function testPct(): void
    {
        $this->assertSame(0, ProgressModel::pct(0, 0));
        $this->assertSame(60, ProgressModel::pct(3, 5));
        $this->assertSame(67, ProgressModel::pct(2, 3));
        $this->assertSame(100, ProgressModel::pct(4, 4));
    }

    public function testMetersWithSubtasksAndEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['nb_subtasks' => 5, 'nb_completed_subtasks' => 3, 'time_spent' => 3.4, 'time_estimated' => 4]));
        $this->assertSame(['task_id' => 42, 'subtasks_done' => 3, 'subtasks_total' => 5, 'pct' => 60,
            'time_spent' => 3.4, 'time_estimated' => 4.0, 'time_pct' => 85, 'time_state' => 'ok',
            'has_subtasks' => true, 'has_estimate' => true], $m);
    }

    public function testMetersOverrunCapsWidthAndFlagsOver(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 6, 'time_estimated' => 4]));
        $this->assertSame(100, $m['time_pct']);
        $this->assertSame('over', $m['time_state']);
    }

    public function testMetersWithoutSubtasksButWithEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 1, 'time_estimated' => 2]));
        $this->assertFalse($m['has_subtasks']);
        $this->assertTrue($m['has_estimate']);
        $this->assertSame(0, $m['pct']);
    }

    public function testMetersWithSubtasksButNoEstimate(): void
    {
        $m = ProgressModel::taskMeters($this->row(['nb_subtasks' => 2, 'nb_completed_subtasks' => 1, 'time_spent' => 3]));
        $this->assertTrue($m['has_subtasks']);
        $this->assertFalse($m['has_estimate']);
        $this->assertSame('none', $m['time_state']);
        $this->assertSame(0, $m['time_pct']);
    }

    public function testMetersWithNeitherAreInvisible(): void
    {
        $m = ProgressModel::taskMeters($this->row(['time_spent' => 2]));
        $this->assertFalse($m['has_subtasks']);
        $this->assertFalse($m['has_estimate']);
    }

    public function testTaskProgressReadsTheBoardRow(): void
    {
        $t = $this->task($this->project(), ['time_estimated' => 4]);
        $this->subtask($t, 1, 2);
        $this->subtask($t, 1, 0);

        $m = (new ProgressModel($this->container))->taskProgress($t);
        $this->assertSame(1, $m['subtasks_done']);
        $this->assertSame(2, $m['subtasks_total']);
        $this->assertSame(50, $m['pct']);
        $this->assertNull((new ProgressModel($this->container))->taskProgress(99999));
    }

    public function testProjectProgressCountsClosedOverAll(): void
    {
        $p = $this->project();
        $a = $this->task($p);
        $this->task($p);
        $this->task($p);
        $this->close($a);

        $r = (new ProgressModel($this->container))->projectProgress($p);
        $this->assertSame(['project_id' => $p, 'closed' => 1, 'total' => 3, 'pct' => 33, 'milestones' => []], $r);
    }

    public function testProjectProgressListsOpenMilestonesWithCounts(): void
    {
        $p = $this->project();
        $ms = $this->task($p, ['title' => 'Beta', 'date_due' => strtotime('2026-10-20 00:00:00')]);
        $m1 = $this->task($p);
        $m2 = $this->task($p);
        $this->milestone($ms, [$m1, $m2]);
        $this->close($m1);

        $r = (new ProgressModel($this->container))->projectProgress($p);
        $this->assertCount(1, $r['milestones']);
        $this->assertSame(['task_id' => $ms, 'title' => 'Beta', 'closed' => 1, 'total' => 2, 'pct' => 50, 'due' => '2026-10-20'], $r['milestones'][0]);
    }

    public function testClosedMilestonesAreNotOnTheTrack(): void
    {
        $p = $this->project();
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($p)]);
        $this->close($ms);

        $this->assertSame([], (new ProgressModel($this->container))->projectProgress($p)['milestones']);
    }

    public function testUndatedMilestonesSortLast(): void
    {
        $p = $this->project();
        $undated = $this->task($p, ['title' => 'Someday']);
        $late = $this->task($p, ['title' => 'Late', 'date_due' => strtotime('2026-12-01')]);
        $soon = $this->task($p, ['title' => 'Soon', 'date_due' => strtotime('2026-11-01')]);
        foreach ([$undated, $late, $soon] as $ms) {
            $this->milestone($ms, [$this->task($p)]);
        }

        $titles = array_column((new ProgressModel($this->container))->projectProgress($p)['milestones'], 'title');
        $this->assertSame(['Soon', 'Late', 'Someday'], $titles);
    }

    public function testMilestoneIgnoresCrossProjectMembers(): void
    {
        $p = $this->project('A');
        $other = $this->project('B');
        $ms = $this->task($p);
        $same = $this->task($p);
        $foreign = $this->task($other);
        $this->milestone($ms, [$same, $foreign]);

        $r = (new ProgressModel($this->container))->milestoneProgress($ms);
        $this->assertSame([$same], $r['members']);
        $this->assertSame(1, $r['total']);
    }

    public function testProjectProgressOmitsMilestoneWhoseOnlyMemberIsForeign(): void
    {
        $p = $this->project('A');
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($this->project('B'))]);

        $this->assertSame([], (new ProgressModel($this->container))->projectProgress($p)['milestones']);
        $this->assertNull((new ProgressModel($this->container))->milestoneProgress($ms));
    }

    public function testMilestoneProgressIsNullForPlainTasks(): void
    {
        $t = $this->task($this->project());
        $this->assertNull((new ProgressModel($this->container))->milestoneProgress($t));
        $this->assertNull((new ProgressModel($this->container))->milestoneProgress(99999));
    }

    public function testProjectProgressUsesTwoQueries(): void
    {
        $p = $this->project();
        $ms = $this->task($p);
        $this->milestone($ms, [$this->task($p), $this->task($p)]);

        $h = $this->container['db']->getStatementHandler();
        $before = $h->getNbQueries();
        (new ProgressModel($this->container))->projectProgress($p);
        $this->assertSame(2, $h->getNbQueries() - $before);
    }
}
