<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\TimeReport\Controller\TimeReportController;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\ManualTimeLog;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/** Undated manual time: out of the way at the bottom, and datable from the report. */
class UndatedEditTest extends Base
{
    use ProgressFixture;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReport', TimeReportHelper::class);
        $this->container['timeReportModel'] = fn ($c) => new TimeReportModel($c);
        $this->projectId = $this->project('Old');
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
    }

    /** A task with one subtask carrying $hours of manual time; $when '' = undated. */
    private function manualTask(float $hours, string $when = '', int $userId = 1, ?int $projectId = null): int
    {
        $taskId = $this->task($projectId ?? $this->projectId, ['owner_id' => $userId, 'title' => 'Task ' . $hours]);
        $sid    = $this->subtask($taskId, $userId);
        $this->container['db']->table('subtasks')->eq('id', $sid)->update(['time_spent' => $hours]);
        $this->container['db']->table(ManualTimeLog::TABLE)->insert([
            'subtask_id' => $sid, 'user_id' => $userId, 'hours' => $hours, 'entered_at' => $when === '' ? 0 : strtotime($when),
        ]);

        return $taskId;
    }

    /** @return array<int,int> task id => entered_at of its ledger row */
    private function enteredAt(): array
    {
        $rows = $this->container['db']->table(ManualTimeLog::TABLE)
            ->columns(ManualTimeLog::TABLE . '.entered_at', 'subtasks.task_id')
            ->join('subtasks', 'id', 'subtask_id')
            ->findAll();

        return array_map('intval', array_column($rows, 'entered_at', 'task_id'));
    }

    private function controller(): TimeReportController
    {
        return new class($this->container) extends TimeReportController {
            public function apply(array $v, int $userId): ?float { return $this->dateUndatedFromValues($v, $userId); }
        };
    }

    // ── The ledger ───────────────────────────────────────────────────────────

    public function testDatingGivesTheChosenTasksUndatedTimeADate(): void
    {
        $a = $this->manualTask(3.0);
        $b = $this->manualTask(1.5);
        $c = $this->manualTask(6.0);
        $when = strtotime('2026-10-05 12:00:00');

        $hours = (new ManualTimeLog($this->container))->dateUndated($this->projectId, [$a, $b], $when);

        $this->assertSame(4.5, $hours);
        $this->assertSame([$a => $when, $b => $when, $c => 0], $this->enteredAt());
    }

    public function testDatingLeavesAlreadyDatedTimeAlone(): void
    {
        $dated = strtotime('2026-09-01 10:00:00');
        $a = $this->manualTask(2.0, '2026-09-01 10:00:00');

        $hours = (new ManualTimeLog($this->container))->dateUndated($this->projectId, [$a], strtotime('2026-10-05 12:00:00'));

        $this->assertSame(0.0, $hours);
        $this->assertSame([$a => $dated], $this->enteredAt());
    }

    public function testDatingIgnoresTasksOfAnotherProject(): void
    {
        $other = $this->manualTask(2.0, '', 1, $this->project('Elsewhere'));

        $hours = (new ManualTimeLog($this->container))->dateUndated($this->projectId, [$other], strtotime('2026-10-05 12:00:00'));

        $this->assertSame(0.0, $hours);
        $this->assertSame([$other => 0], $this->enteredAt());
    }

    public function testDatingCanBeLimitedToOnePersonsSubtasks(): void
    {
        $bob  = $this->user('bob');
        $mine = $this->manualTask(1.0);
        $his  = $this->manualTask(2.0, '', $bob);
        $when = strtotime('2026-10-05 12:00:00');

        $hours = (new ManualTimeLog($this->container))->dateUndated($this->projectId, [$mine, $his], $when, $bob);

        $this->assertSame(2.0, $hours);
        $this->assertSame([$mine => 0, $his => $when], $this->enteredAt());
    }

    // ── The action ───────────────────────────────────────────────────────────

    public function testTheActionDatesTimeAtNoonOfTheChosenDayInAnyAcceptedFormat(): void
    {
        $a = $this->manualTask(3.0);

        $hours = $this->controller()->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $a], 'entered_date' => '10/05/2026'], 1);

        $this->assertSame(3.0, $hours);
        $this->assertSame([$a => strtotime('2026-10-05 12:00:00')], $this->enteredAt());
    }

    public function testTheConfirmationNamesTheDayInTheFieldFormat(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/is now dated %s.*?fieldDate\(/s', $src);
    }

    public function testTheActionRejectsAMissingDateOrNoTasks(): void
    {
        $a = $this->manualTask(3.0);
        $c = $this->controller();

        $this->assertNull($c->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $a], 'entered_date' => 'whenever'], 1));
        $this->assertNull($c->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $a]], 1));
        $this->assertNull($c->apply(['project_id' => $this->projectId, 'entered_date' => '2026-10-05'], 1));
        $this->assertSame([$a => 0], $this->enteredAt());
    }

    public function testTheActionRefusesAProjectTheUserCannotAccess(): void
    {
        $bob = $this->user('bob');
        $a   = $this->manualTask(3.0);
        $this->container['userSession']->initialize($this->container['userModel']->getById($bob));

        $this->expectException(AccessForbiddenException::class);
        $this->controller()->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $a], 'entered_date' => '2026-10-05'], $bob);
    }

    public function testAMemberCanOnlyDateTheirOwnTime(): void
    {
        $bob = $this->user('bob');
        $this->container['projectUserRoleModel']->addUser($this->projectId, $bob, 'project-member');
        $mine = $this->manualTask(1.0);
        $his  = $this->manualTask(2.0, '', $bob);
        $this->container['userSession']->initialize($this->container['userModel']->getById($bob));

        $hours = $this->controller()->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $mine, (string) $his], 'entered_date' => '2026-10-05'], $bob);

        $this->assertSame(2.0, $hours);
        $this->assertSame(0, $this->enteredAt()[$mine]);
    }

    public function testDatedTimeMovesFromTheNoticeIntoTheReportForThatDay(): void
    {
        $a = $this->manualTask(3.0);
        $model = new TimeReportModel($this->container);
        $before = $model->report($this->projectId, '2026-10-01', '2026-10-31', 'day', false, 1);
        $this->assertSame([0.0, 3.0], [$before['total_hours'], $before['untracked']['total_hours']]);

        $this->controller()->apply(['project_id' => $this->projectId, 'task_ids' => [(string) $a], 'entered_date' => '2026-10-05'], 1);

        $after = $model->report($this->projectId, '2026-10-01', '2026-10-31', 'day', false, 1);
        $this->assertSame(['2026-10-05'], array_column($after['breakdown'], 'key'));
        $this->assertSame([3.0, 0.0], [$after['manual_hours'], $after['untracked']['total_hours']]);
    }

    public function testTheActionIsPostOnlyCsrfCheckedAndReturnsToTheReport(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/function dateUndated\(\): void\s*\{\s*\$this->checkCSRFForm\(\);.*?reportUrl\(/s', $src);
        $this->assertStringContainsString("'timereport/date-undated', 'TimeReportController', 'dateUndated', 'TimeReport'", file_get_contents(dirname(__DIR__) . '/Plugin.php'));
    }

    // ── The page ─────────────────────────────────────────────────────────────

    private function show(array $untracked): string
    {
        return $this->container['template']->render('TimeReport:report/show', [
            'report' => [
                'project_id' => 5, 'project_name' => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
                'granularity' => 'day', 'total_hours' => 0.0, 'tracked_hours' => 0.0, 'manual_hours' => 0.0,
                'breakdown' => [['key' => '2026-03-02', 'label' => '2026-03-02', 'hours' => 1.0, 'tracked_hours' => 1.0, 'manual_hours' => 0.0, 'task_count' => 1, 'task_ids' => [7]]],
                'include_detail' => false, 'detail' => [], 'ai' => null, 'profile_id' => 'deep',
                'subject_user_ids' => [1], 'users' => [], 'participants' => [], 'multi_user' => false,
                'can_report_others' => true, 'scope_denied' => false, 'untracked' => $untracked,
            ],
            'markdown' => '', 'ai_enabled' => false, 'projects' => [5 => 'Acme'], 'profiles' => [],
            'can_report_others' => true, 'current_user_id' => 1,
        ]);
    }

    public function testUndatedTimeSitsBelowTheReportCollapsed(): void
    {
        $html = $this->show(['task_count' => 1, 'subtask_count' => 1, 'total_hours' => 2.0, 'tasks' => [
            ['task_id' => 42, 'reference' => 'R1', 'title' => 'Old work', 'hours' => 2.0],
        ]]);

        $panel = strpos($html, '<details class="tr-untracked"');
        $this->assertNotFalse($panel, 'collapsed by default: a <details> with no open attribute');
        $this->assertGreaterThan(strpos($html, 'tr-breakdown'), $panel, 'below the breakdown');
        $this->assertMatchesRegularExpression('#<summary[^>]*>.*?2\.00.*?</summary>#s', $html, 'the closed line still says how much');
    }

    public function testEachUndatedTaskCanBeTickedAndGivenADate(): void
    {
        $html = $this->show(['task_count' => 1, 'subtask_count' => 1, 'total_hours' => 2.0, 'tasks' => [
            ['task_id' => 42, 'reference' => 'R1', 'title' => 'Old work', 'hours' => 2.0],
        ]]);
        preg_match('#<details class="tr-untracked".*?</details>#s', $html, $m);
        $panel = $m[0] ?? '';

        $this->assertMatchesRegularExpression('#<form[^>]*method="post"[^>]*action="[^"]*dateUndated[^"]*"#i', $panel);
        $this->assertStringContainsString('name="csrf_token"', $panel);
        $this->assertStringContainsString('name="task_ids[]" value="42"', $panel);
        $this->assertMatchesRegularExpression('#name="entered_date"[^>]*class="[^"]*form-date#', $panel);
        $this->assertStringContainsString('name="project_id" value="5"', $panel);
        $this->assertStringContainsString('name="start_date" value="2026-03-01"', $panel, 'returns to the same report');
        $this->assertStringContainsString('name="profile_id" value="deep"', $panel, 'keeps the chosen AI profile');
        $this->assertStringContainsString('type="submit"', $panel);
    }

    public function testNoUndatedTimeNoPanel(): void
    {
        $this->assertStringNotContainsString('tr-untracked', $this->show(['task_count' => 0, 'subtask_count' => 0, 'total_hours' => 0.0, 'tasks' => []]));
    }
}
