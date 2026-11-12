<?php

require_once 'tests/units/Base.php';
require_once __DIR__ . '/ProgressFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\TimeReport\Controller\TimeReportController;
use Kanboard\Plugin\TimeReport\Helper\TimeReportHelper;
use Kanboard\Plugin\TimeReport\Model\TimeReportModel;

/** The report's user flow: filters keep their state, ranges are easy to pick, dead ends explain themselves. */
class ReportFlowTest extends Base
{
    use ProgressFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgressSchema();
        $this->container['helper']->register('timeReport', TimeReportHelper::class);
        $this->container['timeReportModel'] = fn ($c) => new TimeReportModel($c);
    }

    private function controller(): TimeReportController
    {
        return new class($this->container) extends TimeReportController {
            public function selection(array $v): array { return $this->subjectSelection($v); }
            public function range(array $v): array { return $this->dateRange($v); }
            public function quick(int $projectId, int $userId): array { return $this->quickReport($projectId, $userId); }
            public function remember(int $userId, string $granularity): void { $this->rememberGranularity($userId, $granularity); }
            public function link(array $v): string { return $this->reportUrl($v); }
            public function projects(int $userId): array { return $this->accessibleProjects($userId); }
            public function anyOthers(int $userId): bool { return $this->canReportOnAnyProject($userId); }
        };
    }

    private function report(array $overrides = []): array
    {
        return $overrides + [
            'project_id' => 5, 'project_name' => 'Acme', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'granularity' => 'day', 'total_hours' => 0.0, 'tracked_hours' => 0.0, 'manual_hours' => 0.0,
            'breakdown' => [], 'include_detail' => false, 'detail' => [], 'ai' => null, 'profile_id' => '',
            'subject_user_ids' => [1], 'users' => [], 'participants' => [], 'multi_user' => false,
            'can_report_others' => true, 'scope_denied' => false,
            'untracked' => ['task_count' => 0, 'subtask_count' => 0, 'total_hours' => 0.0, 'tasks' => []],
        ];
    }

    private function show(array $report, array $profiles = []): string
    {
        return $this->container['template']->render('TimeReport:report/show', [
            'report' => $report, 'markdown' => '', 'ai_enabled' => $profiles !== [], 'projects' => [5 => 'Acme'],
            'profiles' => $profiles, 'can_report_others' => true, 'current_user_id' => 1,
        ]);
    }

    private function filtersForm(string $html): string
    {
        preg_match('#<form id="tr-filters".*?</form>#s', $html, $m);
        return $m[0] ?? '';
    }

    // ── Selections survive "Edit filters → Update" ───────────────────────────

    public function testAnExplicitScopeChoiceBeatsALeftoverUserSelection(): void
    {
        $c = $this->controller();
        $this->assertSame([null, true], $c->selection(['scope' => 'all', 'user_ids' => ['2', '3']]));
        $this->assertSame([null, false], $c->selection(['scope' => 'self', 'user_ids' => ['2', '3']]));
    }

    public function testAUserSelectionIsUsedWhenNoScopeOverridesIt(): void
    {
        $c = $this->controller();
        $this->assertSame([[2, 3], false], $c->selection(['user_ids' => ['2', '3']]));
        $this->assertSame([[2, 3], false], $c->selection(['scope' => 'selected', 'user_ids' => ['2', '3']]));
    }

    public function testFiltersFormKeepsAHandPickedUserSubset(): void
    {
        $people = [1 => ['name' => 'Me', 'hours' => 1.0], 2 => ['name' => 'Bo', 'hours' => 1.0], 3 => ['name' => 'Cy', 'hours' => 1.0]];
        $form = $this->filtersForm($this->show($this->report(['subject_user_ids' => [1, 2], 'multi_user' => true, 'participants' => $people])));

        $this->assertMatchesRegularExpression('#<option value="selected" selected="selected">#', $form);
        $this->assertStringContainsString('name="user_ids[]" value="1"', $form);
        $this->assertStringContainsString('name="user_ids[]" value="2"', $form);
        $this->assertStringNotContainsString('name="user_ids[]" value="3"', $form);
    }

    public function testFiltersFormOffersNoSelectedScopeForEveryoneOrJustMe(): void
    {
        $people = [1 => ['name' => 'Me', 'hours' => 1.0], 2 => ['name' => 'Bo', 'hours' => 1.0]];
        $all = $this->filtersForm($this->show($this->report(['subject_user_ids' => [1, 2], 'multi_user' => true, 'participants' => $people])));
        $me  = $this->filtersForm($this->show($this->report(['participants' => $people])));

        $this->assertMatchesRegularExpression('#<option value="all" selected="selected">#', $all);
        $this->assertMatchesRegularExpression('#<option value="self" selected="selected">#', $me);
        $this->assertStringNotContainsString('user_ids[]', $all . $me);
        $this->assertStringNotContainsString('value="selected"', $all . $me);
    }

    public function testFiltersFormKeepsTheChosenAiProfile(): void
    {
        $profiles = [['id' => 'fast', 'label' => 'Fast'], ['id' => 'deep', 'label' => 'Deep']];
        $form = $this->filtersForm($this->show($this->report(['profile_id' => 'deep']), $profiles));

        $this->assertMatchesRegularExpression('#<option value="deep" selected="selected">#', $form);
    }

    public function testUsersPanelKeepsTheAiSummaryAndProfile(): void
    {
        $people = [1 => ['name' => 'Me', 'hours' => 1.0], 2 => ['name' => 'Bo', 'hours' => 1.0]];
        $html = $this->container['template']->render('TimeReport:report/_users', [
            'report' => $this->report(['participants' => $people, 'profile_id' => 'deep', 'ai' => ['summary' => 's', 'highlights' => []]]),
        ]);

        $this->assertStringContainsString('name="include_ai_summary" value="1"', $html);
        $this->assertStringContainsString('name="profile_id" value="deep"', $html);
    }

    public function testControlBarOnlyClaimsAiWhenTheReportHasAnAiSummary(): void
    {
        $profiles = [['id' => 'fast', 'label' => 'Fast']];
        $this->assertStringNotContainsString('tr-controlbar-ai', $this->show($this->report(), $profiles));
        $this->assertStringContainsString('tr-controlbar-ai', $this->show($this->report(['ai' => ['summary' => 's', 'highlights' => []]]), $profiles));
    }

    // ── Ranges ───────────────────────────────────────────────────────────────

    public function testDatePresetsCoverWeeksAndMonthsAroundToday(): void
    {
        $presets = (new TimeReportHelper($this->container))->datePresets(strtotime('2026-11-12 15:00:00')); // a Thursday

        $this->assertSame(['2026-11-09', '2026-11-15'], [$presets['this_week']['start'], $presets['this_week']['end']]);
        $this->assertSame(['2026-11-02', '2026-11-08'], [$presets['last_week']['start'], $presets['last_week']['end']]);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$presets['this_month']['start'], $presets['this_month']['end']]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$presets['last_month']['start'], $presets['last_month']['end']]);
    }

    public function testBothFormsOfferThePresets(): void
    {
        $form = $this->container['template']->render('TimeReport:report/form', [
            'projects' => [5 => 'Acme'], 'ai_enabled' => false, 'profiles' => [], 'values' => ['start_date' => '2026-03-01', 'end_date' => '2026-03-31'],
            'can_report_others' => false, 'send_descriptions' => false,
        ]);

        foreach ([$form, $this->filtersForm($this->show($this->report()))] as $html) {
            $this->assertSame(4, preg_match_all('#<button type="button"[^>]*data-tr-preset[^>]*data-start="\d{4}-\d\d-\d\d"[^>]*data-end="\d{4}-\d\d-\d\d"#', $html));
        }
    }

    public function testPresetButtonsAreWiredInTheScript(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringContainsString('data-tr-preset', $js);
        $this->assertStringContainsString('start_date', $js);
    }

    public function testAReversedRangeIsSwappedRatherThanReturningNothing(): void
    {
        $this->assertSame(['2026-03-01', '2026-03-31'], $this->controller()->range(['start_date' => '2026-03-31', 'end_date' => '2026-03-01']));
        $this->assertSame(['2026-03-01', '2026-03-31'], $this->controller()->range(['start_date' => '2026-03-01', 'end_date' => '2026-03-31']));
    }

    // ── One-click report remembers the breakdown you last used ───────────────

    public function testQuickReportUsesTheLastBreakdownTheUserGenerated(): void
    {
        $projectId = $this->project('Acme');
        $c = $this->controller();
        $c->remember(1, 'day');

        $this->assertSame('day', $c->quick($projectId, 1)['granularity']);
    }

    public function testQuickReportIgnoresABreakdownThatIsNotValid(): void
    {
        $projectId = $this->project('Acme');
        $this->container['userMetadataModel']->save(1, ['timereport_granularity' => 'nonsense']);

        $this->assertSame('task', $this->controller()->quick($projectId, 1)['granularity']);
    }

    // ── Dead ends explain themselves ─────────────────────────────────────────

    public function testDeniedCsvExportTellsTheUserWhy(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/scope_denied.*?flash->failure.*?redirect/s', $src);
    }

    public function testUndatedNoticeExplainsItselfAndLinksEachTask(): void
    {
        $html = $this->container['template']->render('TimeReport:report/_untracked', ['report' => $this->report([
            'untracked' => ['task_count' => 1, 'subtask_count' => 1, 'total_hours' => 2.0, 'tasks' => [
                ['task_id' => 42, 'reference' => 'R1', 'title' => 'Old work', 'hours' => 2.0],
            ]],
        ])]);

        $this->assertStringContainsString('no entry date', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*task_id=42[^"]*"[^>]*>Old work</a>#', $html);
    }

    // ── A generated report has an address: refresh and bookmarks work ────────

    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    public function testReportLinkCarriesTheWholeRequest(): void
    {
        $q = $this->query($this->controller()->link([
            'project_id' => '5', 'start_date' => '2026-03-31', 'end_date' => '2026-03-01', 'granularity' => 'week',
            'include_detail' => '1', 'user_ids' => ['2', '3'], 'csrf_token' => 'secret', 'include_ai_summary' => '1',
        ]));

        $this->assertSame('5', $q['project_id']);
        $this->assertSame(['2026-03-01', '2026-03-31'], [$q['start_date'], $q['end_date']], 'normalized range');
        $this->assertSame('week', $q['granularity']);
        $this->assertSame('1', $q['include_detail']);
        $this->assertSame(['2', '3'], $q['user_ids']);
        $this->assertArrayNotHasKey('csrf_token', $q);
        $this->assertArrayNotHasKey('include_ai_summary', $q, 'a link must never spend AI credits when opened');
    }

    public function testReportLinkKeepsTheAllUsersIntentRatherThanIds(): void
    {
        $q = $this->query($this->controller()->link(['project_id' => '5', 'scope' => 'all', 'user_ids' => ['2']]));

        $this->assertSame('all', $q['scope']);
        $this->assertArrayNotHasKey('user_ids', $q);
        $this->assertArrayNotHasKey('include_detail', $q);
    }

    public function testGetReportsNeverRunTheAiSummary(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
        $this->assertMatchesRegularExpression('/\$wantsAi\s*=\s*\$this->request->isPost\(\)\s*&&/', $src);
    }

    // ── Project list is loaded once per page, in one query ───────────────────

    public function testProjectListCostDoesNotGrowWithTheNumberOfProjects(): void
    {
        $cost = function (): int {
            $c = $this->controller();
            $h = $this->container['db']->getStatementHandler();
            $before = $h->getNbQueries();
            $c->projects(1);
            $c->anyOthers(1);
            return $h->getNbQueries() - $before;
        };

        $this->container['userSession']->initialize($this->container['userModel']->getById(1));
        $this->project('A');
        $this->project('B');
        $few = $cost();
        foreach (['C', 'D', 'E', 'F', 'G'] as $name) {
            $this->project($name);
        }

        $this->assertSame($few, $cost());
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], array_values($this->controller()->projects(1)));
    }

    // ── Row-summary Copy button ──────────────────────────────────────────────

    public function testRowSummaryCopyButtonIsTranslatedAndOnlyConfirmsARealCopy(): void
    {
        $tpl = file_get_contents(dirname(__DIR__) . '/Template/report/_breakdown.php');
        $this->assertStringContainsString("data-copy=\"<?= t('Copy') ?>\"", $tpl);
        $this->assertStringContainsString("data-copied=\"<?= t('Copied') ?>\"", $tpl);

        $js = file_get_contents(dirname(__DIR__) . '/Assets/js/timereport.js');
        $this->assertStringNotContainsString('"Copied ✓"', $js, 'label must come from the translated attribute');
        $this->assertStringNotContainsString('.catch(function () {})', $js, 'a failed copy must not be swallowed and then confirmed');
        $this->assertMatchesRegularExpression('/function copySummaryPanel.*?TimeReportClipboard\.copy/s', $js, 'reuses the clipboard helper with its fallback');
    }
}
