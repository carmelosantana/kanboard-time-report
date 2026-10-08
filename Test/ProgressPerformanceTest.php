<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\ProgressHelper;
use Kanboard\Plugin\TimeReport\Model\XpCache;

/** Spec Kanboard #5383: ≤ 3 extra queries per page, independent of the number of cards. */
class ProgressPerformanceTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReportProgress', ProgressHelper::class);
        $this->container['xpCache'] = fn ($c) => new XpCache($c);
    }

    /** Bulk rows straight into the tables: no events, fast enough for 500 cards. */
    private function bulkProject(int $cards): int
    {
        $p = $this->project('Big' . $cards);
        $db = $this->container['db'];
        $column = (int) $db->table('columns')->eq('project_id', $p)->asc('position')->findOneColumn('id');
        $swimlane = (int) $db->table('swimlanes')->eq('project_id', $p)->findOneColumn('id');
        for ($i = 0; $i < $cards; $i++) {
            $db->table('tasks')->insert(['title' => "Card $i", 'project_id' => $p, 'column_id' => $column, 'swimlane_id' => $swimlane,
                'position' => $i + 1, 'is_active' => 1, 'time_estimated' => 4, 'time_spent' => $i % 6, 'date_creation' => time()]);
            $taskId = (int) $db->getLastId();
            $db->table('subtasks')->insert(['task_id' => $taskId, 'title' => 's1', 'status' => 2, 'user_id' => 1]);
            $db->table('subtasks')->insert(['task_id' => $taskId, 'title' => 's2', 'status' => 0, 'user_id' => 1]);
        }
        return $p;
    }

    /** Queries the progress surfaces add on top of core's board query, for one page. */
    private function extraQueries(int $projectId): int
    {
        $rows = $this->container['taskFinderModel']->getExtendedQuery()->eq('tasks.project_id', $projectId)->findAll();
        $tpl = $this->container['template'];
        $h = $this->container['db']->getStatementHandler();

        $before = $h->getNbQueries();
        foreach ($rows as $row) {
            $tpl->render('TimeReport:board/progress', ['task' => $row]);
        }
        $tpl->render('TimeReport:project/track', ['project' => ['id' => $projectId]]);
        $tpl->render('TimeReport:layout/level');
        return $h->getNbQueries() - $before;
    }

    public function testBudgetIsAtMostThreeAndFlatInCardCount(): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
        $small = $this->bulkProject(50);
        $large = $this->bulkProject(500);
        $this->container['xpCache']->lifetime(1);   // warm the badge cache, as any earlier page would

        $smallCost = $this->extraQueries($small);
        $largeCost = $this->extraQueries($large);

        $this->assertLessThanOrEqual(3, $largeCost);
        $this->assertSame($smallCost, $largeCost, 'cost must not grow with the number of cards');
    }

    public function testCardMetersCostZeroQueries(): void
    {
        $p = $this->bulkProject(200);
        $rows = $this->container['taskFinderModel']->getExtendedQuery()->eq('tasks.project_id', $p)->findAll();
        $h = $this->container['db']->getStatementHandler();

        $before = $h->getNbQueries();
        foreach ($rows as $row) {
            $this->container['template']->render('TimeReport:board/progress', ['task' => $row]);
        }
        $this->assertSame(0, $h->getNbQueries() - $before);
        $this->assertCount(200, $rows);
    }
}
