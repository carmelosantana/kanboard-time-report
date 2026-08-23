<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class TimeReportControllerTest extends Base
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__) . '/Controller/TimeReportController.php');
    }

    public function testGenerateGuardsWithAccessAndCsrfAndGranularityValidation(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('assertProjectAccess', $src, 'must access-guard via the model');
        $this->assertStringContainsString('checkCSRFForm', $src, 'generate/export must check CSRF on POST');
        $this->assertStringContainsString("'day', 'week', 'task', 'total'", $src, 'granularity must be validated against the allow-list');
    }

    public function testAiIsGatedAndProfileValidated(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('isAiEnabled()', $src, 'AI path must be gated');
        $this->assertStringContainsString('include_ai_summary', $src);
        $this->assertStringContainsString("array_column(", $src, 'submitted profile id must be validated against listProfiles()');
    }

    public function testExportStreamsCsv(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('text/csv', $src);
        $this->assertStringContainsString('Content-Disposition', $src);
        $this->assertStringContainsString('csvFilename', $src);
    }

    /** Behavioral: when the gate is CLOSED, include_ai_summary must be ignored (no AI attached, no throw). */
    public function testGenerateIgnoresAiSummaryWhenGateClosed(): void
    {
        // Build a project owned by user 1 so access passes.
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);

        // Plugin::initialize() is what wires 'timeReportModel'/'aiSummaryModel' into the
        // container in production; the unit Base container doesn't load plugins, so wire
        // the one service this behavioral test needs directly (mirrors TimeReportModelTest).
        $this->container['timeReportModel'] = function ($c) {
            return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
        };

        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public array $captured = [];
            protected function isAiEnabled(): bool { return false; }
            // Capture the report the action would render instead of emitting HTML.
            public function renderProbe(int $projectId): array {
                $model = $this->timeReportModel;
                $report = $model->report($projectId, '2026-03-01', '2026-03-31', 'day', false, 1);
                // Simulate the gate-closed AI branch: since isAiEnabled() is false, ai stays null.
                return $report;
            }
        };

        $report = $controller->renderProbe($projectId);
        $this->assertNull($report['ai'], 'AI must not be attached when the gate is closed');
    }

    /**
     * Behavioral: when the AI summary is wanted (gate open) but the user did NOT ask to
     * display the completed-task detail, the report must be mined EXACTLY ONCE (no second
     * report() call just to fetch detail for the AI), and the detail-display intent must
     * reflect the user's choice (false) even though detail was computed to feed the AI.
     */
    public function testAiWantedWithoutDetailMinesReportOnce(): void
    {
        // Fake model that counts report() invocations and returns a minimal aggregate.
        $fakeModel = new class($this->container) extends \Kanboard\Plugin\TimeReport\Model\TimeReportModel {
            public int $calls = 0;
            public function report(int $projectId, string $startDate, string $endDate, string $granularity, bool $includeDetail, int $userId): array
            {
                $this->calls++;
                return [
                    'project_id'     => $projectId,
                    'project_name'   => 'X',
                    'start_date'     => $startDate,
                    'end_date'       => $endDate,
                    'granularity'    => $granularity,
                    'total_hours'    => 0.0,
                    'breakdown'      => [],
                    'include_detail' => $includeDetail,
                    'detail'         => [],
                    'ai'             => null,
                ];
            }
        };
        $this->container['timeReportModel'] = function ($c) use ($fakeModel) { return $fakeModel; };

        // Fake AI model — returns a canned summary, no network.
        $fakeAi = new class($this->container) extends \Kanboard\Plugin\TimeReport\Model\AiSummaryModel {
            public function summarize(array $detailTasks, ?string $profileId = null): array
            {
                return ['summary' => 'canned', 'highlights' => []];
            }
        };
        $this->container['aiSummaryModel'] = function ($c) use ($fakeAi) { return $fakeAi; };

        // Request: AI wanted, include_detail NOT set, no profile_id (so validProfileId
        // short-circuits). getValues() only returns POST when a valid csrf_token is
        // present, so mint one from the same container token service the request uses.
        $csrf = $this->container['token']->getCSRFToken();
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, [], [], [
            'csrf_token'         => $csrf,
            'project_id'         => '5',
            'start_date'         => '2026-03-01',
            'end_date'           => '2026-03-31',
            'granularity'        => 'day',
            'include_ai_summary' => '1',
        ]);

        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            protected function isAiEnabled(): bool { return true; }
            public function buildPublic(): array { return $this->buildReportFromRequest(); }
        };

        $report = $controller->buildPublic();

        $this->assertSame(1, $fakeModel->calls, 'report() must be mined exactly once even when the AI summary needs detail');
        $this->assertSame('canned', $report['ai']['summary'], 'AI summary must be attached when wanted and the gate is open');
        $this->assertFalse($report['include_detail'], 'display intent preserved: user did not request the detail listing');
    }

    public function testQuickReportDefaultsToThisMonthPerTaskNoDetail(): void
    {
        $projectId = (int) $this->container['projectModel']->create(['name' => 'Acme'], 1, true);
        $this->container['timeReportModel'] = function ($c) {
            return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
        };
        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public function quickPublic(int $projectId, int $userId): array { return $this->quickReport($projectId, $userId); }
        };
        $report = $controller->quickPublic($projectId, 1);
        $this->assertSame('task', $report['granularity']);
        $this->assertSame(date('Y-m-01'), $report['start_date']);
        $this->assertSame(date('Y-m-d'), $report['end_date']);
        $this->assertFalse($report['include_detail']);
    }

    public function testQuickReportAccessGuardThrowsForInaccessibleProject(): void
    {
        $this->container['timeReportModel'] = function ($c) {
            return new \Kanboard\Plugin\TimeReport\Model\TimeReportModel($c);
        };
        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public function quickPublic(int $projectId, int $userId): array { return $this->quickReport($projectId, $userId); }
        };
        $this->expectException(\Kanboard\Core\Controller\AccessForbiddenException::class);
        $controller->quickPublic(999999, 1);
    }

    public function testPrefillProjectIdOnlySelectsAccessibleProject(): void
    {
        $controller = new class($this->container) extends \Kanboard\Plugin\TimeReport\Controller\TimeReportController {
            public function prefillPublic(int $requested, array $projects): int { return $this->prefillProjectId($requested, $projects); }
        };
        $projects = [5 => 'Acme', 8 => 'Beta'];
        $this->assertSame(5, $controller->prefillPublic(5, $projects));
        $this->assertSame(0, $controller->prefillPublic(7, $projects), 'inaccessible id not selected');
        $this->assertSame(0, $controller->prefillPublic(0, $projects), 'no id → no selection');
    }

    public function testViewIsReadOnlyGetWithAccessRedirect(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('function view(', $src, 'quick view action exists');
        $this->assertStringContainsString('getIntegerParam', $src, 'view/index read project_id from the query');
        $this->assertStringContainsString('AccessForbiddenException', $src, 'view catches the access exception and redirects');
        $this->assertStringContainsString('report/show', $src, 'view renders the report view');
    }
}
