<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

class ReportXpTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
    }

    private function report(int $projectId): array
    {
        return (new TimeReportModel($this->container))->report($projectId, date('Y-m-01'), date('Y-m-t'), 'task', false, 1);
    }

    public function testReportCarriesXpForTheSubjectUserInRange(): void
    {
        $p = $this->project();
        $t = $this->task($p, ['owner_id' => 1, 'score' => 2]);
        $s = $this->subtask($t, 1, 2);
        $this->container['db']->table('timereport_subtask_completions')->insert(['subtask_id' => $s, 'user_id' => 1, 'completed_at' => time()]);
        $this->close($t);

        $r = $this->report($p);
        $this->assertSame(['users' => [1 => 55], 'total' => 55], $r['xp']);   // 10 + 25 + 20
    }

    public function testReportXpIsZeroFilledWhenNothingWasEarned(): void
    {
        $this->assertSame(['users' => [1 => 0], 'total' => 0], $this->report($this->project())['xp']);
    }

    public function testMarkdownAndCsvShowXp(): void
    {
        $helper = new TimeReportHelper($this->container);
        $r = $this->report($this->project());
        $r['xp'] = ['users' => [1 => 55], 'total' => 55];

        $this->assertStringContainsString('**XP earned:** 55', $helper->toMarkdown($r));
        $this->assertStringContainsString("# XP earned,55", $helper->toCsv($r));
    }

    public function testMarkdownAndCsvToleratesReportsWithoutXp(): void
    {
        $helper = new TimeReportHelper($this->container);
        $r = $this->report($this->project());
        unset($r['xp']);

        $this->assertStringNotContainsString('XP earned', $helper->toMarkdown($r));
        $this->assertStringNotContainsString('XP earned', $helper->toCsv($r));
    }

    public function testShowTemplateRendersXp(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Template/report/show.php');
        $this->assertStringContainsString("\$report['xp']", $src);
        $this->assertStringContainsString("t('XP')", $src);
    }
}
